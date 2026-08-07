<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Enums\Source;
use App\Jobs\IngestFeed;
use App\Models\Event;
use App\Models\Feed;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The click-out redirector is the only place a third-party URL becomes a
 * Location header the browser acts on, and click-outs are the only revenue
 * signal the site has. Both properties are pinned here.
 */
class ClickOutTest extends TestCase
{
    use RefreshDatabase;

    /** Not seed(): that name is taken by Laravel's own TestCase. */
    private function seedOffer(): Product
    {
        $feed = Feed::firstOrCreate(
            ['source' => Source::Awin, 'external_feed_id' => '18755', 'market' => Market::BeNl],
            [
                'label' => 'Test advertiser',
                'enabled' => true,
                'column_map' => ['url' => base_path('tests/Fixtures/awin-sample.csv')],
            ],
        );
        IngestFeed::dispatchSync($feed->id);

        return Product::query()->where('external_id', '1001')->firstOrFail();
    }

    #[Test]
    public function it_redirects_to_the_affiliate_url(): void
    {
        $product = $this->seedOffer();

        $this->get("/be-nl/go/{$product->id}")
            ->assertRedirect($product->affiliate_url)
            // 302, not 301: the destination is a tracking URL that changes, and
            // a cached permanent redirect would send tomorrow's clicks to a
            // dead link and lose the attribution.
            ->assertStatus(302);
    }

    #[Test]
    public function a_dangerous_scheme_is_refused_even_if_it_reaches_the_database(): void
    {
        $product = $this->seedOffer();

        // Ingestion rejects these, but this is the last line before the browser
        // and a future import path, migration or admin edit must not be able to
        // bypass it.
        foreach (['javascript:alert(1)', 'data:text/html,<script>x</script>', 'http://insecure.test/x'] as $bad) {
            $product->forceFill(['affiliate_url' => $bad])->save();

            $this->get("/be-nl/go/{$product->id}")->assertNotFound();
        }
    }

    #[Test]
    public function it_records_the_click_and_the_price_at_click_time(): void
    {
        $product = $this->seedOffer();

        $this->get("/be-nl/go/{$product->id}");

        $event = Event::query()->where('kind', 'click_out')->firstOrFail();

        $this->assertSame($product->id, $event->payload['product_id']);
        $this->assertSame($product->merchant_id, $event->payload['merchant_id']);
        // Feeds move; a conversion report is unreadable without knowing what
        // was actually on screen when the visitor clicked.
        $this->assertSame($product->price, $event->payload['price']);
    }

    #[Test]
    public function it_does_not_leak_the_search_terms_to_the_merchant(): void
    {
        $product = $this->seedOffer();

        $this->get("/be-nl/go/{$product->id}")
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    #[Test]
    public function an_offer_from_another_market_is_not_found(): void
    {
        $product = $this->seedOffer();

        $this->get("/es/go/{$product->id}")->assertNotFound();
    }

    #[Test]
    public function a_stale_offer_still_redirects(): void
    {
        $product = $this->seedOffer();
        $product->update(['status' => 'stale']);

        // A wishlist item or a published guide may still point here. A dead
        // link is worse than an out-of-date price.
        $this->get("/be-nl/go/{$product->id}")->assertStatus(302);
    }
}

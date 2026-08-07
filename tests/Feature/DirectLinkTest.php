<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\Source;
use App\Models\Event;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Amazon requires Associates links to be direct and unobscured, so its offers
 * bypass the /go/ redirector entirely. Exactly one outbound path per source,
 * enforced server-side rather than trusted to the view.
 */
class DirectLinkTest extends TestCase
{
    use RefreshDatabase;

    private function offer(Source $source, string $url = 'https://shop.test/p/1'): Product
    {
        $group = ProductGroup::firstOrCreate(
            ['market' => Market::BeNl, 'identity_key' => '4006381333931'],
            ['identity_kind' => 'ean', 'title' => 'Sony WH-1000XM5', 'slug' => 'sony-wh-1000xm5', 'image_url' => 'https://img.test/1.jpg', 'min_price' => 30000, 'in_stock' => true],
        );

        $merchant = Merchant::firstOrCreate(
            ['source' => $source, 'external_id' => $source->value],
            ['name' => $source->label()],
        );

        return Product::create([
            'source' => $source,
            'external_id' => $source->value.'-1',
            'market' => Market::BeNl,
            'merchant_id' => $merchant->id,
            'group_id' => $group->id,
            'title' => 'Sony WH-1000XM5',
            'affiliate_url' => $url,
            'price' => 30000,
            'availability' => Availability::InStock,
        ]);
    }

    #[Test]
    public function amazon_offers_link_straight_to_amazon(): void
    {
        $offer = $this->offer(Source::Amazon, 'https://www.amazon.nl/dp/B08XYZ?tag=brandcoves-21');

        // No redirector hop: the visitor's browser goes to Amazon directly.
        $this->assertSame('https://www.amazon.nl/dp/B08XYZ?tag=brandcoves-21', $offer->outboundUrl());
        $this->assertTrue($offer->source->requiresDirectLink());
    }

    #[Test]
    public function other_sources_keep_the_redirector(): void
    {
        foreach ([Source::Awin, Source::Bol] as $source) {
            $offer = $this->offer($source);

            $this->assertStringContainsString("/go/{$offer->id}", (string) $offer->outboundUrl());
        }
    }

    #[Test]
    public function the_redirector_refuses_a_direct_link_source(): void
    {
        $offer = $this->offer(Source::Amazon, 'https://www.amazon.nl/dp/B08XYZ');

        // A hand-built or cached /go/ URL must not quietly still work — that is
        // exactly how an unobscured-link requirement gets violated later.
        $this->get("/be-nl/go/{$offer->id}")->assertNotFound();
    }

    #[Test]
    public function an_unsafe_direct_url_yields_no_link_at_all(): void
    {
        $offer = $this->offer(Source::Amazon, 'javascript:alert(1)');

        // The redirector normally performs the scheme check. On the direct path
        // there is nothing between us and the browser, so returning null means
        // the view renders no link rather than a dangerous one.
        $this->assertNull($offer->outboundUrl());
    }

    #[Test]
    public function the_product_page_marks_direct_offers_and_hides_unsafe_ones(): void
    {
        $amazon = $this->offer(Source::Amazon, 'https://www.amazon.nl/dp/B08XYZ');
        $awin = $this->offer(Source::Awin);
        $group = $amazon->group;

        $offers = $this->get("/be-nl/p/{$group->id}/{$group->slug}")
            ->assertOk()
            ->viewData('page')['props']['offers'];

        $byId = collect($offers)->keyBy('id');

        $this->assertTrue($byId[$amazon->id]['direct']);
        $this->assertNotNull($byId[$amazon->id]['beacon']);
        // The disclaimer is a condition of being allowed to show the price.
        $this->assertTrue($byId[$amazon->id]['needsPriceTimestamp']);

        $this->assertFalse($byId[$awin->id]['direct']);
        $this->assertNull($byId[$awin->id]['beacon']);
    }

    #[Test]
    public function the_beacon_records_a_direct_click(): void
    {
        $offer = $this->offer(Source::Amazon, 'https://www.amazon.nl/dp/B08XYZ');

        // sendBeacon cannot set headers, so this route is CSRF-exempt.
        $this->postJson('/be-nl/track/click', ['offer' => $offer->id])
            ->assertNoContent();

        $event = Event::query()->where('kind', 'click_out')->firstOrFail();

        $this->assertSame($offer->id, $event->payload['product_id']);
        // Distinguishes beacon clicks from redirector ones: a beacon can be
        // blocked or dropped, a redirect cannot.
        $this->assertSame('beacon', $event->payload['via']);
    }

    #[Test]
    public function the_beacon_shrugs_off_an_unknown_offer(): void
    {
        // Fired as the page unloads; turning a stale id into an error would
        // produce noise nobody can act on.
        $this->postJson('/be-nl/track/click', ['offer' => 999999])->assertNoContent();

        $this->assertSame(0, Event::query()->count());
    }
}

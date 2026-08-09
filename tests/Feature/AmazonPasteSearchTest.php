<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Models\AmazonProduct;
use App\Models\Event;
use App\Models\ProductGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pasting an Amazon URL into the ordinary search box.
 *
 * One field, not two. Someone on an Amazon product page wondering who else sells
 * the thing pastes into whichever box is nearest, and adding a second input for
 * links would mean the paste only works for people who already knew about it.
 */
class AmazonPasteSearchTest extends TestCase
{
    use RefreshDatabase;

    private function search(string $q): TestResponse
    {
        return $this->get('/be-nl/search?q='.urlencode($q));
    }

    #[Test]
    public function a_pasted_url_searches_for_the_product_in_its_title(): void
    {
        $group = ProductGroup::factory()->create([
            'market' => Market::BeNl,
            'title' => 'Sony WH-1000XM5 Draadloze Koptelefoon',
            'brand' => 'Sony',
        ]);

        $this->search('https://www.amazon.nl/Sony-WH-1000XM5-Draadloze-Koptelefoon/dp/B09XS7JWHH/ref=sr_1_3')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pastedLink.asin', 'B09XS7JWHH')
                ->where('pastedLink.usable', true)
                ->where('q', 'Sony WH 1000XM5 Draadloze Koptelefoon')
                ->where('results.items.0.id', $group->id)
            );
    }

    #[Test]
    public function a_classified_asin_lands_on_the_product_page_we_hold(): void
    {
        /*
         * The answer the paste was actually asking for. `amazon_products`
         * .identity_key is the same key `product_groups` is unique on, so a
         * classified ASIN points straight at the group the other shops' offers
         * hang off.
         */
        $group = ProductGroup::factory()->create([
            'market' => Market::BeNl,
            'identity_key' => '5054484374411',
            'slug' => 'sony-wh-1000xm5',
        ]);

        AmazonProduct::create([
            'asin' => 'B09XS7JWHH',
            'classified_title' => 'Sony WH-1000XM5',
            'identity_key' => '5054484374411',
        ]);

        $this->search('https://www.amazon.nl/dp/B09XS7JWHH')
            ->assertRedirect('/be-nl/p/'.$group->id.'/sony-wh-1000xm5');
    }

    #[Test]
    public function a_group_in_another_market_is_not_where_we_land(): void
    {
        // Invariant 2. A group in another market is a different product with
        // different tax and shipping; landing someone on it shows them a price
        // they cannot pay.
        ProductGroup::factory()->create([
            'market' => Market::NlNl,
            'identity_key' => '5054484374411',
        ]);

        AmazonProduct::create([
            'asin' => 'B09XS7JWHH',
            'classified_title' => 'Sony WH-1000XM5',
            'identity_key' => '5054484374411',
        ]);

        $this->search('https://www.amazon.nl/dp/B09XS7JWHH')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('q', 'Sony WH-1000XM5'));
    }

    #[Test]
    public function a_bare_link_we_cannot_identify_says_so_instead_of_guessing(): void
    {
        // No title in the URL, no classified ASIN, and we will not fetch the
        // page. An honest "we could not read that" beats a page of results
        // built from routing tokens.
        $this->search('https://www.amazon.nl/dp/B09XS7JWHH')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pastedLink.asin', 'B09XS7JWHH')
                ->where('pastedLink.usable', false)
                ->where('q', '')
            );
    }

    #[Test]
    public function a_shortlink_is_reported_rather_than_followed(): void
    {
        /*
         * Never fetched: a visitor-supplied URL the server requests is SSRF with
         * a search box in front of it, and it would put a third party's latency
         * inside our request handler.
         */
        $this->search('https://amzn.to/3xYzAbC')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('pastedLink.shortlink', true));
    }

    #[Test]
    public function an_ordinary_search_is_untouched(): void
    {
        // The parser sits in front of every search, so a false positive would
        // hijack a real query.
        $this->search('draadloze koptelefoon')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pastedLink', null)
                ->where('q', 'draadloze koptelefoon')
            );
    }

    #[Test]
    public function the_paste_is_recorded_without_the_url(): void
    {
        /*
         * A pasted Amazon link carries `ref=` breadcrumbs and occasionally a
         * session identifier. The ASIN and the outcome are the useful part and
         * the only part that belongs in a table with a 90-day life.
         */
        $this->search('https://www.amazon.nl/Sony-Koptelefoon-Zwart/dp/B09XS7JWHH/ref=sr_1_3?pd_rd_w=secret');

        $event = Event::query()->where('kind', 'amazon_paste')->firstOrFail();
        $payload = json_encode($event->payload);

        $this->assertStringContainsString('B09XS7JWHH', $payload);
        $this->assertStringNotContainsString('secret', $payload);
        $this->assertStringNotContainsString('amazon.nl', $payload);
    }
}

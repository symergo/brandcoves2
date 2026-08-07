<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Enums\Source;
use App\Jobs\GroupProducts;
use App\Jobs\IngestFeed;
use App\Models\Feed;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\SearchLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Search operates on physical products, not offers. A shopper must see one card
 * per product with every shop's price beneath it — not eleven near-identical
 * cards, which is what a search engine pointed at a feed produces.
 */
class SearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $feed = Feed::firstOrCreate(
            ['source' => Source::Awin, 'external_feed_id' => '18755', 'market' => Market::BeNl],
            [
                'label' => 'Test advertiser',
                'enabled' => true,
                'column_map' => ['url' => base_path('tests/Fixtures/awin-sample.csv')],
            ],
        );

        IngestFeed::dispatchSync($feed->id);
        GroupProducts::dispatchSync(Market::BeNl);
    }

    private function search(array $params = []): TestResponse
    {
        return $this->get('/be-nl/search?'.http_build_query($params));
    }

    #[Test]
    public function it_returns_one_card_per_product_not_per_offer(): void
    {
        // Two shops sell the Sony at different prices. That must be ONE result
        // showing two offers, not two results.
        $this->search(['q' => 'koptelefoon'])
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('results.total', 1)
                ->where('results.items.0.merchantCount', 2)
                ->where('results.items.0.offerCount', 2)
                // Cheapest across shops, in cents.
                ->where('results.items.0.minPrice', 32999)
            );
    }

    #[Test]
    public function full_text_search_is_stemmed_per_market(): void
    {
        // Dutch stemming: the catalogue says "Koptelefoon", the shopper may
        // type either form.
        $this->search(['q' => 'koptelefoons'])
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('results.total', 1));
    }

    #[Test]
    public function a_typo_still_finds_the_product(): void
    {
        // The reason the trigram index is queried with `<%` and not `%`:
        // similarity() scores this below the default threshold and finds
        // nothing at all.
        $this->search(['q' => 'koptelefon'])
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('results.total', 1));
    }

    #[Test]
    public function a_barcode_is_treated_as_an_exact_identity(): void
    {
        // Scanned or pasted. Not a text query — an exact unique-index hit,
        // which is what makes the barcode scanner nearly free.
        $this->search(['q' => '4006381333931'])
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('results.total', 1)
                ->where('results.items.0.merchantCount', 2)
            );
    }

    #[Test]
    public function it_can_show_only_products_available_from_several_shops(): void
    {
        $all = $this->search(['q' => ''])->viewData('page')['props']['results']['total'];
        $comparable = $this->search(['comparable' => '1'])->viewData('page')['props']['results']['total'];

        $this->assertGreaterThan($comparable, $all, 'the filter must actually narrow the set');
        $this->assertSame(3, $comparable, 'Sony, Philips and Nedis are each sold by two shops');
    }

    #[Test]
    public function cheapest_first_sorts_on_the_lowest_offer(): void
    {
        $response = $this->search(['sort' => 'price_asc'])->assertOk();
        $prices = array_column($response->viewData('page')['props']['results']['items'], 'minPrice');

        $sorted = $prices;
        sort($sorted);
        $this->assertSame($sorted, $prices);
    }

    #[Test]
    public function out_of_stock_products_are_hidden_by_default(): void
    {
        // An unbuyable price is not an offer.
        ProductGroup::query()->update(['in_stock' => false]);

        $this->search(['q' => 'koptelefoon'])
            ->assertInertia(fn ($page) => $page->where('results.total', 0));

        $this->search(['q' => 'koptelefoon', 'in_stock' => '0'])
            ->assertInertia(fn ($page) => $page->where('results.total', 1));
    }

    #[Test]
    public function an_empty_result_distinguishes_filters_from_a_bad_term(): void
    {
        // Two very different messages: "try another word" vs "remove a filter".
        $this->search(['q' => 'zzzznotathing'])
            ->assertInertia(fn ($page) => $page->where('emptyBecauseOfFilters', false));

        $this->search(['q' => 'koptelefoon', 'min' => '99999'])
            ->assertInertia(fn ($page) => $page->where('emptyBecauseOfFilters', true));
    }

    #[Test]
    public function facets_do_not_erase_their_own_options(): void
    {
        // Selecting a brand must not make every other brand vanish from the
        // list, or the filter panel becomes a one-way door.
        $response = $this->search(['brand' => ['Sony']])->assertOk();
        $brands = array_column($response->viewData('page')['props']['facets']['brands'], 'value');

        $this->assertContains('Sony', $brands);
        $this->assertContains('Philips', $brands, 'other brands must remain selectable');
    }

    #[Test]
    public function every_search_is_logged_for_the_guide_builder(): void
    {
        $this->search(['q' => 'koptelefoon']);

        $this->assertDatabaseHas('search_log', ['query' => 'koptelefoon', 'market' => 'be-nl']);
    }

    #[Test]
    public function a_zero_result_search_is_logged_as_a_content_gap(): void
    {
        // The most valuable rows in the table: real demand we cannot serve.
        $this->search(['q' => 'espressomachine']);

        $row = SearchLog::query()->where('query', 'espressomachine')->first();

        $this->assertNotNull($row);
        $this->assertSame(0, $row->result_count);
        $this->assertSame(1, $row->zero_result_count);
    }

    #[Test]
    public function repeat_searches_within_an_hour_are_one_row(): void
    {
        $this->search(['q' => 'koptelefoon']);
        $this->search(['q' => 'Koptelefoon']);
        $this->search(['q' => '  koptelefoon  ']);

        // Normalised and bucketed by hour, otherwise the volume that justifies
        // a buying guide is split across near-identical rows.
        $this->assertSame(1, SearchLog::query()->where('query', 'koptelefoon')->count());
        $this->assertSame(3, SearchLog::query()->where('query', 'koptelefoon')->value('search_count'));
    }

    #[Test]
    public function the_product_page_lists_every_shop_cheapest_first(): void
    {
        $group = ProductGroup::query()->where('identity_key', '4006381333931')->firstOrFail();

        $this->get("/be-nl/p/{$group->id}/{$group->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('offers', 2)
                ->where('offers.0.price', 32999)
                ->where('offers.1.price', 34900)
                ->where('product.merchantCount', 2)
                // Only shown when it is a real barcode.
                ->where('product.ean', '4006381333931')
            );
    }

    #[Test]
    public function a_stale_slug_redirects_instead_of_404ing(): void
    {
        $group = ProductGroup::query()->firstOrFail();

        // Old shared and indexed links must keep working after a retitle.
        $this->get("/be-nl/p/{$group->id}/an-old-slug")
            ->assertRedirect("/be-nl/p/{$group->id}/{$group->slug}");
    }

    #[Test]
    public function a_product_from_another_market_is_not_found(): void
    {
        $group = ProductGroup::query()->firstOrFail();

        // Market scoping is the rule the whole schema is built around: a be-nl
        // price must never surface under /es.
        $this->get("/es/p/{$group->id}/{$group->slug}")->assertNotFound();
    }
}

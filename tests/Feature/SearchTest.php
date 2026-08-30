<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Jobs\GroupProducts;
use App\Jobs\IngestFeed;
use App\Models\Feed;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\SearchLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /**
     * The threshold reaches Postgres, and search behaves as if it had.
     *
     * `<%` compares against `pg_trgm.word_similarity_threshold` and nothing the
     * query can say. That threshold used to be spelled as an extra
     * `word_similarity(?, title) >= 0.45` clause, which no index could answer and
     * which cost a sequential scan of product_groups five times per search page —
     * measured at 13-21s on staging. Removing it moved the number onto the
     * session, so if AppServiceProvider ever stops setting it, search silently
     * narrows to Postgres' 0.6 default and nothing else fails.
     *
     * "kopltelefon" is the guard: it scores 0.500 against "Sony WH-1000XM5
     * Draadloze Koptelefoon", so it matches at 0.45 and does not at 0.6. It is
     * also not a word any dictionary stems, so full text cannot quietly cover for
     * the trigram branch and make this pass for the wrong reason. The existing
     * typo above scores 0.818 and would pass either way.
     */
    #[Test]
    public function a_typo_below_the_postgres_default_threshold_still_finds_the_product(): void
    {
        $this->assertSame(
            (float) config('giftcoves.search.trigram_threshold'),
            (float) DB::scalar('SHOW pg_trgm.word_similarity_threshold'),
            'The trigram threshold is not reaching the Postgres session.',
        );

        $this->search(['q' => 'kopltelefon'])
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('results.total', 1));
    }

    /**
     * Facets are cached, and the key is narrow enough to be honest.
     *
     * They are computed from market, term and in-stock only — ignoring the active
     * filters, so the sidebar cannot erase its own options — which is exactly
     * what makes every filter, sort and page variant of one term share a result
     * worth caching. The risk that buys is a key too coarse to tell two searches
     * apart, which would serve one term's sidebar under another's.
     */
    #[Test]
    public function two_terms_do_not_share_a_facet_cache_entry(): void
    {
        $headphones = $this->search(['q' => 'koptelefoon'])
            ->assertOk()
            ->viewData('page')['props']['facets'];

        $earbuds = $this->search(['q' => 'oordopjes'])
            ->assertOk()
            ->viewData('page')['props']['facets'];

        $this->assertSame(['Sony'], array_column($headphones['brands'], 'value'));
        $this->assertSame(['Nedis'], array_column($earbuds['brands'], 'value'));
    }

    #[Test]
    public function a_word_only_in_the_description_finds_the_product(): void
    {
        /*
         * "bridge" appears in the fixture's description of the Philips Hue
         * starter kit and in no title anywhere. Before descriptions entered the
         * search vector this query returned nothing at all — every fact a
         * merchant stated about a product but left out of the title was
         * unfindable.
         */
        $this->search(['q' => 'bridge'])
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('results.total', 1)
                ->where('results.items.0.brand', 'Philips')
            );
    }

    #[Test]
    public function a_title_match_outranks_a_description_match(): void
    {
        /*
         * The whole reason description sits at weight D.
         *
         * Two different products, one word: the dishwasher has it in its title,
         * the detergent only in its description. Both are legitimate results and
         * the order between them is the entire point — a description mention is
         * weak evidence, so it belongs behind a title that leads with the word.
         *
         * Worth knowing WHY this passes, because it is not the tsvector weights
         * doing it. Ranking never reads them: orderByRelevance() sorts on
         * word_similarity() against the group title, and a product matched only
         * through its description scores near zero there. The weights and the
         * sort happen to agree, which is lucky rather than designed — if the
         * sort ever moves to ts_rank(), this test is what proves the ordering
         * survived the move.
         */
        $this->describedProduct('dishwasher', 'Bosch Serie 6 Vaatwasser', 'Zuinig en stil apparaat.');
        $this->describedProduct('detergent', 'Bosch Finish Reinigingstabletten', 'Geschikt voor elke vaatwasser.');

        $items = $this->search(['q' => 'vaatwasser'])
            ->assertOk()
            ->viewData('page')['props']['results']['items'];

        $this->assertSame(
            ['Bosch Serie 6 Vaatwasser', 'Bosch Finish Reinigingstabletten'],
            array_column($items, 'title'),
        );
    }

    #[Test]
    public function only_the_first_2000_characters_of_a_description_are_indexed(): void
    {
        /*
         * The cap is real, not decorative. It matches the one BolConnector
         * already applies at its own boundary, so a single verbose Awin
         * advertiser cannot contribute ten times more index than bol does for
         * the same product.
         *
         * The marker word sits past character 2000 in one description and at the
         * front of the other, so the pair fails in opposite directions if the
         * truncation is ever dropped or applied too aggressively.
         */
        $this->describedProduct(
            'buried',
            'Tefal Koekenpan 24 cm',
            str_repeat('vulling ', 300).'kwartelei',
        );
        $this->describedProduct('upfront', 'Tefal Steelpan 16 cm', 'kwartelei en meer.');

        $items = $this->search(['q' => 'kwartelei'])
            ->assertOk()
            ->viewData('page')['props']['results']['items'];

        $this->assertSame(['Tefal Steelpan 16 cm'], array_column($items, 'title'));
    }

    /**
     * One product, one offer, its own group — built directly rather than through
     * the fixture feed so that adding a case here cannot shift the counts the
     * other tests in this file assert on.
     */
    private function describedProduct(string $key, string $title, string $description): void
    {
        $merchant = Merchant::firstOrCreate(
            ['source' => Source::Awin->value, 'external_id' => 'descshop'],
            ['name' => 'Descshop BE'],
        );

        $group = ProductGroup::create([
            'market' => Market::BeNl->value,
            'identity_key' => "desc-{$key}",
            'identity_kind' => 'title',
            'title' => $title,
            'slug' => "desc-{$key}",
            'brand' => 'Testmerk',
            'category' => 'Keuken',
            // Not decoration: storedQuery() requires an image, on the grounds
            // that a card without one is not worth showing.
            'image_url' => "https://example.test/desc-{$key}.jpg",
            'min_price' => 4900,
            'max_price' => 4900,
            'offer_count' => 1,
            'merchant_count' => 1,
            'in_stock' => true,
        ]);

        Product::create([
            'source' => Source::Awin->value,
            'external_id' => "desc-{$key}",
            'market' => Market::BeNl->value,
            'merchant_id' => $merchant->id,
            'group_id' => $group->id,
            'title' => $title,
            'description' => $description,
            'brand' => 'Testmerk',
            'merchant_category' => 'Keuken',
            'price' => 4900,
            'affiliate_url' => "https://example.test/desc-{$key}",
            'availability' => Availability::InStock->value,
            'status' => ProductStatus::Active->value,
        ]);
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
    public function the_results_offer_their_own_vocabulary_as_further_searches(): void
    {
        /*
         * What sits above the grid now that the statistics are gone. Two
         * products sharing a word, because ResultTerms ignores anything
         * appearing once — and the fixture holds one product per phrase, which
         * is exactly the case that floor exists to reject.
         */
        $merchant = Merchant::firstOrCreate(
            ['source' => Source::Awin->value, 'external_id' => 'termshop'],
            ['name' => 'Termshop BE'],
        );

        foreach ([
            'Draadloze ruisonderdrukkende oordopjes',
            'Draadloze ruisonderdrukkende koptelefoon',
        ] as $i => $title) {
            $group = ProductGroup::create([
                'market' => Market::BeNl->value,
                'identity_key' => "terms-{$i}",
                'identity_kind' => 'title',
                'title' => $title,
                'slug' => "terms-{$i}",
                'brand' => 'Testmerk',
                'category' => 'Audio',
                'image_url' => "https://example.test/terms-{$i}.jpg",
                'min_price' => 4900,
                'max_price' => 4900,
                'offer_count' => 1,
                'merchant_count' => 1,
                'in_stock' => true,
            ]);

            Product::create([
                'source' => Source::Awin->value,
                'external_id' => "terms-{$i}",
                'market' => Market::BeNl->value,
                'merchant_id' => $merchant->id,
                'group_id' => $group->id,
                'title' => $title,
                'brand' => 'Testmerk',
                'merchant_category' => 'Audio',
                'price' => 4900,
                'affiliate_url' => "https://example.test/terms-{$i}",
                'availability' => Availability::InStock->value,
                'status' => ProductStatus::Active->value,
            ]);
        }

        $terms = array_column(
            $this->search(['q' => 'ruisonderdrukkende'])->assertOk()->viewData('page')['props']['terms'],
            'url',
            'term',
        );

        /*
         * The word is ADDED to the query, not swapped in for it.
         *
         * These used to carry the bare word — `?q=Draadloze` — on the reasoning
         * that the bare word is the whole category. That was wrong about what
         * the click means: somebody reading "Draadloze" under a page of
         * noise-cancelling results is refining, not restarting, and a fresh
         * search throws away the word they typed. Widening is what the search
         * box is for.
         */
        $this->assertSame(
            '/be-nl/search?q='.urlencode('ruisonderdrukkende Draadloze'),
            $terms['Draadloze'] ?? null,
        );

        // The query's own word is not offered back — the reader has just typed
        // it, and adding it again would change nothing.
        $this->assertArrayNotHasKey('ruisonderdrukkende', $terms);

        // Nothing on a filtered variant. It is noindex anyway, and one block of
        // links repeated across every facet combination is a doorway page.
        $this->assertSame([], $this->search(['q' => 'ruisonderdrukkende', 'brand' => ['Testmerk']])
            ->viewData('page')['props']['terms']);
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

    /**
     * Picking a shop in the by-store view leaves one lane, not five.
     *
     * The group filter deliberately keeps a product whose offer at ANY selected
     * shop matches — the shopper is asking "who has this at Coolblue", so the
     * Krefel price stays on the card as a comparison. The lane view then split
     * those same groups by merchant and gave the unselected shops columns of
     * their own, so deselecting Krefel left Krefel on screen.
     *
     * The Sony is the guard: both shops sell it, so a lane query that ignores
     * the filter cannot help but produce a Krefel lane.
     */
    #[Test]
    public function the_by_store_view_shows_only_the_shops_that_were_selected(): void
    {
        $coolblue = Merchant::query()->where('name', 'Coolblue')->firstOrFail();

        $lanes = $this->search(['view' => 'store', 'merchant' => [$coolblue->id]])
            ->assertOk()
            ->viewData('page')['props']['lanes'];

        $this->assertSame(['Coolblue'], array_column($lanes, 'shop'));
        $this->assertNotEmpty($lanes[0]['items']);
    }

    /**
     * Each lane carries its shop's mark.
     *
     * The lane payload used to be keyed by merchant name, which could not say
     * anything else about the shop. It is a list of records now precisely so
     * the column header can show a logo, and `faviconUrl()` derives one from
     * the merchant's domain when no `logo_url` was stored.
     */
    /**
     * A related-term pill narrows the search without changing the view.
     *
     * These links were `?q=` and nothing else, so clicking one from the
     * by-store view answered the narrower question back in the grid. Sort went
     * the same way. `withTerm()` already states the rule the whole page follows
     * — the visitor chose the sort and the view, and a pill is not them
     * changing their mind about either.
     */
    /**
     * Three products that share a word, so there is a related term at all.
     *
     * The shared fixture cannot produce one: `ResultTerms` drops any word
     * occurring once, and no search over that feed returns two groups with a
     * word in common outside the query itself. Built here rather than added to
     * the feed so the counts every other test in this file asserts on do not
     * move.
     */
    private function productsSharingAWord(): void
    {
        foreach (['hoofdtelefoon', 'oordopjes', 'speaker'] as $i => $kind) {
            $this->describedProduct(
                "gizmo-{$i}",
                "Gizmo ruisonderdrukkende {$kind}",
                'Ruisonderdrukkende audio.',
            );
        }
    }

    #[Test]
    public function a_related_term_keeps_the_view_and_the_sort(): void
    {
        $this->productsSharingAWord();

        $terms = $this->search(['q' => 'gizmo', 'view' => 'store', 'sort' => 'price_asc'])
            ->assertOk()
            ->viewData('page')['props']['terms'];

        $this->assertNotEmpty($terms, 'no related terms to assert on');

        foreach ($terms as $item) {
            parse_str((string) parse_url($item['url'], PHP_URL_QUERY), $params);

            $this->assertSame('store', $params['view'] ?? null);
            $this->assertSame('price_asc', $params['sort'] ?? null);
            $this->assertStringStartsWith('gizmo ', $params['q']);
        }
    }

    #[Test]
    public function a_related_term_carries_no_view_when_none_was_chosen(): void
    {
        $this->productsSharingAWord();

        $terms = $this->search(['q' => 'gizmo'])
            ->assertOk()
            ->viewData('page')['props']['terms'];

        $this->assertNotEmpty($terms);

        foreach ($terms as $item) {
            parse_str((string) parse_url($item['url'], PHP_URL_QUERY), $params);

            // The defaults stay out of the URL, so the commonest link is still
            // just `?q=`.
            $this->assertArrayNotHasKey('view', $params);
            $this->assertArrayNotHasKey('sort', $params);
        }
    }

    #[Test]
    public function each_lane_carries_the_shops_mark(): void
    {
        $lanes = $this->search(['view' => 'store'])
            ->assertOk()
            ->viewData('page')['props']['lanes'];

        foreach ($lanes as $lane) {
            $this->assertArrayHasKey('logo', $lane);
        }

        $logos = array_filter(array_column($lanes, 'logo'));
        $this->assertNotEmpty($logos, 'no lane offered a mark at all');

        // Never the affiliate network's icon: Awin's mark on every column
        // identifies nothing, on the view whose job is telling shops apart.
        foreach ($logos as $logo) {
            $this->assertStringNotContainsStringIgnoringCase('awin', $logo);
        }
    }

    #[Test]
    public function the_by_store_view_shows_every_shop_when_none_is_selected(): void
    {
        $lanes = $this->search(['view' => 'store'])
            ->assertOk()
            ->viewData('page')['props']['lanes'];

        // The narrowing above must be the filter doing its job, not the lane
        // query having quietly lost the other shop for some other reason.
        $shops = array_column($lanes, 'shop');
        sort($shops);
        $this->assertSame(['Coolblue', 'Krefel'], $shops);
    }
}

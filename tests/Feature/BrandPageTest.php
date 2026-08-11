<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\IdentityKind;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\PublishStatus;
use App\Enums\Source;
use App\Jobs\GroupProducts;
use App\Jobs\IngestFeed;
use App\Jobs\RefreshBrandStats;
use App\Models\BrandStat;
use App\Models\Feed;
use App\Models\Guide;
use App\Models\GuideItem;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Connectors\ConnectorRegistry;
use App\Services\Connectors\LiveConnector;
use App\Services\Connectors\Offer;
use App\Services\Seo\BrandCopy;
use App\Services\Seo\BrandLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Brand pages.
 *
 * The property worth testing is not "the page renders" but **the page never
 * asserts something the catalogue cannot back up**. A generated brand page that
 * says "Coolblue currently has discounts on Sony" when nothing is reduced is not
 * a cosmetic bug: it is the difference between a page a search engine trusts and
 * one that drags a domain down with it.
 *
 * The second property is that no brand link anywhere on the site can 404. A
 * brand below the three-product threshold has no page, and every place that
 * renders a brand has to know that.
 */
class BrandPageTest extends TestCase
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

    /**
     * Give a brand enough products to earn a page.
     *
     * Built by hand rather than by widening the fixture, because the threshold is
     * the thing under test and a fixture that happens to satisfy it tests
     * nothing.
     */
    private function seedBrand(string $brand, int $count = 4, array $overrides = [], int $offset = 0): void
    {
        // A group with no offers behind it is not searchable — `products` is what
        // carries the search vector — and `top_merchant_id` would stay null, so
        // the copy would fall back to its vaguer sentence. Seeding the offer is
        // what makes this resemble a real catalogue rather than a fixture that
        // happens to satisfy the assertions.
        $merchant = Merchant::firstOrCreate(
            ['source' => Source::Awin->value, 'external_id' => 'testshop'],
            ['name' => 'Testshop BE'],
        );

        for ($i = $offset; $i < $offset + $count; $i++) {
            $group = ProductGroup::create(array_merge([
                'market' => Market::BeNl->value,
                // Offset, so a brand can be seeded twice — once per category —
                // without the second call colliding on (market, identity_key).
                'identity_key' => "test-{$brand}-{$i}",
                'identity_kind' => 'title',
                'title' => "{$brand} draadloze koptelefoon model {$i}",
                'slug' => strtolower($brand)."-koptelefoon-{$i}",
                'brand' => $brand,
                'category' => 'Audio',
                // `presentable()` requires an image: a card with no picture is
                // not a card. Omitting it here silently emptied every result set.
                'image_url' => "https://example.test/{$i}.jpg",
                'min_price' => 9900 + ($i * 1000),
                'max_price' => 12900 + ($i * 1000),
                'median_price' => 14900,
                'offer_count' => 1,
                'merchant_count' => 2,
                'in_stock' => true,
            ], $overrides));

            Product::create([
                'source' => Source::Awin->value,
                'external_id' => "test-{$brand}-{$i}",
                'market' => Market::BeNl->value,
                'merchant_id' => $merchant->id,
                'group_id' => $group->id,
                'title' => $group->title,
                'brand' => $brand,
                'merchant_category' => 'Audio',
                'price' => $group->min_price,
                'affiliate_url' => "https://example.test/{$brand}/{$i}",
                'availability' => Availability::InStock->value,
                'status' => ProductStatus::Active->value,
            ]);
        }

        RefreshBrandStats::dispatchSync(Market::BeNl);
    }

    #[Test]
    public function a_brand_with_enough_products_gets_a_page(): void
    {
        $this->seedBrand('Aurex');

        $this->get('/be-nl/brand/aurex')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Brand')
                ->where('brand.name', 'Aurex')
                ->where('brand.productCount', 4)
                // The whole point: it is a search with the brand preselected.
                ->where('results.total', 4)
            );
    }

    #[Test]
    public function a_brand_with_too_few_products_has_no_page(): void
    {
        // Two products cannot support paragraphs about a brand, and publishing
        // thousands of pages that can't is the doorway-page pattern.
        $this->seedBrand('Tinybrand', count: 2);

        $this->get('/be-nl/brand/tinybrand')->assertNotFound();
    }

    /**
     * The brand page's prose, read off the page a visitor is served.
     *
     * The four tests below have moved twice, and the rule they pin has not
     * changed either time: **never state a number the catalogue cannot back up.**
     *
     * They first read `props['copy']`, the templated statistics that opened a
     * brand page. Those came off the top of the page on 2026-08-10 and the
     * assertions moved onto `BrandCopy`, the service behind them. `BrandCopy` is
     * now gone too — nothing rendered it, and a service no page calls is a
     * liability rather than coverage. The prose it used to write lives in
     * `PageNarrative::forBrand()`, below the grid.
     *
     * So they read the rendered `narrative` prop now, which is the strongest of
     * the three: it asserts against what a reader is actually shown rather than
     * against a service that may or may not be wired to a page.
     *
     * @return array{sections: list<array{heading: string, body: list<string>}>, faq: list<array{q: string, a: string}>, related: list<array{term: string, url: string}>}
     */
    private function brandProse(string $brand): array
    {
        $slug = BrandStat::query()->where('brand', $brand)->value('slug');

        $narrative = $this->get("/be-nl/brand/{$slug}")
            ->assertOk()
            ->viewData('page')['props']['narrative'] ?? null;

        $this->assertIsArray($narrative, "the brand page for {$brand} rendered no prose at all");

        return $narrative;
    }

    /** Every sentence on the page, as one string. */
    private function brandProseText(string $brand): string
    {
        $prose = $this->brandProse($brand);

        $lines = [];

        foreach ($prose['sections'] as $section) {
            $lines[] = $section['heading'];
            $lines = [...$lines, ...$section['body']];
        }

        foreach ($prose['faq'] as $entry) {
            $lines[] = $entry['q'];
            $lines[] = $entry['a'];
        }

        return implode(' ', $lines);
    }

    #[Test]
    public function the_copy_only_claims_a_discount_when_something_is_discounted(): void
    {
        // median 14900, min from 9900 → genuinely reduced.
        $this->seedBrand('Aurex');

        $this->assertStringContainsString('%', $this->brandProseText('Aurex'), 'expected a discount claim');

        // Now a brand whose median equals its minimum: nothing is reduced, so
        // the sentence must not appear at all.
        $this->seedBrand('Norvik', overrides: ['median_price' => 9900, 'min_price' => 9900]);

        $this->assertSame(0, BrandStat::query()->where('brand', 'Norvik')->first()->discounted_count);

        $this->assertStringNotContainsString(
            '%',
            $this->brandProseText('Norvik'),
            'claimed a discount with nothing reduced',
        );
    }

    #[Test]
    public function the_copy_says_what_the_brand_makes(): void
    {
        $this->seedBrand('Denon');

        // A second category, so the brand has a spread rather than one word.
        $this->seedBrand('Denon', 2, ['category' => 'Televisies'], 10);

        $stat = BrandStat::query()->where('brand', 'Denon')->firstOrFail();

        /*
         * `top_category` answered "mostly what?" and nothing else, so every
         * sentence a brand page could write about the brand itself came out as
         * one word — and the copy filled the gap with prices, medians and shop
         * counts. Someone who arrived wanting to know what Denon is got three
         * paragraphs about how we measure discounts.
         */
        $categories = array_column($stat->categories, 'category');

        $this->assertContains('Audio', $categories);
        $this->assertContains('Televisies', $categories);

        $prose = $this->brandProseText('Denon');

        // Named in the copy, not merely present in the stats row.
        $this->assertStringContainsString('Audio', $prose);
        $this->assertStringContainsString('Televisies', $prose);
    }

    #[Test]
    public function the_brand_is_described_before_the_price_is(): void
    {
        $this->seedBrand('Marantz');
        $this->seedBrand('Marantz', 2, ['category' => 'Versterkers'], 10);

        $sections = $this->brandProse('Marantz')['sections'];

        // The reader typed a brand name. The first section should answer "what
        // is this", not "how do we compute a discount".
        $this->assertNotSame([], $sections);
        $this->assertStringContainsString('Versterkers', implode(' ', $sections[0]['body']));
    }

    #[Test]
    public function a_brand_in_one_category_does_not_claim_a_range(): void
    {
        $this->seedBrand('Sennheiser');

        $sections = $this->brandProse('Sennheiser')['sections'];

        /*
         * The list joiner would render a single-item list as a bare word, which
         * reads as a truncated sentence — and claiming a spread from one
         * category would be the invented sentence this copy exists to avoid.
         *
         * Asserted on the sentence that names the categories rather than the
         * whole section, because the paragraphs around it have commas of their
         * own and always did.
         */
        $named = array_values(array_filter(
            $sections[0]['body'],
            fn (string $line) => str_contains($line, 'Audio'),
        ));

        $this->assertNotSame([], $named, 'the brand\'s one category is never named');
        $this->assertStringNotContainsString('Audio, ', $named[0]);
    }

    #[Test]
    public function the_page_opens_with_its_vocabulary_rather_than_with_statistics(): void
    {
        $this->seedBrand('Aurex');

        $props = $this->get('/be-nl/brand/aurex')->assertOk()->viewData('page')['props'];

        // The block of counts, ranges and medians is off the top of the page.
        $this->assertArrayNotHasKey('copy', $props);

        $terms = array_column($props['terms'], 'url', 'term');
        $this->assertNotSame([], $terms, 'expected words extracted from the product titles');

        /*
         * A search for the word, not a filter on this brand. The point of the
         * link is to leave: "koptelefoon" is every brand that makes one, which
         * is the comparison the site exists for, where "Aurex koptelefoon" is
         * the page already open.
         */
        $this->assertSame('/be-nl/search?q=koptelefoon', $terms['koptelefoon'] ?? null);

        // The brand's own name is excluded, or the page links to itself.
        $this->assertArrayNotHasKey('Aurex', $terms);
    }

    #[Test]
    public function the_page_is_indexable_and_its_filtered_variants_are_not(): void
    {
        $this->seedBrand('Aurex');

        /*
         * The reason this route exists at all: one canonical, indexable URL per
         * brand, where `?brand[]=Aurex` is noindex.
         *
         * Asserted against `noindex, follow` rather than `noindex`, because the
         * whole test environment carries a site-wide `noindex, nofollow` — the
         * staging guard. `follow` is what a page-level decision looks like, so it
         * is the only part of the string that distinguishes the two cases.
         */
        /*
         * Built from url(), not hard-coded. This asserted against
         * `http://localhost:8000` — the host in `.env.example` — while phpunit.xml
         * sets no APP_URL at all, so the test passed only on a machine whose
         * local `.env` happened to agree with it and failed everywhere else. The
         * canonical's *host* was never the property under test.
         */
        $canonical = 'rel="canonical" href="'.url('/be-nl/brand/aurex').'"';

        $bare = $this->get('/be-nl/brand/aurex')->assertOk();
        $bare->assertDontSee('content="noindex, follow"', escape: false);
        $bare->assertSee($canonical, escape: false);

        $this->get('/be-nl/brand/aurex?sort=price_asc')
            ->assertOk()
            ->assertSee('content="noindex, follow"', escape: false)
            // Canonical still names the bare page, so any signal a sorted
            // variant picks up consolidates rather than splitting.
            ->assertSee($canonical, escape: false);
    }

    #[Test]
    public function a_brand_query_parameter_cannot_override_the_brand_in_the_path(): void
    {
        // /brand/aurex?brand[]=Philips must not produce a page whose copy is about
        // Sony and whose results are Philips.
        $this->seedBrand('Aurex');
        $this->seedBrand('Norvik');

        $this->get('/be-nl/brand/aurex?'.http_build_query(['brand' => ['Norvik']]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('brand.name', 'Aurex')
                ->where('results.total', 4)
            );
    }

    #[Test]
    public function search_results_link_brands_that_have_a_page_and_not_those_that_do_not(): void
    {
        $this->seedBrand('Aurex');
        $this->seedBrand('Tinybrand', count: 2);

        $this->get('/be-nl/search?q=koptelefoon')
            ->assertOk()
            ->assertInertia(function ($page) {
                $links = $page->toArray()['props']['brandLinks'];

                $this->assertSame('/be-nl/brand/aurex', $links['aurex'] ?? null);
                $this->assertArrayNotHasKey('tinybrand', $links);
            });
    }

    #[Test]
    public function the_search_results_offer_their_vocabulary_as_links(): void
    {
        $this->seedBrand('Aurex');

        $this->get('/be-nl/search?q=koptelefoon')
            ->assertOk()
            ->assertInertia(function ($page) {
                $terms = array_column($page->toArray()['props']['terms'], 'url', 'term');
                $lowered = array_change_key_case($terms);

                // The vocabulary of the results, extracted rather than invented.
                $this->assertNotSame([], $terms);
                $this->assertArrayHasKey('draadloze', $lowered);

                // "koptelefoon" is the query, so it must NOT be echoed back as a
                // characteristic term of its own results.
                $this->assertArrayNotHasKey('koptelefoon', $lowered);

                // Every word is a link to a search for that word, which is what
                // makes this navigation rather than a sentence.
                foreach ($terms as $term => $url) {
                    $this->assertSame('/be-nl/search?q='.urlencode((string) $term), $url);
                }
            });
    }

    #[Test]
    public function the_term_links_are_absent_on_pages_that_are_noindex_anyway(): void
    {
        // Repeating one block of internal links across filtered and paginated
        // variants is the doorway-page pattern, and those pages are noindex
        // regardless.
        $this->seedBrand('Aurex');

        $this->get('/be-nl/search?q=koptelefoon&page=2')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('terms', []));
    }

    #[Test]
    public function a_brand_page_lists_coves_that_feature_the_brand(): void
    {
        $this->seedBrand('Aurex');

        $group = ProductGroup::query()->where('brand', 'Aurex')->firstOrFail();

        $featured = Guide::create([
            'market' => Market::BeNl->value,
            'slug' => 'de-beste-koptelefoons',
            'title' => 'De beste koptelefoons',
            'intro' => 'Wat te kiezen.',
            'status' => PublishStatus::Published->value,
            'published_at' => now(),
        ]);

        GuideItem::create([
            'guide_id' => $featured->id,
            'group_id' => $group->id,
            'rank' => 1,
        ]);

        // A second Cove that only *mentions* the brand in its prose. Both are
        // worth linking and they answer different questions.
        Guide::create([
            'market' => Market::BeNl->value,
            'slug' => 'stil-werken',
            'title' => 'Stil werken',
            'intro' => 'Over ruis.',
            'body_md' => 'Wij kijken naar [[brand:Aurex]] en anderen.',
            'status' => PublishStatus::Published->value,
            'published_at' => now(),
        ]);

        // And one that mentions nobody, to prove the filter filters.
        Guide::create([
            'market' => Market::BeNl->value,
            'slug' => 'iets-anders',
            'title' => 'Iets anders',
            'status' => PublishStatus::Published->value,
            'published_at' => now(),
        ]);

        $this->get('/be-nl/brand/aurex')
            ->assertOk()
            ->assertInertia(function ($page) {
                $titles = array_column($page->toArray()['props']['coves'], 'title');

                $this->assertContains('De beste koptelefoons', $titles);
                $this->assertContains('Stil werken', $titles);
                $this->assertNotContains('Iets anders', $titles);
            });
    }

    #[Test]
    public function the_brand_index_lists_only_pageworthy_brands(): void
    {
        $this->seedBrand('Aurex');
        $this->seedBrand('Tinybrand', count: 2);

        $this->get('/be-nl/brands')
            ->assertOk()
            ->assertInertia(function ($page) {
                $names = array_column($page->toArray()['props']['brands'], 'name');

                $this->assertContains('Aurex', $names);
                $this->assertNotContains('Tinybrand', $names);
            });
    }

    #[Test]
    public function the_sitemap_lists_brand_pages_and_only_ones_that_resolve(): void
    {
        $this->seedBrand('Aurex');
        $this->seedBrand('Tinybrand', count: 2);

        $this->get('/sitemap/be-nl/1.xml')
            ->assertOk()
            ->assertSee('/be-nl/brand/aurex', escape: false)
            ->assertDontSee('/be-nl/brand/tinybrand', escape: false);
    }

    #[Test]
    public function a_brand_that_leaves_the_catalogue_keeps_its_row_and_loses_its_page(): void
    {
        $this->seedBrand('Aurex');
        $this->get('/be-nl/brand/aurex')->assertOk();

        ProductGroup::query()->where('brand', 'Aurex')->delete();
        RefreshBrandStats::dispatchSync(Market::BeNl);

        // The row survives so anyone asking why a URL stopped working can find
        // out; the page 404s because the copy would have nothing to say.
        $this->assertSame(0, BrandStat::query()->where('brand', 'Aurex')->value('product_count'));
        $this->get('/be-nl/brand/aurex')->assertNotFound();
    }

    #[Test]
    public function two_feed_spellings_of_one_brand_share_a_page(): void
    {
        /*
         * The failure that actually happened on the real catalogue: an Awin feed
         * says "Audio-Technica", bol says "Audio Technica", and Str::slug folds
         * both to audio-technica. The first refresh died on the unique index —
         * which is the index doing its job.
         *
         * The right answer is one page showing both, not two half-pages: a reader
         * searching for a brand does not care about a hyphen, and a comparison
         * site that hides half the offers because two merchants punctuate
         * differently has failed at its one job.
         */
        $this->seedBrand('Audio-Technica', count: 5);
        $this->seedBrand('Audio Technica', count: 3);

        $stat = BrandStat::query()->where('slug', 'audio-technica')->first();

        $this->assertNotNull($stat, 'the two spellings did not fold into one row');
        // The most-stocked spelling becomes the display name.
        $this->assertSame('Audio-Technica', $stat->brand);
        $this->assertSame(8, $stat->product_count);
        $this->assertEqualsCanonicalizing(['Audio-Technica', 'Audio Technica'], $stat->brandSpellings());

        // And the page shows all eight, not five.
        $this->get('/be-nl/brand/audio-technica')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('results.total', 8));
    }

    #[Test]
    public function a_card_carrying_either_spelling_links_to_the_same_page(): void
    {
        $this->seedBrand('Audio-Technica', count: 5);
        $this->seedBrand('Audio Technica', count: 3);

        $links = app(BrandLinker::class)
            ->urls(['Audio-Technica', 'Audio Technica'], Market::BeNl);

        $this->assertSame('/be-nl/brand/audio-technica', $links['audio-technica'] ?? null);
        $this->assertSame('/be-nl/brand/audio-technica', $links['audio technica'] ?? null);
    }

    #[Test]
    public function slugs_survive_transliteration(): void
    {
        // The reason the slug is stored rather than computed in SQL: Str::slug
        // folds "Kärcher" to "karcher" and lower(replace(...)) does not, so a
        // recomputed slug and a stored one would disagree and every link 404.
        $this->seedBrand('Kärcher');

        $this->assertSame('karcher', BrandStat::query()->where('brand', 'Kärcher')->value('slug'));
        $this->get('/be-nl/brand/karcher')->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | The live sources
    |--------------------------------------------------------------------------
    |
    | A brand page used to show the stored index only, because the live half of
    | SearchService fires on a search term and a brand page has none — so bol,
    | which carries a great deal no Awin advertiser does, was invisible on the
    | one page dedicated to the brand.
    |
    | Two properties matter. The sources are asked the brand's *name*, because
    | none of them takes a brand filter; and what comes back is tied to the
    | brand before it is shown, because otherwise it lands with a null brand and
    | the page's own filter hides it.
    */

    /** bol, configured and answering. */
    private function fakeBol(array $products): void
    {
        config([
            'brandcoves.connectors.bol.enabled' => true,
            'brandcoves.connectors.bol.client_id' => 'test-id',
            'brandcoves.connectors.bol.client_secret' => 'test-secret',
        ]);

        Cache::flush();

        // The limiter talks to Redis directly, on purpose — its job is sharing
        // state across processes — so Cache::flush() does not reset it, and a
        // drained bucket makes a test silently issue no request at all.
        Redis::del('bc:ratelimit:bol:search', 'bc:ratelimit:bol:search:cooldown');

        Http::fake([
            'login.bol.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 300]),
            'api.bol.com/*' => Http::response(['results' => $products]),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function bolProduct(array $overrides = []): array
    {
        return array_merge([
            'bolProductId' => '9200000123456',
            // Deliberately an EAN the Awin fixture does not carry, so this
            // product is one only bol knows about — the case where the title
            // rule is the only evidence there is. The fixture's own
            // 4006381333931 is a Sony, and reusing it here would prove the
            // catalogue lookup instead.
            'ean' => '8712345000011',
            // No brand field anywhere: bol's catalogue API does not return one,
            // which is the entire reason BrandAttribution exists.
            'title' => 'Aurex draadloze koptelefoon XR9',
            'url' => 'https://www.bol.com/nl/p/aurex/9200000123456/',
            'image' => ['url' => 'https://media.bol.com/1.jpg'],
            'offer' => ['price' => 199.99],
        ], $overrides);
    }

    #[Test]
    public function the_live_sources_are_asked_for_the_brand_by_name(): void
    {
        $this->seedBrand('Aurex');
        $this->fakeBol([]);

        $this->get('/be-nl/brand/aurex')->assertOk();

        // The stored half of the page is an exact brand filter. The live half
        // cannot be: bol's catalogue API takes a search term and nothing else,
        // so the brand's name IS the query.
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/products/search')
            && $request['search-term'] === 'Aurex');
    }

    #[Test]
    public function a_typed_term_is_asked_alongside_the_brand(): void
    {
        $this->seedBrand('Aurex');
        $this->fakeBol([]);

        $this->get('/be-nl/brand/aurex?q=koptelefoon')->assertOk();

        // Not dropped and not used alone: "Aurex koptelefoon" is a better
        // question for bol than either word, and it is the one asked.
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/products/search')
            && $request['search-term'] === 'Aurex koptelefoon');
    }

    #[Test]
    public function page_two_asks_nobody(): void
    {
        $this->seedBrand('Aurex');
        $this->fakeBol([]);

        // Page two is noindex and is being read by someone who has already
        // scrolled past everything a live source could add. Asking again costs
        // a request per page per crawl, across thousands of brand pages.
        $this->get('/be-nl/brand/aurex?page=2')->assertOk();

        Http::assertNothingSent();
    }

    #[Test]
    public function a_bol_product_reaches_the_page_because_its_title_leads_with_the_brand(): void
    {
        $this->seedBrand('Aurex');
        $this->fakeBol([$this->bolProduct()]);

        $this->get('/be-nl/brand/aurex')
            ->assertOk()
            // Five, not four: bol's listing is a product no Awin advertiser
            // carries, and it appears because the brand was attributed to it.
            // Left null it would be stored, grouped, and then hidden by the
            // page's own `whereIn('brand', ...)` — a request paid for and
            // thrown away.
            ->assertInertia(fn ($page) => $page->where('results.total', 5));

        $this->assertSame('Aurex', Product::query()
            ->where('source', Source::Bol->value)
            ->value('brand'));
    }

    #[Test]
    public function an_accessory_for_the_brand_is_not_claimed_as_the_brand(): void
    {
        $this->seedBrand('Aurex');
        $this->fakeBol([$this->bolProduct([
            'bolProductId' => '9200000999999',
            'ean' => '8712345000028',
            // The shape a third-party accessory takes on a brand search, and the
            // reason attribution anchors at the *start* of the title rather than
            // matching anywhere in it.
            'title' => 'Hardcase hoesje voor Aurex XR9 koptelefoon',
        ])]);

        $this->get('/be-nl/brand/aurex')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('results.total', 4));

        $this->assertNull(Product::query()
            ->where('source', Source::Bol->value)
            ->value('brand'));
    }

    #[Test]
    public function the_brand_comes_from_the_catalogue_when_another_source_already_named_it(): void
    {
        $this->seedBrand('Aurex');

        // A feed row that carries both a barcode and a brand. This is the
        // evidence the lookup runs on, and it beats the title rule: the bol
        // listing below is worded category-first, which the title rule refuses.
        Product::query()->where('external_id', 'test-Aurex-0')->update([
            'ean' => '8712345000035',
            'identity_key' => '8712345000035',
            'identity_kind' => IdentityKind::Ean->value,
        ]);

        $this->fakeBol([$this->bolProduct([
            'ean' => '8712345000035',
            'title' => 'Draadloze koptelefoon XR9 van Aurex',
        ])]);

        $this->get('/be-nl/brand/aurex')->assertOk();

        // A lookup, not an inference: an EAN is a physical product, and a feed
        // has already said which brand that product is.
        $this->assertSame('Aurex', Product::query()
            ->where('source', Source::Bol->value)
            ->value('brand'));
    }

    #[Test]
    public function the_fold_runs_once_per_cache_window(): void
    {
        $this->seedBrand('Aurex');
        $this->fakeBol([$this->bolProduct()]);

        $this->get('/be-nl/brand/aurex')->assertOk();
        $this->get('/be-nl/brand/aurex')->assertOk();

        // One search request, not two. Folding is a write, brand pages are the
        // crawl target for the whole site, and the offers stay folded in the
        // database long after the marker expires — so the second request
        // renders the same page without paying for it.
        $searches = collect(Http::recorded())
            ->filter(fn (array $pair) => str_contains($pair[0]->url(), '/products/search'));

        $this->assertCount(1, $searches);

        $this->get('/be-nl/brand/aurex')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('results.total', 5));
    }

    #[Test]
    public function a_source_that_may_not_be_mirrored_is_shown_but_never_stored(): void
    {
        $this->seedBrand('Aurex');

        // Amazon has no connector yet. Registering a stand-in proves the rule it
        // will arrive under: Associates terms permit storing the decision and
        // require title, price, image and availability to be re-fetched at
        // render, so these offers must reach the page without reaching the
        // catalogue. See docs/features/amazon-compliance.md.
        app(ConnectorRegistry::class)->registerLive(new class implements LiveConnector
        {
            public function source(): Source
            {
                return Source::Amazon;
            }

            public function supports(Market $market): bool
            {
                return true;
            }

            public function isCoolingDown(): bool
            {
                return false;
            }

            public function search(string $query, Market $market, int $limit = 24): array
            {
                return [new Offer(
                    source: Source::Amazon,
                    externalId: 'B00TEST0001',
                    market: $market,
                    title: 'Aurex XR9 koptelefoon',
                    affiliateUrl: 'https://www.amazon.nl/dp/B00TEST0001',
                    price: 18900,
                    availability: Availability::InStock,
                )];
            }

            public function fetchById(string $externalId, Market $market): ?Offer
            {
                return null;
            }
        });

        $this->get('/be-nl/brand/aurex')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // Shown, in its own section: there is no group behind it, so it
                // cannot claim an offer count, a shop count or a discount.
                ->has('liveOffers', 1)
                ->where('liveOffers.0.title', 'Aurex XR9 koptelefoon')
                // The programme's own conditions, read off Source rather than
                // hard-coded into the page.
                ->where('liveOffers.0.needsPriceTimestamp', true)
                ->where('liveOffers.0.directLink', true)
                // And absent from the grid, because nothing wrote it down.
                ->where('results.total', 4)
            );

        $this->assertSame(0, Product::query()->where('source', Source::Amazon->value)->count());
    }
}

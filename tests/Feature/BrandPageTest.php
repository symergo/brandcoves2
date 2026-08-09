<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
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
use App\Services\Seo\BrandLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    private function seedBrand(string $brand, int $count = 4, array $overrides = []): void
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

        for ($i = 0; $i < $count; $i++) {
            $group = ProductGroup::create(array_merge([
                'market' => Market::BeNl->value,
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

    #[Test]
    public function the_copy_only_claims_a_discount_when_something_is_discounted(): void
    {
        // median 14900, min from 9900 → genuinely reduced.
        $this->seedBrand('Aurex');

        $discounted = $this->get('/be-nl/brand/aurex')->assertOk();
        $discounted->assertInertia(function ($page) {
            $paragraphs = implode(' ', $page->toArray()['props']['copy']['paragraphs']);
            $this->assertStringContainsString('%', $paragraphs, 'expected a discount claim');
        });

        // Now a brand whose median equals its minimum: nothing is reduced, so
        // the sentence must not appear at all.
        $this->seedBrand('Norvik', overrides: ['median_price' => 9900, 'min_price' => 9900]);

        $this->get('/be-nl/brand/norvik')
            ->assertOk()
            ->assertInertia(function ($page) {
                $stat = BrandStat::query()->where('brand', 'Norvik')->first();
                $this->assertSame(0, $stat->discounted_count);

                $paragraphs = implode(' ', $page->toArray()['props']['copy']['paragraphs']);
                $this->assertStringNotContainsString('%', $paragraphs, 'claimed a discount with nothing reduced');
            });
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
        $bare = $this->get('/be-nl/brand/aurex')->assertOk();
        $bare->assertDontSee('content="noindex, follow"', escape: false);
        $bare->assertSee('rel="canonical" href="http://localhost:8000/be-nl/brand/aurex"', escape: false);

        $this->get('/be-nl/brand/aurex?sort=price_asc')
            ->assertOk()
            ->assertSee('content="noindex, follow"', escape: false)
            // Canonical still names the bare page, so any signal a sorted
            // variant picks up consolidates rather than splitting.
            ->assertSee('rel="canonical" href="http://localhost:8000/be-nl/brand/aurex"', escape: false);
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
    public function the_search_intro_describes_the_results_with_facts(): void
    {
        $this->seedBrand('Aurex');

        $this->get('/be-nl/search?q=koptelefoon')
            ->assertOk()
            ->assertInertia(function ($page) {
                $intro = $page->toArray()['props']['intro'];

                $this->assertNotNull($intro);
                // The vocabulary of the results, extracted rather than invented.
                $this->assertNotEmpty($intro['terms']);
                // "koptelefoon" is the query, so it must NOT be echoed back as a
                // characteristic term of its own results.
                $this->assertNotContains('koptelefoon', array_map('mb_strtolower', $intro['terms']));
                // Brands arrive as links, not as a comma-separated string.
                $this->assertSame('/be-nl/brand/aurex', $intro['brands'][0]['url'] ?? null);
            });
    }

    #[Test]
    public function the_intro_is_absent_on_pages_that_are_noindex_anyway(): void
    {
        // Repeating the same prose across filtered and paginated variants is the
        // doorway-page pattern, and those pages are noindex regardless.
        $this->seedBrand('Aurex');

        $this->get('/be-nl/search?q=koptelefoon&page=2')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('intro', null));
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
}

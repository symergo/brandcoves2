<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Jobs\GroupProducts;
use App\Jobs\IngestFeed;
use App\Jobs\RefreshBrandStats;
use App\Models\Feed;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\SearchLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The long copy below a results grid.
 *
 * **The search page only, since 2026-08-16.** Brand pages carried the same
 * three sections and dropped them: the copy was arithmetic about the grid
 * immediately above it, written in sentences, identically on a thousand pages.
 * What replaced it is links to articles that mention the brand, covered by
 * `BrandPageTest`.
 *
 * Two opposing failure modes, and a test for each.
 *
 *  - **Too little.** A grid of cards is almost pure markup; without prose there
 *    is nothing for a search engine to decide the page is about, which is why
 *    comparison sites rank for their guides and not their listings.
 *  - **Too much of the wrong kind.** Padding a word count by repeating the query
 *    works for about a fortnight, and then a helpful-content update decides the
 *    domain is filler and takes the good pages down with it.
 *
 * So: enough words to read as a document, and every one of them either a fact
 * off this page or a true explanation of how the site works.
 */
class PageNarrativeTest extends TestCase
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

    private function seedBrand(string $brand, int $count = 5): void
    {
        $merchant = Merchant::firstOrCreate(
            ['source' => Source::Awin->value, 'external_id' => 'testshop'],
            ['name' => 'Testshop BE'],
        );

        for ($i = 0; $i < $count; $i++) {
            $group = ProductGroup::create([
                'market' => Market::BeNl->value,
                'identity_key' => "narr-{$brand}-{$i}",
                'identity_kind' => 'title',
                'title' => "{$brand} draadloze koptelefoon met ruisonderdrukking {$i}",
                'slug' => strtolower($brand)."-{$i}",
                'brand' => $brand,
                'category' => 'Audio',
                'image_url' => "https://example.test/{$i}.jpg",
                'min_price' => 9900 + ($i * 1000),
                'max_price' => 12900,
                'median_price' => 14900,
                'offer_count' => 2,
                'merchant_count' => 2,
                'in_stock' => true,
            ]);

            Product::create([
                'source' => Source::Awin->value,
                'external_id' => "narr-{$brand}-{$i}",
                'market' => Market::BeNl->value,
                'merchant_id' => $merchant->id,
                'group_id' => $group->id,
                'title' => $group->title,
                'brand' => $brand,
                'price' => $group->min_price,
                'affiliate_url' => "https://example.test/{$i}",
                'availability' => Availability::InStock->value,
                'status' => ProductStatus::Active->value,
            ]);
        }

        RefreshBrandStats::dispatchSync(Market::BeNl);
    }

    /** Words across every visible string of the narrative. */
    private function wordCount(array $narrative): int
    {
        $text = '';

        foreach ($narrative['sections'] as $section) {
            $text .= ' '.$section['heading'].' '.implode(' ', $section['body']);
        }

        foreach ($narrative['faq'] as $item) {
            $text .= ' '.$item['q'].' '.$item['a'];
        }

        return str_word_count(strip_tags($text), 0, 'àâäéèêëîïôöùûüçÀÂÄÉÈÊËÎÏÔÖÙÛÜÇñáíóúÁÍÓÚ0123456789€%');
    }

    #[Test]
    public function a_search_page_carries_enough_prose_to_be_a_document(): void
    {
        $this->seedBrand('Aurex');

        $this->get('/be-nl/search?q=koptelefoon')
            ->assertOk()
            ->assertInertia(function ($page) {
                $narrative = $page->toArray()['props']['narrative'];

                $this->assertNotNull($narrative);
                // Three hundred words is roughly the point at which a page reads
                // as written rather than generated. Not a magic number — a floor
                // that catches a section quietly returning nothing.
                $this->assertGreaterThan(300, $this->wordCount($narrative));
                $this->assertCount(3, $narrative['sections']);
                $this->assertNotEmpty($narrative['faq']);
            });
    }

    /**
     * The brand page has no narrative to carry any more.
     *
     * `a_brand_page_carries_enough_prose_too` used to sit here and asserted the
     * same 300-word floor against `/be-nl/brand/aurex`. The copy below a brand
     * page went on 2026-08-16, replaced by links to articles that mention the
     * brand — see `BrandController::coves()` and
     * docs/features/brand-pages.md. `PageNarrative::forBrand()` still exists and
     * nothing calls it, so the floor is asserted on the search page alone.
     */
    #[Test]
    public function every_placeholder_is_filled(): void
    {
        // An unfilled `:brand` renders literally, and a reader seeing ":brand"
        // learns more about our template engine than we would like. This is the
        // failure mode of a copy block with an optional placeholder in it.
        $this->seedBrand('Aurex');

        $this->get('/be-nl/search?q=koptelefoon')
            ->assertOk()
            ->assertInertia(function ($page) {
                $blob = json_encode($page->toArray()['props']['narrative']);

                foreach ([':term', ':brand', ':count', ':low', ':high', ':shop', ':category', ':percent', ':shops'] as $token) {
                    $this->assertStringNotContainsString($token, (string) $blob, "unfilled {$token}");
                }
            });
    }

    #[Test]
    public function the_narrative_is_absent_where_the_page_is_noindex(): void
    {
        /*
         * Several hundred words repeated across every filter, sort and page
         * variant of one query is the doorway-page pattern at scale — and those
         * variants are noindex anyway, so the copy would be pure weight.
         */
        $this->seedBrand('Aurex');

        foreach ([
            '/be-nl/search?q=koptelefoon&page=2',
            '/be-nl/search?q=koptelefoon&discounted=1',
        ] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertInertia(fn ($page) => $page->where('narrative', null));
        }
    }

    #[Test]
    public function the_faq_is_rendered_as_structured_data_and_as_text(): void
    {
        $this->seedBrand('Aurex');

        // Both halves are required. Structured data whose answer is not on the
        // page is a misrepresentation, and search engines have started treating
        // it as one.
        $response = $this->get('/be-nl/search?q=koptelefoon')->assertOk();

        $response->assertSee('"@type":"FAQPage"', escape: false);

        $response->assertInertia(function ($page) {
            $faq = $page->toArray()['props']['narrative']['faq'];

            $this->assertNotEmpty($faq);

            foreach ($faq as $item) {
                $this->assertNotSame('', trim($item['q']));
                $this->assertNotSame('', trim($item['a']));
            }
        });
    }

    #[Test]
    public function related_searches_come_from_the_log_and_exclude_the_query_itself(): void
    {
        $this->seedBrand('Aurex');

        foreach (['draadloze koptelefoon', 'gaming koptelefoon', 'koptelefoon'] as $query) {
            SearchLog::create([
                'query' => $query,
                'query_hash' => hash('sha256', $query.'be-nl'),
                'market' => Market::BeNl->value,
                'hour_bucket' => now()->startOfHour(),
                'search_count' => 12,
                'result_count' => 8,
            ]);
        }

        $this->get('/be-nl/search?q=koptelefoon')
            ->assertOk()
            ->assertInertia(function ($page) {
                $related = array_column($page->toArray()['props']['narrative']['related'], 'term');

                // word_similarity finds the neighbours without a taxonomy.
                $this->assertContains('draadloze koptelefoon', $related);
                // Linking a page to itself is not a related search.
                $this->assertNotContains('koptelefoon', $related);
            });
    }
}

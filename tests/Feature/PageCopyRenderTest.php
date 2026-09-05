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
use App\Models\PageBlock;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Pages\PageCopy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The page templates as a reader receives them.
 *
 * Replaces `PageNarrativeTest`. The properties are the ones that file held, and
 * they did not change when the mechanism did — which is the point of asserting
 * them here rather than against the service.
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
 * And one that is new: **there is no fallback now**, so "the copy is there"
 * stopped being something the framework guarantees and became something a test
 * has to.
 */
class PageCopyRenderTest extends TestCase
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
                'identity_key' => "copy-{$brand}-{$i}",
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
                'external_id' => "copy-{$brand}-{$i}",
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

    /** Every visible word of a narrative prop. */
    private function words(array $narrative): int
    {
        $text = '';

        foreach ($narrative['sections'] as $section) {
            $text .= ' '.$section['heading'];

            foreach ($section['body'] as $parts) {
                foreach ($parts as $part) {
                    $text .= ' '.($part['t'] === 'text'
                        ? $part['v']
                        : implode(' ', array_column($part['items'], 'label')));
                }
            }
        }

        return str_word_count(strip_tags($text), 0, 'àâäéèêëîïôöùûüçÀÂÄÉÈÊËÎÏÔÖÙÛÜÇñáíóúÁÍÓÚ0123456789€%');
    }

    private function props(string $url): array
    {
        return $this->get($url)->assertOk()->viewData('page')['props'];
    }

    /*
     * -----------------------------------------------------------------
     * Enough prose to be a document.
     * -----------------------------------------------------------------
     */

    #[Test]
    public function a_search_page_carries_enough_prose_to_be_a_document(): void
    {
        $this->seedBrand('Aurex');

        $narrative = $this->props('/be-nl/search?q=koptelefoon')['narrative'];

        $this->assertNotNull($narrative, 'the copy below the grid is missing');
        // Three hundred words is roughly where a page reads as written rather
        // than generated. Not a magic number — a floor that catches a section
        // quietly returning nothing, which is now the *only* way copy vanishes.
        $this->assertGreaterThan(300, $this->words($narrative));
        $this->assertGreaterThanOrEqual(3, count($narrative['sections']));
    }

    #[Test]
    public function a_brand_page_carries_enough_prose_too(): void
    {
        $this->seedBrand('Aurex');

        $narrative = $this->props('/be-nl/brand/aurex')['narrative'];

        $this->assertNotNull($narrative);
        $this->assertGreaterThan(300, $this->words($narrative));
        $this->assertGreaterThanOrEqual(3, count($narrative['sections']));
    }

    #[Test]
    public function every_placeholder_is_filled(): void
    {
        $this->seedBrand('Aurex');

        foreach (['/be-nl/search?q=koptelefoon', '/be-nl/brand/aurex'] as $url) {
            $narrative = $this->props($url)['narrative'];

            foreach ($narrative['sections'] as $section) {
                $this->assertStringNotContainsString(':', $section['heading'], "unfilled placeholder in a heading on {$url}");

                foreach ($section['body'] as $parts) {
                    foreach ($parts as $part) {
                        if ($part['t'] !== 'text') {
                            continue;
                        }

                        // A colon is legal punctuation, so this looks for the
                        // shape a placeholder leaves: a colon glued to a word.
                        $this->assertDoesNotMatchRegularExpression(
                            '/:[a-z][a-z0-9_]*/',
                            $part['v'],
                            "unfilled placeholder on {$url}: {$part['v']}",
                        );
                    }
                }
            }
        }
    }

    /**
     * A second language renders its own blocks.
     *
     * Asserted through the empty state, because the fixture catalogue only
     * exists in `be-nl` — so a French search finds nothing, and the region that
     * fires on a French page is the one for a dead end. It is the right thing to
     * assert anyway: under a no-fallback rule "this language has copy" is
     * exactly what can silently stop being true, and the empty state is where a
     * missing sentence would leave a reader with a blank box.
     */
    #[Test]
    public function a_second_language_renders_its_own_blocks(): void
    {
        $props = $this->props('/be-fr/search?q=casque');

        $this->assertNotNull($props['emptyCopy'], 'the French empty state has no copy');

        $encoded = (string) json_encode($props['emptyCopy'], JSON_UNESCAPED_UNICODE);

        // The seeded French hint, not the Dutch one and not a language key.
        $this->assertStringContainsString('recherche', $encoded);
        $this->assertStringNotContainsString('zoekterm', $encoded);
    }

    /*
     * -----------------------------------------------------------------
     * Which URLs may carry copy.
     * -----------------------------------------------------------------
     */

    #[Test]
    public function a_thin_search_page_carries_no_copy_at_all(): void
    {
        $this->seedBrand('Aurex');

        /*
         * A filtered or paginated variant is noindex anyway, and repeating
         * several hundred words across dozens of near-identical URLs is the
         * doorway-page pattern at scale. The rule covers the intro too — an
         * intro repeated the same way is the same pattern with fewer words.
         */
        foreach ([
            '/be-nl/search?q=koptelefoon&page=2',
            '/be-nl/search?q=koptelefoon&min=50',
            '/be-nl/search',
        ] as $url) {
            $props = $this->props($url);

            $this->assertNull($props['narrative'], "copy below the grid on {$url}");
            $this->assertNull($props['intro'], "an intro on {$url}");
        }
    }

    #[Test]
    public function a_thin_brand_page_carries_no_copy_at_all(): void
    {
        $this->seedBrand('Aurex');

        foreach ([
            '/be-nl/brand/aurex?page=2',
            '/be-nl/brand/aurex?sort=price_asc',
            '/be-nl/brand/aurex?q=draadloos',
        ] as $url) {
            $props = $this->props($url);

            $this->assertNull($props['narrative'], "copy below the grid on {$url}");
            $this->assertNull($props['intro'], "an intro on {$url}");
        }
    }

    /**
     * The empty state is the one region with the opposite guard.
     *
     * It renders *because* the page is empty, and on noindex variants too — it
     * is for the reader, and a dead end is exactly where a way out belongs.
     */
    #[Test]
    public function an_empty_search_page_carries_its_own_copy_and_nothing_else(): void
    {
        $this->seedBrand('Aurex');

        $props = $this->props('/be-nl/search?q=qwertyuiopasdfgh');

        $this->assertNotNull($props['emptyCopy'], 'a dead end with nothing on it');
        $this->assertNull($props['narrative']);
        $this->assertNull($props['intro']);
    }

    #[Test]
    public function a_page_with_results_carries_no_empty_state_copy(): void
    {
        $this->seedBrand('Aurex');

        $this->assertNull($this->props('/be-nl/search?q=koptelefoon')['emptyCopy']);
    }

    /*
     * -----------------------------------------------------------------
     * What an editor changes, a reader sees.
     * -----------------------------------------------------------------
     */

    #[Test]
    public function an_edited_block_reaches_the_page(): void
    {
        $this->seedBrand('Aurex');

        PageBlock::query()
            ->where('page', 'search')
            ->where('region', 'below_grid')
            ->where('language', 'nl')
            ->where('kind', PageBlock::PARAGRAPH)
            ->first()
            ->variants()
            ->first()
            ->update(['body' => 'Deze zin komt uit de blokkeneditor.']);

        PageCopy::flush();

        $this->assertStringContainsString(
            'Deze zin komt uit de blokkeneditor.',
            (string) json_encode($this->props('/be-nl/search?q=koptelefoon')['narrative']),
        );
    }

    /**
     * An editor can add a region that ships empty, and it appears.
     *
     * `above_grid` is the interesting one: it exists as a place and renders
     * nothing until somebody writes into it, which is the whole arrangement —
     * adding a *place* is a deploy, adding *text* is not.
     */
    #[Test]
    public function the_intro_is_empty_until_somebody_writes_it(): void
    {
        $this->seedBrand('Aurex');

        $this->assertNull($this->props('/be-nl/search?q=koptelefoon')['intro']);

        $block = PageBlock::create([
            'page' => 'search',
            'region' => 'above_grid',
            'language' => 'nl',
            'kind' => PageBlock::PARAGRAPH,
            'position' => 1,
            'conditions' => [],
            'enabled' => true,
        ]);
        $block->variants()->create(['body' => 'Alles over :term op één pagina.', 'weight' => 1, 'enabled' => true]);

        $intro = $this->props('/be-nl/search?q=koptelefoon')['intro'];

        $this->assertNotNull($intro);
        $this->assertSame('Alles over koptelefoon op één pagina.', $intro[0]['parts'][0]['v']);
    }

    /**
     * `FAQPage` structured data is gone from these two page types.
     *
     * Withdrawn deliberately: Google narrowed FAQ rich results to a handful of
     * authoritative domains in 2023, so emitting the same six templated
     * questions across thousands of near-identical URLs had stopped paying for
     * itself. The questions survive as ordinary headings with answers under
     * them, so a reader loses nothing — and a Cove still emits its own
     * hand-written FAQ, where the questions are genuinely per page.
     */
    #[Test]
    public function neither_page_emits_faq_structured_data(): void
    {
        $this->seedBrand('Aurex');

        foreach (['/be-nl/search?q=koptelefoon', '/be-nl/brand/aurex'] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertDontSee('"@type":"FAQPage"', escape: false);
        }
    }

    #[Test]
    public function the_questions_are_still_on_the_page_as_prose(): void
    {
        $this->seedBrand('Aurex');

        $encoded = (string) json_encode(
            $this->props('/be-nl/search?q=koptelefoon')['narrative'],
            JSON_UNESCAPED_UNICODE,
        );

        // Seeded from the retired FAQ slots, as a heading with its answer under
        // it. The markup went; the words did not.
        $this->assertStringContainsString('Waar kan ik koptelefoon kopen?', $encoded);
    }

    /*
     * -----------------------------------------------------------------
     * The copy rule.
     * -----------------------------------------------------------------
     */

    /**
     * A page with nothing reduced never says anything about a discount.
     *
     * The rule every generated sentence on this site obeys, and the one the
     * placeholder-availability check exists to enforce: a claim has to be
     * checkable against the grid immediately above it.
     */
    #[Test]
    public function a_page_with_no_discount_makes_no_discount_claim(): void
    {
        $merchant = Merchant::firstOrCreate(
            ['source' => Source::Awin->value, 'external_id' => 'flatshop'],
            ['name' => 'Flatshop BE'],
        );

        for ($i = 0; $i < 5; $i++) {
            $group = ProductGroup::create([
                'market' => Market::BeNl->value,
                'identity_key' => "flat-{$i}",
                'identity_kind' => 'title',
                'title' => "Vlakke draadloze koptelefoon {$i}",
                'slug' => "flat-{$i}",
                'brand' => 'Flatbrand',
                'category' => 'Audio',
                'image_url' => "https://example.test/f{$i}.jpg",
                // Median equals minimum: nothing here is reduced.
                'min_price' => 9900,
                'max_price' => 9900,
                'median_price' => 9900,
                'offer_count' => 1,
                'merchant_count' => 1,
                'in_stock' => true,
            ]);

            Product::create([
                'source' => Source::Awin->value,
                'external_id' => "flat-{$i}",
                'market' => Market::BeNl->value,
                'merchant_id' => $merchant->id,
                'group_id' => $group->id,
                'title' => $group->title,
                'brand' => 'Flatbrand',
                'price' => 9900,
                'affiliate_url' => "https://example.test/f{$i}",
                'availability' => Availability::InStock->value,
                'status' => ProductStatus::Active->value,
            ]);
        }

        $encoded = (string) json_encode(
            $this->props('/be-nl/search?q=vlakke')['narrative'],
            JSON_UNESCAPED_UNICODE,
        );

        $this->assertStringNotContainsString('korting op', $encoded);
        $this->assertStringNotContainsString('onder die mediaan', $encoded);
        $this->assertDoesNotMatchRegularExpression('/\b0 producten\b/', $encoded);
    }
}

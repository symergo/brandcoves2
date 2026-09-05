<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Models\BrandStat;
use App\Models\PageBlock;
use App\Models\PageBlockVariant;
use App\Models\ProductGroup;
use App\Services\Pages\Context\PageContext;
use App\Services\Pages\Context\SearchContext;
use App\Services\Pages\PageCopy;
use App\Services\Pages\Placeholders\Absence;
use App\Services\Pages\Placeholders\Level;
use App\Services\Pages\Placeholders\PlaceholderFunction;
use App\Services\Pages\Placeholders\PlaceholderRegistry;
use App\Services\Pages\Placeholders\Value;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Placeholder functions: the extension point.
 *
 * A placeholder is a registered class, not a key in an array the caller
 * assembled. That is what lets one of them run a database query, another build
 * links out of a service, and a third be added next year without touching a
 * block, the schema or the admin.
 *
 * The properties that make it safe rather than merely flexible:
 *
 *  1. **A function's output is data, never markup.** Editors type `:brand_links`
 *     and the renderer makes the anchors. There is no path from a textarea to an
 *     element, because an admin form that renders arbitrary markup is one stored
 *     `<script>` from being the worst hole in the site.
 *  2. **An unanswerable placeholder hides the sentence that names it**, by the
 *     same rule for every kind of value — an empty link list behaves exactly as
 *     a missing number does.
 *  3. **A function runs only when something will render it**, and at most once.
 *  4. **A new one works immediately**, in blocks written before it existed.
 */
class PlaceholderFunctionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        PageBlockVariant::query()->delete();
        PageBlock::query()->delete();
        PageCopy::flush();
    }

    protected function tearDown(): void
    {
        // The registry memoises, and a fake registered in one case would
        // otherwise leak into every case after it in the same process.
        PlaceholderRegistry::fake(null);

        parent::tearDown();
    }

    private function block(string $body, array $overrides = []): PageBlock
    {
        $block = PageBlock::create(array_merge([
            'page' => 'search',
            'region' => 'below_grid',
            'language' => 'nl',
            'kind' => PageBlock::PARAGRAPH,
            'position' => 1,
            'conditions' => [],
            'enabled' => true,
        ], $overrides));

        $block->variants()->create(['body' => $body, 'weight' => 1, 'enabled' => true]);

        return $block;
    }

    private function context(array $brands = [], string $term = 'koptelefoon'): SearchContext
    {
        $items = [];

        foreach ($brands as $i => $brand) {
            $items[] = new ProductGroup([
                'market' => Market::BeNl->value,
                'identity_key' => 'ph-'.$i,
                'identity_kind' => 'title',
                'title' => 'Draadloze koptelefoon met ruisonderdrukking '.$i,
                'slug' => 'ph-'.$i,
                'brand' => $brand,
                'min_price' => 1999,
                'merchant_count' => 1,
            ]);
        }

        return new SearchContext(Market::BeNl, $items, count($items), $term);
    }

    private function render(SearchContext $context): array
    {
        PageCopy::flush();

        return (new PageCopy)->forRegion('search', 'below_grid', $context);
    }

    /*
     * -----------------------------------------------------------------
     * Data, never markup.
     * -----------------------------------------------------------------
     */

    #[Test]
    public function a_link_function_produces_parts_rather_than_html(): void
    {
        // Three products, so the brand earns a page and BrandLinker will resolve
        // it — a brand with fewer is deliberately not linked.
        $this->seedBrandPage('Aurex');
        $this->block('Merken hier: :brand_links.');

        $blocks = $this->render($this->context(['Aurex', 'Aurex', 'Aurex']));

        $this->assertCount(1, $blocks);

        $types = array_column($blocks[0]['parts'], 't');
        $this->assertContains('links', $types, 'the brands did not come back as a links part');

        $links = collect($blocks[0]['parts'])->firstWhere('t', 'links');
        $this->assertSame('Aurex', $links['items'][0]['label']);
        $this->assertStringContainsString('/brand/', $links['items'][0]['url']);

        // Nothing anywhere in the payload is markup.
        $this->assertStringNotContainsString('<a', json_encode($blocks));
    }

    #[Test]
    public function an_empty_link_list_hides_the_sentence_that_names_it(): void
    {
        $this->block('Merken hier: :brand_links.');

        // No brands in the results, so there is no list — and a sentence
        // introducing an empty one is worse than no sentence.
        $this->assertSame([], $this->render($this->context()));
    }

    /*
     * -----------------------------------------------------------------
     * Links to other pages, and to what this page is about.
     * -----------------------------------------------------------------
     */

    /**
     * A site link is market-correct without an editor thinking about it.
     *
     * The failure it prevents is quiet: a hand-typed `/be-nl/coves` is right in
     * one market and a 404 in four, the sentence still reads correctly, and the
     * only symptom is a dead link on thousands of pages in the markets nobody on
     * the team browses.
     */
    #[Test]
    public function a_site_link_resolves_against_the_pages_own_market(): void
    {
        $this->block('Kijk ook bij :coves_link.');

        $blocks = $this->render($this->context());
        $links = collect($blocks[0]['parts'])->firstWhere('t', 'links');

        $this->assertSame('/be-nl/coves', $links['items'][0]['url']);
        $this->assertNotSame('', $links['items'][0]['label']);
        $this->assertStringNotContainsString('.', $links['items'][0]['label'], 'an unresolved translation key reached a reader');
    }

    #[Test]
    public function a_site_link_takes_its_wording_from_the_readers_language(): void
    {
        $this->block('Kijk ook bij :guides_link.', ['language' => 'nl']);
        $this->block('Voir aussi :guides_link.', ['language' => 'fr']);

        $dutch = $this->render($this->context())[0]['parts'];
        $french = $this->render(new SearchContext(Market::BeFr, [], 0, 'casque'))[0]['parts'];

        $dutchLabel = collect($dutch)->firstWhere('t', 'links')['items'][0]['label'];
        $frenchLabel = collect($french)->firstWhere('t', 'links')['items'][0]['label'];

        $this->assertSame('/be-nl/guides', collect($dutch)->firstWhere('t', 'links')['items'][0]['url']);
        $this->assertSame('/be-fr/guides', collect($french)->firstWhere('t', 'links')['items'][0]['url']);
        $this->assertNotSame($dutchLabel, $frenchLabel, 'both markets linked with the same word');
    }

    /**
     * The subject of the page is plain text on the page it would link to.
     *
     * A self-link is noise for a reader and a wasted signal for a crawler, so
     * `:term_page_link` says the term on a search page and links it anywhere
     * else the block is reused.
     */
    #[Test]
    public function the_search_term_is_not_linked_to_the_page_it_is_already_on(): void
    {
        $this->block('Niets gevonden voor :term_page_link.');

        $parts = $this->render($this->context())[0]['parts'];

        $this->assertSame('text', $parts[0]['t']);
        $this->assertSame('Niets gevonden voor koptelefoon.', $parts[0]['v']);
    }

    #[Test]
    public function a_brand_is_linked_from_a_page_that_is_not_its_own(): void
    {
        $this->seedBrandPage('Aurex');
        $this->block('Meer van :brand_page_link.', ['page' => 'brand', 'region' => 'below_grid']);

        // Rendered against a page that is *not* the brand's own, which is the
        // arrangement a shared block meets when a region is added elsewhere.
        $context = $this->contextNamingBrand('Aurex');

        PageCopy::flush();
        $blocks = (new PageCopy)->forRegion('brand', 'below_grid', $context);

        $links = collect($blocks[0]['parts'])->firstWhere('t', 'links');

        $this->assertSame('Aurex', $links['items'][0]['label']);
        $this->assertSame('/be-nl/brand/aurex', $links['items'][0]['url']);
    }

    /**
     * A brand with no page of its own is words, not a broken link.
     *
     * A brand needs three products before it earns a page. Linking one that has
     * not is a 404 in a sentence on every page that mentions it, which is the
     * worst possible place for one.
     */
    #[Test]
    public function a_brand_without_a_page_is_rendered_as_plain_text(): void
    {
        $this->block('Meer van :brand_page_link.', ['page' => 'brand', 'region' => 'below_grid']);

        $context = $this->contextNamingBrand('Nietbestaand');

        PageCopy::flush();
        $blocks = (new PageCopy)->forRegion('brand', 'below_grid', $context);

        $this->assertSame('Meer van Nietbestaand.', $blocks[0]['parts'][0]['v']);
        $this->assertNull(collect($blocks[0]['parts'])->firstWhere('t', 'links'));
    }

    /*
     * -----------------------------------------------------------------
     * Lazy, and once.
     * -----------------------------------------------------------------
     */

    #[Test]
    public function a_function_nobody_names_is_never_resolved(): void
    {
        $counter = $this->countingFunction();

        PlaceholderRegistry::fake([...PlaceholderRegistry::all(), 'counted' => $counter]);

        $this->block('Een zin zonder plaatshouders.');
        $this->render($this->context());

        $this->assertSame(0, $counter::$calls, ':counted was resolved for a page that never mentions it');
    }

    #[Test]
    public function a_function_named_twice_is_resolved_once(): void
    {
        $counter = $this->countingFunction();

        PlaceholderRegistry::fake([...PlaceholderRegistry::all(), 'counted' => $counter]);

        $this->block('Eerst :counted.', ['position' => 1]);
        $this->block('En nogmaals :counted.', ['position' => 2]);

        $this->render($this->context());

        // Memoised on the context. One of these is a trigram query in real life.
        $this->assertSame(1, $counter::$calls);
    }

    #[Test]
    public function a_block_that_was_conditioned_away_resolves_nothing(): void
    {
        $counter = $this->countingFunction();

        PlaceholderRegistry::fake([...PlaceholderRegistry::all(), 'counted' => $counter]);

        $this->block('Alleen bij korting: :counted.', ['conditions' => ['has_discount']]);
        $this->render($this->context(['Aurex']));

        $this->assertSame(0, $counter::$calls);
    }

    /*
     * -----------------------------------------------------------------
     * The extension point itself.
     * -----------------------------------------------------------------
     */

    /**
     * The property the whole design exists for, asserted rather than trusted.
     *
     * A function registered today has to work inside a block written before it
     * existed — no migration, no schema change, no edit to the block.
     */
    #[Test]
    public function a_newly_registered_function_works_in_a_block_written_before_it(): void
    {
        $this->block('De levertijd is :delivery_window.');

        /*
         * Written before anything answered it.
         *
         * The token renders literally rather than hiding the paragraph, which is
         * the deliberate choice: prose containing a colon is far more likely
         * than a placeholder somebody deleted, and blanking a paragraph over a
         * punctuation mark would be baffling. Visible is the recoverable
         * failure. The admin refuses to save one of these in the first place.
         */
        $before = $this->render($this->context());
        $this->assertSame('De levertijd is :delivery_window.', $before[0]['parts'][0]['v']);

        PlaceholderRegistry::fake([
            ...PlaceholderRegistry::all(),
            'delivery_window' => new class implements PlaceholderFunction
            {
                public function name(): string
                {
                    return 'delivery_window';
                }

                public function label(): string
                {
                    return 'Delivery window';
                }

                public function help(): string
                {
                    return 'How long the shops on this page take.';
                }

                public function level(): Level
                {
                    return Level::Inline;
                }

                public function absent(): Absence
                {
                    return Absence::Blank;
                }

                public function sample(): Value
                {
                    return Value::text('1–3 werkdagen');
                }

                public function dependsOn(): array
                {
                    return [];
                }

                public function resolve(PageContext $context): Value
                {
                    return Value::text('1–3 werkdagen');
                }
            },
        ]);

        $blocks = $this->render($this->context());

        $this->assertCount(1, $blocks);
        $this->assertSame('De levertijd is 1–3 werkdagen.', $blocks[0]['parts'][0]['v']);
    }

    /*
     * -----------------------------------------------------------------
     * The absence rules.
     * -----------------------------------------------------------------
     */

    #[Test]
    public function zero_counts_as_missing_and_an_explicit_never_does_not(): void
    {
        $this->assertTrue(Absence::BlankOrZero->hides(0));
        $this->assertTrue(Absence::BlankOrZero->hides('0'));
        $this->assertTrue(Absence::BlankOrZero->hides(''));
        $this->assertTrue(Absence::BlankOrZero->hides(null));
        $this->assertFalse(Absence::BlankOrZero->hides(1));

        // For a value that can legitimately be zero.
        $this->assertFalse(Absence::Blank->hides(0));
        $this->assertTrue(Absence::Blank->hides(''));

        // For one the region's own guard already promises.
        $this->assertFalse(Absence::Never->hides(null));

        // A list collapses to its own emptiness, so links obey the same rule as
        // numbers with no special case at the call site.
        $this->assertTrue(Absence::Blank->hides([]));
        $this->assertFalse(Absence::Blank->hides([['label' => 'x', 'url' => '/x']]));
    }

    /**
     * A page that mentions a brand without being that brand's page.
     *
     * There is no such page today — the two contexts are search and brand — so
     * this stands in for the one a region added later would bring. It is exactly
     * the case `:brand_page_link` exists for: on the brand's own page it is
     * plain text, and everywhere else it is a link.
     */
    private function contextNamingBrand(string $brand): PageContext
    {
        return new class(Market::BeNl, [], 0, 'koptelefoon', $brand) extends PageContext
        {
            public function __construct(
                Market $market,
                array $items,
                int $total,
                string $rotationKey,
                private readonly string $brand,
            ) {
                parent::__construct($market, $items, $total, $rotationKey);
            }

            public function page(): string
            {
                return 'brand';
            }

            public function narrowUrl(string $term): string
            {
                return '/be-nl/search?q='.urlencode($term);
            }

            protected function computeFacts(): array
            {
                return ['brand' => $this->brand, 'term' => 'koptelefoon'];
            }

            protected function computeConditions(): array
            {
                return [];
            }
        };
    }

    /** A function that counts how often it is asked. */
    private function countingFunction(): PlaceholderFunction
    {
        $function = new class implements PlaceholderFunction
        {
            public static int $calls = 0;

            public function name(): string
            {
                return 'counted';
            }

            public function label(): string
            {
                return 'Counted';
            }

            public function help(): string
            {
                return 'Counts how often it is resolved.';
            }

            public function level(): Level
            {
                return Level::Inline;
            }

            public function absent(): Absence
            {
                return Absence::Blank;
            }

            public function sample(): Value
            {
                return Value::text('x');
            }

            public function dependsOn(): array
            {
                return [];
            }

            public function resolve(PageContext $context): Value
            {
                self::$calls++;

                return Value::text('x');
            }
        };

        /*
         * Reset between cases.
         *
         * PHP treats an anonymous class declaration as one class however many
         * instances are made of it, so the static counter carries across every
         * test in the process. Without this the second case to use it starts at
         * whatever the first left behind.
         */
        $function::$calls = 0;

        return $function;
    }

    private function seedBrandPage(string $brand): void
    {
        BrandStat::create([
            'market' => Market::BeNl->value,
            'brand' => $brand,
            'slug' => Str::slug($brand),
            'aliases' => [$brand],
            // Three, because a brand needs that many before it earns a page —
            // and BrandLinks defers to that rule rather than slugifying names.
            'product_count' => 3,
        ]);
    }
}

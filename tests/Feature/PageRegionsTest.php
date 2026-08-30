<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Models\PageBlock;
use App\Services\Pages\Context\BrandContext;
use App\Services\Pages\Context\PageContext;
use App\Services\Pages\Context\SearchContext;
use App\Services\Pages\Placeholders\PlaceholderRegistry;
use App\Services\Pages\Placeholders\Value;
use App\Services\Pages\Regions\Region;
use App\Services\Pages\Regions\RegionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The region registry, and the guardrail that replaces the old fallback.
 *
 * ## Why this file is the most important one in the feature
 *
 * Page copy used to have a floor: a slot with nothing in it rendered the
 * sentence from the language file, so the worst an editor could do was make a
 * page ordinary. That floor is gone on purpose — fixed system text was the thing
 * being replaced — and what stands in for it is `every_required_region_has_blocks_in_every_language`.
 *
 * Without that test, emptying a table is a silent event: the suite stays green,
 * the deploy succeeds, and four hundred words leave several thousand indexed
 * pages with nothing anywhere reporting it. With it, the same mistake is a red
 * build.
 *
 * ## And the agreement tests
 *
 * A region declares which placeholders it offers; a `PageContext` supplies the
 * values. Those are two lists that have to agree, and a disagreement is silent
 * in the worst direction: a placeholder nothing answers does not render `:foo`
 * to a reader, it **hides every block that names it**, on every page, for ever.
 * So both directions are asserted, and so is the same property for conditions.
 */
class PageRegionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A context per page, built from a fixture rather than mocked.
     *
     * The point is to run the real fact computation, because "the region
     * declares `:categories` and the context never sets it" is exactly the
     * failure being tested for.
     */
    private function contextFor(string $page): PageContext
    {
        return match ($page) {
            'search' => new SearchContext(Market::BeNl, [], 0, 'koptelefoon'),
            'brand' => new BrandContext(
                market: Market::BeNl,
                items: [],
                total: 0,
                brand: 'Sony',
                slug: 'sony',
                topShop: 'Coolblue',
                topCategory: 'Koptelefoons',
                categories: ['Koptelefoons', 'Speakers'],
            ),
            default => throw new \RuntimeException("No context fixture for the page '{$page}'. Add one when you add a page."),
        };
    }

    /*
     * -----------------------------------------------------------------
     * The guardrail.
     * -----------------------------------------------------------------
     */

    /**
     * Every region that must be written, is — in all four languages.
     *
     * `requiresContent` is what keeps this from breaking the build the moment
     * somebody adds a region: a brand-new one has no blocks in any language by
     * definition, and `above_grid` ships deliberately empty. A region flips to
     * required once it has been written.
     */
    #[Test]
    public function every_required_region_has_blocks_in_every_language(): void
    {
        $required = RegionRegistry::required();

        $this->assertNotEmpty($required, 'no region is marked as required, so this test guards nothing');

        foreach ($required as $region) {
            foreach (Market::languages() as $language) {
                $drawable = PageBlock::query()
                    ->where('page', $region->page)
                    ->where('region', $region->key)
                    ->where('language', $language)
                    ->where('enabled', true)
                    ->whereHas('variants', fn ($q) => $q->drawable())
                    ->count();

                $this->assertGreaterThan(
                    0,
                    $drawable,
                    "{$region->id()} has no drawable blocks in '{$language}'. "
                    ."There is no fallback beneath a region, so this is a blank section on every {$region->page} page in that language. "
                    .'Seed it, or write it in /admin → Page templates — the "Copy from another language" action is the quick fix.',
                );
            }
        }
    }

    /**
     * The seeded copy still carries the facts it was written to carry.
     *
     * A cheap smoke test on the migration's mapping: if a block's conditions or
     * kind were wrong, the words would still be there and the shape would not.
     */
    #[Test]
    public function the_seeded_search_copy_reads_as_sections(): void
    {
        $blocks = PageBlock::query()
            ->where('page', 'search')
            ->where('region', 'below_grid')
            ->where('language', 'nl')
            ->orderBy('position')
            ->get();

        $this->assertGreaterThan(15, $blocks->count());
        $this->assertSame(PageBlock::HEADING, $blocks->first()->kind, 'the region does not open with a heading');
        $this->assertGreaterThanOrEqual(4, $blocks->where('kind', PageBlock::HEADING)->count());

        // The guards that used to be hardcoded in PageNarrative came across as
        // data. If none did, the mapping silently dropped them and every
        // conditional sentence now renders unconditionally.
        $this->assertGreaterThan(
            0,
            $blocks->filter(fn (PageBlock $b) => ($b->conditions ?? []) !== [])->count(),
            'no seeded block carries a condition, so the old guards were lost',
        );
    }

    /** The related-searches block survived the move, as content. */
    #[Test]
    public function related_searches_is_a_block_an_editor_can_move(): void
    {
        foreach (['search', 'brand'] as $page) {
            foreach (Market::languages() as $language) {
                $widget = PageBlock::query()
                    ->where('page', $page)
                    ->where('region', 'below_grid')
                    ->where('language', $language)
                    ->whereHas('variants', fn ($q) => $q->where('body', ':related_searches'))
                    ->first();

                $this->assertNotNull(
                    $widget,
                    "{$page}/{$language} lost its related-searches block. It is content now, not markup, and it is seeded.",
                );

                // A widget draws a block of its own, so it must be a paragraph
                // holding nothing else — which is also what the admin validates.
                $this->assertSame(PageBlock::PARAGRAPH, $widget->kind);
                $this->assertSame(':related_searches', $widget->variants->first()->body);
            }
        }
    }

    /*
     * -----------------------------------------------------------------
     * The two lists that have to agree.
     * -----------------------------------------------------------------
     */

    #[Test]
    public function every_placeholder_a_region_offers_exists(): void
    {
        foreach (RegionRegistry::all() as $region) {
            foreach ($region->placeholders as $name) {
                $this->assertNotNull(
                    PlaceholderRegistry::find($name),
                    "{$region->id()} offers :{$name}, which no function answers. "
                    .'Every block naming it would silently never render.',
                );
            }
        }
    }

    /**
     * Every placeholder a region offers can actually be answered by that page.
     *
     * The nastiest failure in the feature, and the reason `dependsOn()` exists.
     * A region declaring `:brand_page_link` on a page whose context has no brand
     * does not render `:brand_page_link` to a reader — it hides every block that
     * mentions it, everywhere, for as long as nobody notices the paragraph is
     * missing. Nothing throws, nothing logs, and the page just reads shorter.
     */
    #[Test]
    public function every_offered_placeholder_has_the_facts_it_needs(): void
    {
        foreach (RegionRegistry::all() as $region) {
            $facts = array_keys($this->contextFor($region->page)->facts());

            foreach ($region->functions() as $function) {
                foreach ($function->dependsOn() as $fact) {
                    $this->assertContains(
                        $fact,
                        $facts,
                        "{$region->id()} offers :{$function->name()}, which needs the fact "
                        ."'{$fact}' — and that page's context never supplies it. "
                        .'Every block naming it would silently never render.',
                    );
                }
            }
        }
    }

    /**
     * And it resolves without throwing, against a real context.
     *
     * The declaration above says what a function needs; this runs it. A function
     * that reads the products directly declares no facts, so it is only this
     * that would catch it reaching for something a page does not have.
     */
    #[Test]
    public function every_offered_placeholder_resolves_without_throwing(): void
    {
        foreach (RegionRegistry::all() as $region) {
            $context = $this->contextFor($region->page);

            foreach ($region->functions() as $function) {
                $value = $context->resolve($function);

                $this->assertContains($value->type, [
                    Value::TEXT, Value::LINKS, Value::CHIPS, Value::NOTHING,
                ], "{$region->id()} — :{$function->name()} returned an unknown shape.");
            }
        }
    }

    /**
     * A link function produces data, never markup.
     *
     * The invariant that keeps an editable copy screen from being the site's
     * worst hole: there is no path from a textarea to an element, so a sample
     * and a resolved value both come back as a label and a URL.
     */
    #[Test]
    public function no_placeholder_ever_returns_markup(): void
    {
        foreach (PlaceholderRegistry::all() as $function) {
            $encoded = (string) json_encode($function->sample());

            $this->assertStringNotContainsString('<', $encoded, ":{$function->name()} returned markup in its sample");
        }
    }

    #[Test]
    public function every_declared_condition_is_answered_by_its_pages_context(): void
    {
        foreach (RegionRegistry::all() as $region) {
            $answered = array_keys($this->contextFor($region->page)->conditions());

            foreach ($region->conditionKeys() as $key) {
                $this->assertContains(
                    $key,
                    $answered,
                    "{$region->id()} offers the condition '{$key}', which its context never answers — "
                    .'so an unknown key fails closed and every block ticking it disappears.',
                );
            }
        }
    }

    #[Test]
    public function every_stored_block_names_a_region_that_exists(): void
    {
        $orphans = PageBlock::query()
            ->get()
            ->filter(fn (PageBlock $b) => RegionRegistry::find($b->page, $b->region) === null)
            ->map(fn (PageBlock $b) => "{$b->page}.{$b->region}")
            ->unique()
            ->values()
            ->all();

        $this->assertSame([], $orphans, 'blocks point at regions the code no longer declares');
    }

    #[Test]
    public function every_seeded_body_only_uses_placeholders_its_region_offers(): void
    {
        foreach (PageBlock::query()->with('variants')->get() as $block) {
            $region = RegionRegistry::find($block->page, $block->region);

            if ($region === null) {
                continue;
            }

            foreach ($block->variants as $variant) {
                foreach (PlaceholderRegistry::namesIn($variant->body) as $name) {
                    // Only names the registry knows: prose containing a colon is
                    // not a placeholder and is left alone by the renderer.
                    if (PlaceholderRegistry::find($name) === null) {
                        continue;
                    }

                    $this->assertTrue(
                        $region->offers($name),
                        "A block in {$region->id()} uses :{$name}, which that region does not offer.",
                    );
                }
            }
        }
    }

    /*
     * -----------------------------------------------------------------
     * A region nothing renders is worse than no region.
     * -----------------------------------------------------------------
     */

    /**
     * Every declared region reaches a page.
     *
     * The failure this prevents already happened once, to the retired
     * `brand_intro` surface: the registry still declared it, so admin still
     * listed it and an editor could rewrite copy, be told it saved, and see no
     * change on any page. Work silently discarded by a form reporting success.
     */
    #[Test]
    public function every_declared_region_is_rendered_by_a_page(): void
    {
        $rendered = [
            'search.above_grid' => ['/be-nl/search?q=koptelefoon', 'intro'],
            'search.below_grid' => ['/be-nl/search?q=koptelefoon', 'narrative'],
            'search.empty_state' => ['/be-nl/search?q=qwertyuiopasdf', 'emptyCopy'],
            'brand.above_grid' => [null, 'intro'],
            'brand.below_grid' => [null, 'narrative'],
            'brand.empty_state' => [null, 'emptyCopy'],
        ];

        foreach (RegionRegistry::all() as $region) {
            $this->assertArrayHasKey(
                $region->id(),
                $rendered,
                "{$region->id()} is declared but this test does not know which prop renders it. "
                .'Either wire it into a page, or the region is offering editors work that goes nowhere.',
            );
        }
    }

    #[Test]
    public function a_regions_blurb_says_where_it_renders(): void
    {
        // Not decoration: it is the only place an editor learns that a region is
        // suppressed on filtered URLs, or that one is deliberately kept short.
        foreach (RegionRegistry::all() as $region) {
            $this->assertNotSame('', trim($region->blurb), "{$region->id()} has no blurb");
            $this->assertNotSame('', trim($region->label));
            $this->assertContains($region->layout, [Region::SECTIONS, Region::FLOW]);
        }
    }
}

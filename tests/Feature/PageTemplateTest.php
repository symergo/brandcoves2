<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Models\PageBlock;
use App\Models\PageBlockVariant;
use App\Models\ProductGroup;
use App\Services\Pages\BlockSections;
use App\Services\Pages\Context\SearchContext;
use App\Services\Pages\PageCopy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Editable page templates: what renders, and what deliberately does not.
 *
 * Five properties, in order of how expensive they are to get wrong:
 *
 *  1. **Hiding is the only way a block resolves to nothing.** There is no
 *     fallback beneath a region, so every other path has to produce words.
 *  2. **A sentence never states a number the page does not have.** A block
 *     naming `:reduced` on a page with nothing reduced would read "0 products",
 *     which is not a gap but a false claim.
 *  3. **The draw is stable for a page within a period.** Rotating per request
 *     would break caching, flicker on back, and show a crawler a different
 *     document every fetch.
 *  4. **A save is visible on the next request.** The cache and the scoped
 *     instance both have to go, or an editor concludes the admin does not work.
 *  5. **A heading with nothing under it never renders.** A heading is what a
 *     section is; one standing over nothing is a broken page, not a short one.
 */
class PageTemplateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An empty template.
     *
     * The release migration seeds every region in four languages, which is the
     * property `PageRegionsTest` exists to hold. These cases are about the
     * resolver's rules rather than about the site's copy, and asserting "this
     * region renders nothing" against a region the migration filled would be
     * asserting nothing at all.
     */
    protected function setUp(): void
    {
        parent::setUp();

        PageBlockVariant::query()->delete();
        PageBlock::query()->delete();
        PageCopy::flush();
    }

    /**
     * Resolved fresh, because the per-request memo would otherwise carry one
     * assertion's block set into the next.
     */
    private function copy(): PageCopy
    {
        PageCopy::flush();

        return new PageCopy;
    }

    private function block(string $body, array $overrides = [], array $variants = []): PageBlock
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

        foreach ($variants as $extra) {
            $block->variants()->create(array_merge(['weight' => 1, 'enabled' => true], $extra));
        }

        return $block;
    }

    /**
     * A page with the facts a sentence might want.
     *
     * Built from real `ProductGroup` models rather than a stub, because the
     * arithmetic under test — how many are comparable, what the range is — reads
     * off exactly the columns a controller would hand it.
     */
    private function context(array $options = []): SearchContext
    {
        $items = [];

        foreach ($options['products'] ?? [] as $i => $product) {
            $items[] = new ProductGroup(array_merge([
                'market' => Market::BeNl->value,
                'identity_key' => 'ctx-'.$i,
                'identity_kind' => 'title',
                'title' => 'Test '.$i,
                'slug' => 'test-'.$i,
                'merchant_count' => 1,
            ], $product));
        }

        return new SearchContext(
            Market::BeNl,
            $items,
            $options['total'] ?? count($items),
            $options['term'] ?? 'koptelefoon',
        );
    }

    private function text(array $blocks): string
    {
        $out = '';

        foreach ($blocks as $block) {
            foreach ($block['parts'] as $part) {
                $out .= $part['t'] === 'text'
                    ? $part['v']
                    : implode(' ', array_column($part['items'], 'label'));
            }

            $out .= "\n";
        }

        return $out;
    }

    /*
     * -----------------------------------------------------------------
     * There is no floor.
     * -----------------------------------------------------------------
     */

    #[Test]
    public function a_region_with_no_blocks_renders_nothing(): void
    {
        // Not a shipped sentence, not a language string. Nothing — which is the
        // whole difference between this and the copy bank it replaced.
        $this->assertSame([], $this->copy()->forRegion('search', 'below_grid', $this->context()));
    }

    #[Test]
    public function a_disabled_block_never_renders(): void
    {
        $this->block('Zichtbaar.');
        $this->block('Verborgen.', ['enabled' => false, 'position' => 2]);

        $rendered = $this->text($this->copy()->forRegion('search', 'below_grid', $this->context()));

        $this->assertStringContainsString('Zichtbaar.', $rendered);
        $this->assertStringNotContainsString('Verborgen.', $rendered);
    }

    #[Test]
    public function a_block_with_no_drawable_phrasing_renders_nothing(): void
    {
        $block = $this->block('Enige zin.');
        // Weight zero is the soft retirement, and a retired phrasing must not
        // come back because it happened to be the only one left.
        $block->variants()->update(['weight' => 0]);

        $this->assertSame([], $this->copy()->forRegion('search', 'below_grid', $this->context()));
    }

    #[Test]
    public function blocks_are_scoped_to_their_language(): void
    {
        $this->block('Nederlands.', ['language' => 'nl']);
        $this->block('Français.', ['language' => 'fr']);

        $dutch = $this->text($this->copy()->forRegion('search', 'below_grid', $this->context()));

        $this->assertStringContainsString('Nederlands.', $dutch);
        $this->assertStringNotContainsString('Français.', $dutch);
    }

    #[Test]
    public function blocks_are_scoped_to_their_region(): void
    {
        $this->block('Onder het raster.', ['region' => 'below_grid']);
        $this->block('Boven het raster.', ['region' => 'above_grid']);

        $below = $this->text($this->copy()->forRegion('search', 'below_grid', $this->context()));

        $this->assertStringContainsString('Onder het raster.', $below);
        $this->assertStringNotContainsString('Boven het raster.', $below);
    }

    /*
     * -----------------------------------------------------------------
     * A sentence never claims a number the page does not have.
     * -----------------------------------------------------------------
     */

    #[Test]
    public function a_block_naming_a_missing_value_does_not_render(): void
    {
        $this->block(':reduced producten staan onder hun mediaan.');

        // Nothing on this page is reduced, so `:reduced` is 0 — and "0 products
        // are below their median" is a claim, not a gap.
        $this->assertSame([], $this->copy()->forRegion('search', 'below_grid', $this->context([
            'products' => [['min_price' => 1999]],
        ])));
    }

    #[Test]
    public function a_block_naming_a_present_value_renders_with_it_filled_in(): void
    {
        $this->block(':reduced producten staan onder hun mediaan.');

        $rendered = $this->text($this->copy()->forRegion('search', 'below_grid', $this->context([
            'products' => [
                ['min_price' => 1999, 'median_price' => 2999],
                ['min_price' => 4999, 'median_price' => 5999],
            ],
        ])));

        $this->assertStringContainsString('2 producten', $rendered);
    }

    /**
     * The reason the filter runs before the draw, not after.
     *
     * A block with two phrasings — one naming a number, one not — must render
     * whenever *either* can. Checking availability after the draw would make it
     * disappear on roughly half the pages that lack the number, depending
     * entirely on which phrasing the hash happened to pick.
     */
    #[Test]
    public function a_phrasing_that_cannot_render_falls_to_its_sibling(): void
    {
        $this->block(
            'Er is :percent% korting op deze pagina.',
            variants: [['body' => 'De prijzen hier lopen uiteen.']],
        );

        $rendered = $this->text($this->copy()->forRegion('search', 'below_grid', $this->context([
            'products' => [['min_price' => 1999]],
        ])));

        $this->assertStringContainsString('De prijzen hier lopen uiteen.', $rendered);
        $this->assertStringNotContainsString('korting', $rendered);
    }

    #[Test]
    public function a_word_that_is_not_a_placeholder_is_left_alone(): void
    {
        // Prose containing a colon is far more likely than a deleted
        // placeholder, and hiding a paragraph over a punctuation mark would be
        // baffling.
        $this->block('Let op: dit is gewoon tekst.');

        $rendered = $this->text($this->copy()->forRegion('search', 'below_grid', $this->context()));

        $this->assertStringContainsString('Let op: dit is gewoon tekst.', $rendered);
    }

    #[Test]
    public function the_longest_placeholder_name_wins(): void
    {
        // `:count` is a real placeholder and a prefix of nothing here — but the
        // matcher must not fire inside a longer word either.
        $this->block('Er zijn :count resultaten, :countdown niet.');

        $rendered = $this->text($this->copy()->forRegion('search', 'below_grid', $this->context([
            'products' => [['min_price' => 1999]],
            'total' => 7,
        ])));

        $this->assertStringContainsString('Er zijn 7 resultaten', $rendered);
        $this->assertStringContainsString(':countdown', $rendered);
    }

    /*
     * -----------------------------------------------------------------
     * Conditions.
     * -----------------------------------------------------------------
     */

    #[Test]
    public function a_block_whose_condition_is_false_does_not_render(): void
    {
        $this->block('Sommige producten worden door meer winkels verkocht.', ['conditions' => ['multi_shop']]);

        $this->assertSame([], $this->copy()->forRegion('search', 'below_grid', $this->context([
            'products' => [['min_price' => 1999, 'merchant_count' => 1]],
        ])));
    }

    #[Test]
    public function every_ticked_condition_must_hold(): void
    {
        $this->block('Beide.', ['conditions' => ['has_prices', 'has_discount']]);

        // Priced, but nothing discounted. AND, not OR.
        $this->assertSame([], $this->copy()->forRegion('search', 'below_grid', $this->context([
            'products' => [['min_price' => 1999]],
        ])));

        $this->assertNotSame([], $this->copy()->forRegion('search', 'below_grid', $this->context([
            'products' => [['min_price' => 1999, 'median_price' => 2999]],
        ])));
    }

    /**
     * A condition somebody renamed in code fails closed.
     *
     * The dangerous direction is the other one: a block whose gate has vanished
     * starting to render unconditionally, on every page in the market, saying
     * something that was only ever true sometimes.
     */
    #[Test]
    public function an_unknown_condition_hides_the_block(): void
    {
        $this->block('Ooit voorwaardelijk.', ['conditions' => ['a_condition_that_was_renamed']]);

        $this->assertSame([], $this->copy()->forRegion('search', 'below_grid', $this->context([
            'products' => [['min_price' => 1999]],
        ])));
    }

    /*
     * -----------------------------------------------------------------
     * Rotation.
     * -----------------------------------------------------------------
     */

    #[Test]
    public function the_draw_is_stable_for_a_page_within_a_period(): void
    {
        $this->block('Eerste.', variants: [['body' => 'Tweede.'], ['body' => 'Derde.']]);

        $first = $this->text($this->copy()->forRegion('search', 'below_grid', $this->context()));

        for ($i = 0; $i < 10; $i++) {
            $this->assertSame($first, $this->text(
                $this->copy()->forRegion('search', 'below_grid', $this->context()),
            ), 'the same page drew differently within one period');
        }
    }

    #[Test]
    public function two_pages_draw_differently(): void
    {
        $this->block('Eerste.', variants: [['body' => 'Tweede.'], ['body' => 'Derde.']]);

        $seen = [];

        foreach (['koptelefoon', 'speaker', 'laptop', 'muis', 'toetsenbord', 'monitor', 'webcam', 'tablet'] as $term) {
            $seen[$this->text($this->copy()->forRegion('search', 'below_grid', $this->context(['term' => $term])))] = true;
        }

        $this->assertGreaterThan(1, count($seen), 'every page drew the same phrasing');
    }

    #[Test]
    public function the_corpus_moves_between_periods(): void
    {
        $this->block('Eerste.', variants: [['body' => 'Tweede.'], ['body' => 'Derde.']]);

        $terms = ['koptelefoon', 'speaker', 'laptop', 'muis', 'toetsenbord', 'monitor'];

        $draw = fn (): string => implode('|', array_map(
            fn (string $t) => $this->text($this->copy()->forRegion('search', 'below_grid', $this->context(['term' => $t]))),
            $terms,
        ));

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-02'));
        $thisWeek = $draw();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14'));
        $sixWeeksOn = $draw();

        CarbonImmutable::setTestNow();

        $this->assertNotSame($thisWeek, $sixWeeksOn, 'the whole corpus said the same thing six weeks later');
    }

    #[Test]
    public function static_rotation_pins_a_page_forever(): void
    {
        config(['giftcoves.copy.rotation' => 'static']);

        $this->block('Eerste.', variants: [['body' => 'Tweede.'], ['body' => 'Derde.']]);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-02'));
        $now = $this->text($this->copy()->forRegion('search', 'below_grid', $this->context()));

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-05-01'));
        $muchLater = $this->text($this->copy()->forRegion('search', 'below_grid', $this->context()));

        CarbonImmutable::setTestNow();

        $this->assertSame($now, $muchLater);
    }

    #[Test]
    public function weight_biases_the_draw(): void
    {
        $block = $this->block('Zeldzaam.', variants: [['body' => 'Vaak.', 'weight' => 9]]);

        $often = 0;

        foreach (range(1, 60) as $i) {
            $rendered = $this->text($this->copy()->forRegion('search', 'below_grid', $this->context(['term' => 'term-'.$i])));
            $often += str_contains($rendered, 'Vaak.') ? 1 : 0;
        }

        // 9:1 over sixty draws. Loose bounds — this is asserting that weight
        // does something, not that the hash is uniform.
        $this->assertGreaterThan(40, $often);
        $this->assertLessThan(60, $often);
        $this->assertNotNull($block->fresh());
    }

    /*
     * -----------------------------------------------------------------
     * Sections.
     * -----------------------------------------------------------------
     */

    #[Test]
    public function a_heading_opens_a_section_and_the_paragraphs_after_it_belong_to_it(): void
    {
        $this->block('Vergelijken', ['kind' => PageBlock::HEADING, 'position' => 1]);
        $this->block('Eerste alinea.', ['position' => 2]);
        $this->block('Tweede alinea.', ['position' => 3]);
        $this->block('Prijzen', ['kind' => PageBlock::HEADING, 'position' => 4]);
        $this->block('Derde alinea.', ['position' => 5]);

        $sections = BlockSections::assemble(
            $this->copy()->forRegion('search', 'below_grid', $this->context()),
        );

        $this->assertCount(2, $sections);
        $this->assertSame('Vergelijken', $sections[0]['heading']);
        $this->assertCount(2, $sections[0]['body']);
        $this->assertSame('Prijzen', $sections[1]['heading']);
        $this->assertCount(1, $sections[1]['body']);
    }

    #[Test]
    public function a_heading_with_no_surviving_paragraphs_is_dropped(): void
    {
        $this->block('Prijzen', ['kind' => PageBlock::HEADING, 'position' => 1]);
        // Its only paragraph is conditioned away on this page.
        $this->block('Er is korting.', ['position' => 2, 'conditions' => ['has_discount']]);

        $sections = BlockSections::assemble(
            $this->copy()->forRegion('search', 'below_grid', $this->context([
                'products' => [['min_price' => 1999]],
            ])),
        );

        $this->assertSame([], $sections, 'a heading rendered over nothing');
    }

    #[Test]
    public function paragraphs_before_the_first_heading_form_a_section_without_one(): void
    {
        $this->block('Losse alinea.', ['position' => 1]);
        $this->block('Kop', ['kind' => PageBlock::HEADING, 'position' => 2]);
        $this->block('Onder de kop.', ['position' => 3]);

        $sections = BlockSections::assemble(
            $this->copy()->forRegion('search', 'below_grid', $this->context()),
        );

        $this->assertCount(2, $sections);
        $this->assertSame('', $sections[0]['heading']);
        $this->assertCount(1, $sections[0]['body']);
    }

    /*
     * -----------------------------------------------------------------
     * The cache.
     * -----------------------------------------------------------------
     */

    /**
     * The regression test for a bug the old copy bank had.
     *
     * `CopyBank::flush()` dropped the cache entry and left the *scoped instance*
     * in the container holding its memo — so the request that had just saved
     * went on to re-render from the copy it had already read. The symptom was an
     * editor saving, reloading, and concluding the admin did not work.
     *
     * Resolved from the container on both sides, deliberately: resolving fresh
     * would test nothing.
     */
    #[Test]
    public function a_saved_block_is_visible_to_the_next_resolve(): void
    {
        $block = $this->block('Eerste versie.');

        $this->assertStringContainsString(
            'Eerste versie.',
            $this->text(app(PageCopy::class)->forRegion('search', 'below_grid', $this->context())),
        );

        // Through the model, so its booted() hook is what flushes.
        $block->variants()->first()->update(['body' => 'Herschreven versie.']);

        $this->assertStringContainsString(
            'Herschreven versie.',
            $this->text(app(PageCopy::class)->forRegion('search', 'below_grid', $this->context())),
            'the container handed back a stale instance after a save',
        );
    }

    #[Test]
    public function deleting_a_block_is_visible_to_the_next_resolve(): void
    {
        $block = $this->block('Gaat weg.');

        $this->assertNotSame([], app(PageCopy::class)->forRegion('search', 'below_grid', $this->context()));

        $block->delete();

        $this->assertSame([], app(PageCopy::class)->forRegion('search', 'below_grid', $this->context()));
    }

    #[Test]
    public function the_resolver_is_shared_within_a_request(): void
    {
        // A render asks for three regions; a fresh instance each time would turn
        // one cache read into three.
        $this->assertSame(app(PageCopy::class), app(PageCopy::class));
    }
}

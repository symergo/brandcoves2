<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\EditPageTemplate;
use App\Models\PageBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The screen an editor actually uses.
 *
 * The three selects at the top are the whole navigation of this page — pick a
 * page, a region and a language, and the block list underneath is that region's.
 * When that wiring is wrong the screen does not error: it shows the previous
 * region's blocks under the new region's name, which is the worst kind of broken
 * because it looks like it worked, and the next Save writes one region's copy
 * into another's.
 *
 * ## Drive them the way a person does
 *
 * Every case here writes `data.region`, not `region`. That distinction is the
 * whole reason this file exists in its current form: the screen first shipped
 * with the selects in a `Section(...)->statePath('')` bound to public
 * properties, and `statePath('')` does not reset a child to the root — it
 * contributes nothing, so the fields inherited the form's `data` and rendered as
 * `wire:model="data.region"`. Picking a region in the browser changed the label
 * and nothing else.
 *
 * The tests passed throughout, because `set('region', …)` writes the property
 * directly and the browser never does that. A test that drives a path the
 * product does not have is worse than no test: it reports a feature works while
 * a person is looking at it not working.
 */
class PageTemplateAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    /** @param array<int|string, array<string, mixed>> $state */
    private function bodies(array $state): array
    {
        return array_values(array_map(
            fn (array $block) => (string) (array_values($block['variants'] ?? [])[0]['body'] ?? ''),
            $state,
        ));
    }

    /**
     * The selects are wired to the state the browser writes.
     *
     * Asserted against the rendered markup rather than through the component's
     * API, because the bug this pins was invisible from the API: the property
     * existed, the hook existed, and the two were simply not connected to the
     * control on screen.
     */
    #[Test]
    public function the_selects_are_bound_to_the_form_state(): void
    {
        $html = Livewire::actingAs($this->admin())->test(EditPageTemplate::class)->html();

        preg_match_all('/wire:model[^=]*="([^"]*)"/', $html, $matches);
        $paths = array_values(array_unique($matches[1]));

        foreach (['data.pageKey', 'data.region', 'data.language'] as $path) {
            $this->assertContains($path, $paths, "the select for {$path} is not bound to it");
        }
    }

    #[Test]
    public function it_opens_on_the_search_pages_long_copy(): void
    {
        $component = Livewire::actingAs($this->admin())->test(EditPageTemplate::class);

        $component->assertOk();

        $blocks = $component->get('data.blocks');

        $this->assertNotEmpty($blocks, 'the screen opened on an empty region');
        $this->assertStringContainsString(
            ':term',
            implode(' ', $this->bodies($blocks)),
            'the Dutch search copy is not what the screen opened on',
        );
    }

    #[Test]
    public function switching_the_region_reloads_the_block_list(): void
    {
        $component = Livewire::actingAs($this->admin())->test(EditPageTemplate::class);

        $before = $this->bodies($component->get('data.blocks'));

        $component->set('data.region', 'empty_state');

        $after = $this->bodies($component->get('data.blocks'));

        $this->assertNotSame($before, $after, 'the block list did not follow the region');
        $this->assertLessThan(count($before), count($after));
        $this->assertStringContainsString('zoekterm', implode(' ', $after));
    }

    #[Test]
    public function switching_the_language_reloads_the_block_list(): void
    {
        $component = Livewire::actingAs($this->admin())->test(EditPageTemplate::class);

        $dutch = $this->bodies($component->get('data.blocks'));

        $component->set('data.language', 'fr');

        $french = $this->bodies($component->get('data.blocks'));

        $this->assertNotSame($dutch, $french, 'the block list did not follow the language');
    }

    /**
     * Switching page has to move the region with it.
     *
     * A region belongs to a page, so leaving a search-only region selected under
     * `brand` would be a screen showing nothing, with no explanation of why.
     */
    #[Test]
    public function switching_the_page_moves_to_that_pages_first_region(): void
    {
        $component = Livewire::actingAs($this->admin())->test(EditPageTemplate::class);

        $component->set('data.pageKey', 'brand');

        $this->assertSame('above_grid', $component->get('region'));

        // `brand.above_grid` ships empty on purpose.
        $this->assertSame([], $component->get('data.blocks'));

        $component->set('data.region', 'below_grid');

        $this->assertNotEmpty($component->get('data.blocks'));
    }

    #[Test]
    public function it_saves_an_edit_and_renumbers_the_order(): void
    {
        $component = Livewire::actingAs($this->admin())->test(EditPageTemplate::class);

        $blocks = $component->get('data.blocks');
        $keys = array_keys($blocks);

        $variantKey = array_key_first($blocks[$keys[0]]['variants']);
        $blocks[$keys[0]]['variants'][$variantKey]['body'] = 'Herschreven kop.';

        // Drop the second block; the rest must renumber from 1 with no gap.
        unset($blocks[$keys[1]]);

        $component->set('data.blocks', $blocks)->call('save')->assertHasNoErrors();

        $stored = PageBlock::query()
            ->where('page', 'search')->where('region', 'below_grid')->where('language', 'nl')
            ->with('variants')
            ->orderBy('position')
            ->get();

        $this->assertSame(range(1, $stored->count()), $stored->pluck('position')->all());
        $this->assertSame('Herschreven kop.', $stored->first()->variants->first()->body);
    }

    #[Test]
    public function it_refuses_a_placeholder_the_region_does_not_offer(): void
    {
        $component = Livewire::actingAs($this->admin())->test(EditPageTemplate::class);

        $blocks = $component->get('data.blocks');
        $first = array_key_first($blocks);
        $variant = array_key_first($blocks[$first]['variants']);

        // `:shop` is a brand-page fact. A search page cannot supply it, so a
        // sentence naming it would silently never render.
        $blocks[$first]['variants'][$variant]['body'] = 'Bij :shop.';

        $component->set('data.blocks', $blocks)->call('save')->assertHasErrors();
    }

    #[Test]
    public function it_refuses_a_widget_that_is_not_alone_in_its_paragraph(): void
    {
        $component = Livewire::actingAs($this->admin())->test(EditPageTemplate::class);

        $blocks = $component->get('data.blocks');
        $first = array_key_first($blocks);
        $variant = array_key_first($blocks[$first]['variants']);

        $blocks[$first]['variants'][$variant]['body'] = 'Kijk ook: :related_searches en meer.';

        $component->set('data.blocks', $blocks)->call('save')->assertHasErrors();
    }

    #[Test]
    public function the_palette_lists_what_the_region_offers(): void
    {
        $component = Livewire::actingAs($this->admin())->test(EditPageTemplate::class);

        $tokens = array_column($component->instance()->palette(), 'token');

        $this->assertContains(':term', $tokens);
        $this->assertContains(':brand_links', $tokens);
        $this->assertContains(':related_searches', $tokens);
        $this->assertContains(':coves_link', $tokens);
        // A brand-page fact, and not on offer here.
        $this->assertNotContains(':shop', $tokens);
    }

    #[Test]
    public function copying_from_another_language_reproduces_the_region(): void
    {
        $component = Livewire::actingAs($this->admin())->test(EditPageTemplate::class);

        $component->set('data.region', 'above_grid');
        $this->assertSame([], $component->get('data.blocks'));

        // Write one block in French, then copy it into Dutch.
        $block = PageBlock::create([
            'page' => 'search', 'region' => 'above_grid', 'language' => 'fr',
            'kind' => PageBlock::PARAGRAPH, 'position' => 1, 'conditions' => [], 'enabled' => true,
        ]);
        $block->variants()->create(['body' => 'Tout sur :term.', 'weight' => 1, 'enabled' => true]);

        $component->callAction('copyFrom', ['from' => 'fr']);

        $blocks = $component->get('data.blocks');

        $this->assertCount(1, $blocks);
        $this->assertSame('Tout sur :term.', $this->bodies($blocks)[0]);
    }
}

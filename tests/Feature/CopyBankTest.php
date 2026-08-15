<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Filament\Pages\EditPageCopy;
use App\Models\CopyTemplate;
use App\Models\User;
use App\Services\Seo\CopyBank;
use App\Services\Seo\CopySlots;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Editable, rotating page copy.
 *
 * Three properties, in order of how expensive they are to get wrong:
 *
 *  1. **An empty or broken table cannot break a page.** The language file is the
 *     fallback, so the worst an editor can do is make a page ordinary again.
 *  2. **The draw is stable for a page within a period.** Rotating per request
 *     would break caching, flicker for anyone hitting back, and show a crawler a
 *     different document every fetch.
 *  3. **Placeholders are constrained per slot.** A wrong one either renders as
 *     literal text or asserts a number the page cannot back up, and neither
 *     throws.
 */
class CopyBankTest extends TestCase
{
    use RefreshDatabase;

    private function bank(): CopyBank
    {
        // Resolved fresh each time: the per-request memo would otherwise carry a
        // previous assertion's variant set into the next one.
        return new CopyBank;
    }

    private function variant(string $body, int $weight = 1, array $overrides = []): CopyTemplate
    {
        CopyBank::flush();

        return CopyTemplate::create(array_merge([
            'surface' => 'brand',
            'slot' => 'about_3',
            'language' => 'nl',
            'body' => $body,
            'weight' => $weight,
            'enabled' => true,
        ], $overrides));
    }

    #[Test]
    public function a_seeded_slot_shadows_a_rewritten_language_file(): void
    {
        $this->variant('The old sentence about :brand.');

        /*
         * The property `bc:seed-copy` is built around — never overwrite an
         * editor's work — has a consequence that is invisible until it bites:
         * once a slot is in the bank, rewriting its language file changes
         * nothing anywhere it has been seeded.
         *
         * The brand copy was rewritten, the tests passed and staging deployed,
         * and staging carried on serving the old sentences out of the database.
         */
        $this->artisan('bc:seed-copy', ['--language' => 'nl'])->assertSuccessful();

        $line = $this->bank()->line('brand', 'about_3', Market::BeNl, ['brand' => 'Sony'], 'sony');

        $this->assertSame('The old sentence about Sony.', $line);
    }

    #[Test]
    public function replace_puts_the_shipped_copy_back(): void
    {
        $this->variant('The old sentence about :brand.');

        $this->artisan('bc:seed-copy', [
            '--language' => 'nl',
            '--surface' => 'brand',
            '--replace' => true,
            '--force' => true,
        ])->assertSuccessful();

        $line = $this->bank()->line('brand', 'about_3', Market::BeNl, ['brand' => 'Sony', 'count' => '12'], 'sony');

        $this->assertStringNotContainsString('The old sentence', $line);
        $this->assertStringContainsString('Sony', $line);
    }

    #[Test]
    public function replace_leaves_other_surfaces_alone(): void
    {
        $this->variant('Kept.', 1, ['surface' => 'search', 'slot' => 'compare_1']);

        $this->artisan('bc:seed-copy', [
            '--language' => 'nl',
            '--surface' => 'brand',
            '--replace' => true,
            '--force' => true,
        ])->assertSuccessful();

        // Destructive by definition, so it is narrowed on purpose: a rewrite of
        // the brand pages must not take the search copy with it.
        $this->assertSame(
            'Kept.',
            $this->bank()->line('search', 'compare_1', Market::BeNl, [], 'x'),
        );
    }

    #[Test]
    public function a_dry_run_replaces_nothing(): void
    {
        $this->variant('The old sentence about :brand.');

        $this->artisan('bc:seed-copy', [
            '--language' => 'nl',
            '--surface' => 'brand',
            '--replace' => true,
            '--dry-run' => true,
        ])->assertSuccessful();

        CopyBank::flush();

        $this->assertSame(
            'The old sentence about Sony.',
            $this->bank()->line('brand', 'about_3', Market::BeNl, ['brand' => 'Sony'], 'sony'),
        );
    }

    #[Test]
    public function an_empty_table_renders_the_shipped_copy(): void
    {
        $line = $this->bank()->line('brand', 'about_3', Market::BeNl, ['brand' => 'Sony', 'count' => '12'], 'sony');

        // Whatever the Dutch file says, with the placeholders filled — and
        // crucially not an empty string or a dotted key.
        $this->assertStringContainsString('Sony', $line);
        $this->assertStringNotContainsString(':brand', $line);
        $this->assertStringNotContainsString('site.brand', $line);
    }

    #[Test]
    public function a_variant_overrides_the_shipped_copy(): void
    {
        $this->variant('Alles van :brand, :count stuks.');

        $line = $this->bank()->line('brand', 'about_3', Market::BeNl, ['brand' => 'Sony', 'count' => '12'], 'sony');

        $this->assertSame('Alles van Sony, 12 stuks.', $line);
    }

    #[Test]
    public function disabling_every_variant_falls_back_rather_than_rendering_nothing(): void
    {
        // The property that makes this table safe to hand over: an editor who
        // disables everything gets the shipped copy, not a blank page.
        $this->variant('Alles van :brand.', overrides: ['enabled' => false]);
        $this->variant('Nog iets van :brand.', weight: 0);

        $line = $this->bank()->line('brand', 'about_3', Market::BeNl, ['brand' => 'Sony', 'count' => '12'], 'sony');

        $this->assertNotSame('', $line);
        $this->assertStringNotContainsString('Alles van', $line);
        $this->assertStringNotContainsString('Nog iets van', $line);
        $this->assertStringContainsString('Sony', $line);
    }

    #[Test]
    public function the_same_page_gets_the_same_variant_twice(): void
    {
        /*
         * Rotating per request is the obvious reading of "rotate constantly" and
         * the one thing that would hurt: it defeats caching, flickers on a back
         * button, and shows a crawler a different document each fetch.
         */
        foreach (range(1, 6) as $i) {
            $this->variant("Variant {$i} van :brand.");
        }

        $first = $this->bank()->line('brand', 'about_3', Market::BeNl, ['brand' => 'Sony'], 'sony');
        $second = $this->bank()->line('brand', 'about_3', Market::BeNl, ['brand' => 'Sony'], 'sony');

        $this->assertSame($first, $second);
    }

    #[Test]
    public function different_pages_get_different_variants(): void
    {
        // The reason variants exist at all: thousands of pages opening with one
        // identical sentence is a pattern visible in a single sample.
        foreach (range(1, 6) as $i) {
            $this->variant("Variant {$i} van :brand.");
        }

        $bank = $this->bank();
        $seen = [];

        foreach (['sony', 'philips', 'bosch', 'jbl', 'samsung', 'lg', 'apple', 'asus'] as $brand) {
            $seen[] = $bank->line('brand', 'about_3', Market::BeNl, ['brand' => $brand], $brand);
        }

        $this->assertGreaterThan(2, count(array_unique($seen)), 'the rotation is not spreading across pages');
    }

    #[Test]
    public function two_slots_on_one_page_do_not_move_in_lockstep(): void
    {
        /*
         * Without the slot in the seed, every slot on a page draws the same
         * index — so a site with six variants each would have six documents
         * rather than many.
         */
        foreach (range(1, 4) as $i) {
            $this->variant("Lead {$i} :brand.");
            $this->variant("Comparison {$i} :brand.", overrides: ['slot' => 'about_1']);
        }

        $bank = $this->bank();
        $pairs = [];

        foreach (['sony', 'philips', 'bosch', 'jbl', 'lg', 'asus'] as $brand) {
            $lead = $bank->line('brand', 'about_3', Market::BeNl, ['brand' => $brand], $brand);
            $comparison = $bank->line('brand', 'about_1', Market::BeNl, ['brand' => $brand], $brand);

            preg_match('/Lead (\d)/', $lead, $a);
            preg_match('/Comparison (\d)/', $comparison, $b);

            $pairs[] = ($a[1] ?? '') === ($b[1] ?? '');
        }

        $this->assertContains(false, $pairs, 'every slot drew the same index');
    }

    #[Test]
    public function the_variant_changes_when_the_period_does(): void
    {
        foreach (range(1, 8) as $i) {
            $this->variant("Variant {$i} van :brand.");
        }

        config(['giftcoves.copy.rotation' => 'weekly']);

        $seen = [];

        // Twelve weeks. Over that span a page must not be showing one sentence.
        foreach (range(0, 11) as $week) {
            $this->travelTo(CarbonImmutable::create(2027, 1, 4)->addWeeks($week));
            $seen[] = $this->bank()->line('brand', 'about_3', Market::BeNl, ['brand' => 'Sony'], 'sony');
        }

        $this->assertGreaterThan(2, count(array_unique($seen)), 'the copy never rotated over time');
    }

    #[Test]
    public function static_rotation_pins_a_page_to_one_variant(): void
    {
        // The setting for comparing two rewrites rather than churning.
        foreach (range(1, 8) as $i) {
            $this->variant("Variant {$i} van :brand.");
        }

        config(['giftcoves.copy.rotation' => 'static']);

        $seen = [];

        foreach (range(0, 11) as $week) {
            $this->travelTo(CarbonImmutable::create(2027, 1, 4)->addWeeks($week));
            $seen[] = $this->bank()->line('brand', 'about_3', Market::BeNl, ['brand' => 'Sony'], 'sony');
        }

        $this->assertCount(1, array_unique($seen));
    }

    #[Test]
    public function weight_biases_the_draw(): void
    {
        $this->variant('Rare :brand.', weight: 1);
        $this->variant('Common :brand.', weight: 20);

        $bank = $this->bank();
        $common = 0;

        foreach (range(1, 200) as $i) {
            if (str_starts_with($bank->line('brand', 'about_3', Market::BeNl, ['brand' => "b{$i}"], "b{$i}"), 'Common')) {
                $common++;
            }
        }

        // 20:1 should land near 190. A loose bound, because the point is that
        // weight does something monotonic, not that the hash is uniform.
        $this->assertGreaterThan(150, $common);
    }

    #[Test]
    public function languages_do_not_bleed_into_each_other(): void
    {
        $this->variant('Nederlandse zin over :brand.', overrides: ['language' => 'nl']);

        $nl = $this->bank()->line('brand', 'about_3', Market::BeNl, ['brand' => 'Sony', 'count' => '5'], 'sony');
        $fr = $this->bank()->line('brand', 'about_3', Market::BeFr, ['brand' => 'Sony', 'count' => '5'], 'sony');

        $this->assertStringContainsString('Nederlandse zin', $nl);
        // French has no variant, so it falls back to the French file — never to
        // the Dutch variant.
        $this->assertStringNotContainsString('Nederlandse zin', $fr);
    }

    #[Test]
    public function a_longer_placeholder_is_not_eaten_by_a_shorter_one(): void
    {
        // `:count` inside `:count_shops` would otherwise be replaced first,
        // leaving a dangling "_shops" in the sentence.
        $this->variant(':count and :count_shops.', overrides: ['slot' => 'shops_count']);

        $line = $this->bank()->line('brand', 'shops_count', Market::BeNl, [
            'count' => '3',
            'count_shops' => '9',
        ], 'sony');

        $this->assertSame('3 and 9.', $line);
    }

    #[Test]
    public function every_slot_the_code_asks_for_has_shipped_copy_in_every_language(): void
    {
        /*
         * The registry and the language files are two lists that must agree. A
         * slot with no line renders an empty string — no exception, no log, just
         * a missing sentence on every page of that type.
         */
        $missing = [];

        foreach (CopySlots::all() as $key => $definition) {
            $namespace = CopySlots::namespaceFor($definition['surface']);

            foreach (['nl', 'fr', 'en', 'es'] as $language) {
                $line = __("{$namespace}.{$definition['slot']}", [], $language);

                if (! is_string($line) || $line === '' || str_contains($line, $namespace)) {
                    $missing[] = "{$key} / {$language}";
                }
            }
        }

        $this->assertSame([], $missing);
    }

    #[Test]
    public function shipped_copy_only_uses_placeholders_its_slot_supplies(): void
    {
        /*
         * The same rule the admin form enforces, applied to what we shipped. A
         * `:percent` in an always-rendered sentence would assert a 0% saving on
         * every page where nothing is discounted.
         */
        $offenders = [];

        foreach (CopySlots::all() as $key => $definition) {
            $namespace = CopySlots::namespaceFor($definition['surface']);

            foreach (['nl', 'fr', 'en', 'es'] as $language) {
                $line = __("{$namespace}.{$definition['slot']}", [], $language);

                if (! is_string($line)) {
                    continue;
                }

                $bad = CopySlots::disallowedIn($definition['surface'], $definition['slot'], $line);

                if ($bad !== []) {
                    $offenders[] = "{$key} / {$language}: :".implode(', :', $bad);
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    #[Test]
    public function a_typo_in_a_placeholder_is_caught(): void
    {
        // `:cont` renders as literal text to a reader, on every page of that
        // type, and nothing throws. The admin form refuses the save on this.
        $this->assertSame(
            ['cont'],
            CopySlots::disallowedIn('brand', 'about_3', 'Zoek je :brand? Wij volgen :cont producten.'),
        );
    }

    #[Test]
    public function a_placeholder_the_slot_cannot_supply_is_caught(): void
    {
        /*
         * The worse case. `about_3` renders on every brand page including those
         * where nothing is discounted, so a `:percent` in it would assert a 0%
         * saving — a false claim rather than a cosmetic bug.
         */
        $this->assertSame(['percent'], CopySlots::disallowedIn('brand', 'about_3', ':brand, tot :percent% korting.'));

        // The same placeholder is fine in a slot that only renders when
        // something actually is reduced.
        $this->assertSame([], CopySlots::disallowedIn('brand', 'faq_discount_a', ':reduced producten van :brand.'));
    }

    #[Test]
    public function a_time_is_not_mistaken_for_a_placeholder(): void
    {
        // A false positive here is a validation error an editor cannot explain.
        $this->assertSame([], CopySlots::disallowedIn('brand', 'about_3', 'Bijgewerkt om 09:15 vandaag, :brand.'));
    }

    #[Test]
    public function the_bank_is_shared_within_a_request(): void
    {
        /*
         * PageNarrative asks for around thirty slots per render. Resolved fresh
         * each time, every one of those is an object with an empty memo and the
         * cache is hit thirty times instead of once.
         */
        $this->assertSame(app(CopyBank::class), app(CopyBank::class));
    }

    #[Test]
    public function the_editor_saves_a_whole_page_at_once(): void
    {
        $admin = User::create(['email' => 'copy@example.test', 'password' => 'password-for-testing']);
        $admin->forceFill(['is_admin' => true])->save();

        $existing = $this->variant('Bestaande zin over :brand.');

        Livewire::actingAs($admin)
            ->test(EditPageCopy::class)
            ->set('surface', 'brand')
            ->set('language', 'nl')
            ->call('loadCopy')
            ->set('data.slots.about_3', [
                // An edit to the existing row, identified by its id.
                ['id' => $existing->id, 'body' => 'Herschreven zin over :brand.', 'weight' => 2, 'enabled' => true],
                // And a brand new variant in the same save.
                ['id' => null, 'body' => 'Een tweede zin over :brand.', 'weight' => 1, 'enabled' => true],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $rows = CopyTemplate::query()->where('slot', 'about_3')->where('language', 'nl')->get();

        $this->assertCount(2, $rows);
        // The edited row kept its id, so its author and created_at survive
        // rather than the table being rewritten on every save.
        $this->assertSame('Herschreven zin over :brand.', $rows->firstWhere('id', $existing->id)?->body);
        $this->assertSame(2, $rows->firstWhere('id', $existing->id)?->weight);
    }

    #[Test]
    public function removing_a_variant_in_the_editor_deletes_it(): void
    {
        $admin = User::create(['email' => 'copy2@example.test', 'password' => 'password-for-testing']);
        $admin->forceFill(['is_admin' => true])->save();

        $keep = $this->variant('Blijft staan :brand.');
        $drop = $this->variant('Gaat weg :brand.');

        Livewire::actingAs($admin)
            ->test(EditPageCopy::class)
            // The editor opens on the search surface; these slots are the brand
            // page's, so it has to be switched before the form is loaded.
            ->set('surface', 'brand')
            ->call('loadCopy')
            ->set('data.slots.about_3', [
                ['id' => $keep->id, 'body' => 'Blijft staan :brand.', 'weight' => 1, 'enabled' => true],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNotNull($keep->fresh());
        $this->assertNull($drop->fresh());
    }

    #[Test]
    public function the_editor_cannot_save_a_page_outside_the_one_on_screen(): void
    {
        /*
         * The delete pass removes anything the form no longer holds. Scoped to
         * the surface and language on screen — without that scope, saving the
         * brand page would wipe every search-page variant, which is the kind of
         * bug you discover from a support ticket.
         */
        $admin = User::create(['email' => 'copy3@example.test', 'password' => 'password-for-testing']);
        $admin->forceFill(['is_admin' => true])->save();

        $otherSurface = $this->variant('Zoekpagina zin.', overrides: ['surface' => 'search', 'slot' => 'compare_1']);
        $otherLanguage = $this->variant('French line about :brand.', overrides: ['language' => 'fr']);

        Livewire::actingAs($admin)
            ->test(EditPageCopy::class)
            ->set('surface', 'brand')
            ->set('language', 'nl')
            ->call('loadCopy')
            ->set('data.slots.about_3', [['id' => null, 'body' => 'Nieuw :brand.', 'weight' => 1, 'enabled' => true]])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNotNull($otherSurface->fresh(), 'saving the brand page deleted search copy');
        $this->assertNotNull($otherLanguage->fresh(), 'saving Dutch deleted French copy');
    }

    #[Test]
    public function the_editor_refuses_a_placeholder_the_slot_cannot_supply(): void
    {
        $admin = User::create(['email' => 'copy4@example.test', 'password' => 'password-for-testing']);
        $admin->forceFill(['is_admin' => true])->save();

        Livewire::actingAs($admin)
            ->test(EditPageCopy::class)
            ->set('surface', 'brand')
            ->call('loadCopy')
            ->set('data.slots.about_3', [
                ['id' => null, 'body' => ':brand, tot :percent% korting.', 'weight' => 1, 'enabled' => true],
            ])
            ->call('save')
            ->assertHasErrors();

        // Nothing written: a page that renders on every brand must not claim a
        // discount percentage.
        $this->assertSame(0, CopyTemplate::query()->count());
    }

    #[Test]
    public function seeding_imports_the_shipped_copy_and_never_overwrites_an_edit(): void
    {
        $this->artisan('bc:seed-copy')->assertSuccessful();

        $imported = CopyTemplate::query()->count();
        $this->assertGreaterThan(100, $imported);

        /*
         * One variant per slot, per language. It used to be four for the brand
         * pages' opening line: the retired `brand_intro.lead` shipped with four
         * alternatives that a hash picked between, and seeding turned each into a
         * row. Nothing ships alternatives now — see SeedCopyCommand::bodiesFor —
         * so the count an editor opens on is one, with a button to add more.
         */
        $this->assertSame(1, CopyTemplate::query()
            ->where('surface', 'brand')->where('slot', 'about_3')->where('language', 'nl')
            ->count());

        CopyTemplate::query()->where('slot', 'about_3')->where('language', 'nl')->update(['body' => 'EDITED :brand']);

        $this->artisan('bc:seed-copy')->assertSuccessful();

        // Re-running is a button in admin. Putting the shipped copy back on top
        // of someone's work would make it unusable.
        $this->assertSame($imported, CopyTemplate::query()->count());
        $this->assertSame(
            1,
            CopyTemplate::query()->where('slot', 'about_3')->where('language', 'nl')->where('body', 'EDITED :brand')->count(),
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Models\CopyTemplate;
use App\Services\Seo\CopyBank;
use App\Services\Seo\CopySlots;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'surface' => 'brand_intro',
            'slot' => 'lead',
            'language' => 'nl',
            'body' => $body,
            'weight' => $weight,
            'enabled' => true,
        ], $overrides));
    }

    #[Test]
    public function an_empty_table_renders_the_shipped_copy(): void
    {
        $line = $this->bank()->line('brand_intro', 'lead', Market::BeNl, ['brand' => 'Sony', 'count' => '12'], 'sony');

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

        $line = $this->bank()->line('brand_intro', 'lead', Market::BeNl, ['brand' => 'Sony', 'count' => '12'], 'sony');

        $this->assertSame('Alles van Sony, 12 stuks.', $line);
    }

    #[Test]
    public function disabling_every_variant_falls_back_rather_than_rendering_nothing(): void
    {
        // The property that makes this table safe to hand over: an editor who
        // disables everything gets the shipped copy, not a blank page.
        $this->variant('Alles van :brand.', overrides: ['enabled' => false]);
        $this->variant('Nog iets van :brand.', weight: 0);

        $line = $this->bank()->line('brand_intro', 'lead', Market::BeNl, ['brand' => 'Sony', 'count' => '12'], 'sony');

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

        $first = $this->bank()->line('brand_intro', 'lead', Market::BeNl, ['brand' => 'Sony'], 'sony');
        $second = $this->bank()->line('brand_intro', 'lead', Market::BeNl, ['brand' => 'Sony'], 'sony');

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
            $seen[] = $bank->line('brand_intro', 'lead', Market::BeNl, ['brand' => $brand], $brand);
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
            $this->variant("Comparison {$i} :brand.", overrides: ['slot' => 'comparison']);
        }

        $bank = $this->bank();
        $pairs = [];

        foreach (['sony', 'philips', 'bosch', 'jbl', 'lg', 'asus'] as $brand) {
            $lead = $bank->line('brand_intro', 'lead', Market::BeNl, ['brand' => $brand], $brand);
            $comparison = $bank->line('brand_intro', 'comparison', Market::BeNl, ['brand' => $brand], $brand);

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

        config(['brandcoves.copy.rotation' => 'weekly']);

        $seen = [];

        // Twelve weeks. Over that span a page must not be showing one sentence.
        foreach (range(0, 11) as $week) {
            $this->travelTo(CarbonImmutable::create(2027, 1, 4)->addWeeks($week));
            $seen[] = $this->bank()->line('brand_intro', 'lead', Market::BeNl, ['brand' => 'Sony'], 'sony');
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

        config(['brandcoves.copy.rotation' => 'static']);

        $seen = [];

        foreach (range(0, 11) as $week) {
            $this->travelTo(CarbonImmutable::create(2027, 1, 4)->addWeeks($week));
            $seen[] = $this->bank()->line('brand_intro', 'lead', Market::BeNl, ['brand' => 'Sony'], 'sony');
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
            if (str_starts_with($bank->line('brand_intro', 'lead', Market::BeNl, ['brand' => "b{$i}"], "b{$i}"), 'Common')) {
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

        $nl = $this->bank()->line('brand_intro', 'lead', Market::BeNl, ['brand' => 'Sony', 'count' => '5'], 'sony');
        $fr = $this->bank()->line('brand_intro', 'lead', Market::BeFr, ['brand' => 'Sony', 'count' => '5'], 'sony');

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

        $line = $this->bank()->line('brand_intro', 'shops_count', Market::BeNl, [
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
            CopySlots::disallowedIn('brand_intro', 'lead', 'Zoek je :brand? Wij volgen :cont producten.'),
        );
    }

    #[Test]
    public function a_placeholder_the_slot_cannot_supply_is_caught(): void
    {
        /*
         * The worse case. `lead` renders on every brand page including those
         * where nothing is discounted, so a `:percent` in it would assert a 0%
         * saving — a false claim rather than a cosmetic bug.
         */
        $this->assertSame(['percent'], CopySlots::disallowedIn('brand_intro', 'lead', ':brand, tot :percent% korting.'));

        // The same placeholder is fine in a slot that only renders when
        // something actually is reduced.
        $this->assertSame([], CopySlots::disallowedIn('brand_intro', 'discount_count', ':reduced producten van :brand.'));
    }

    #[Test]
    public function a_time_is_not_mistaken_for_a_placeholder(): void
    {
        // A false positive here is a validation error an editor cannot explain.
        $this->assertSame([], CopySlots::disallowedIn('brand_intro', 'lead', 'Bijgewerkt om 09:15 vandaag, :brand.'));
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
    public function seeding_imports_the_shipped_copy_and_never_overwrites_an_edit(): void
    {
        $this->artisan('bc:seed-copy')->assertSuccessful();

        $imported = CopyTemplate::query()->count();
        $this->assertGreaterThan(100, $imported);

        // Four openings for brand pages, from the lead_1..4 the site shipped
        // with — so an editor opens the admin with something to compare.
        $this->assertSame(4, CopyTemplate::query()
            ->where('surface', 'brand_intro')->where('slot', 'lead')->where('language', 'nl')
            ->count());

        CopyTemplate::query()->where('slot', 'lead')->where('language', 'nl')->update(['body' => 'EDITED :brand']);

        $this->artisan('bc:seed-copy')->assertSuccessful();

        // Re-running is a button in admin. Putting the shipped copy back on top
        // of someone's work would make it unusable.
        $this->assertSame($imported, CopyTemplate::query()->count());
        $this->assertSame(
            4,
            CopyTemplate::query()->where('slot', 'lead')->where('language', 'nl')->where('body', 'EDITED :brand')->count(),
        );
    }
}

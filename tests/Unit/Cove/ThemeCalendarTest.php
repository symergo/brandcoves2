<?php

declare(strict_types=1);

namespace Tests\Unit\Cove;

use App\Enums\Market;
use App\Services\Cove\Observance;
use App\Services\Cove\ObservanceCalendar;
use App\Services\Cove\ThemeRotation;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "A theme for every day."
 *
 * It is an easy promise to break silently — one untranslated key, one seasonal
 * theme added while an all-year one was removed, and some Tuesday in March
 * quietly publishes with no title at all. These tests are the only thing
 * standing between that and production.
 */
class ThemeCalendarTest extends TestCase
{
    #[Test]
    public function every_day_of_the_year_has_a_theme_in_every_market(): void
    {
        $calendar = app(ObservanceCalendar::class);

        // A leap year, so 29 February is covered too.
        $start = CarbonImmutable::create(2028, 1, 1);
        $missing = [];

        foreach (Market::cases() as $market) {
            for ($i = 0; $i < 366; $i++) {
                $date = $start->addDays($i);

                if ($calendar->themeFor($date, $market) === null) {
                    $missing[] = $market->value.' '.$date->toDateString();
                }
            }
        }

        $this->assertSame([], $missing);
    }

    #[Test]
    public function the_rotation_never_repeats_a_theme_inside_a_month(): void
    {
        $rotation = app(ThemeRotation::class);
        $seasonal = $this->seasonalKeys();

        foreach (range(1, 12) as $month) {
            $first = CarbonImmutable::create(2028, $month, 1);
            $keys = [];

            for ($day = 1; $day <= $first->daysInMonth; $day++) {
                $theme = $rotation->on($first->setDay($day), Market::BeNl);
                $this->assertNotNull($theme, "no theme for 2028-{$month}-{$day}");
                $keys[] = $theme->key;
            }

            /*
             * Run-up themes are ALLOWED to recur: the whole point of "before
             * Halloween" is that it shows up more than once in the fortnight
             * before Halloween. Only the ordinary rotation must be unique, and
             * it is unique because its eligible pool is longer than any month.
             */
            $ordinary = array_values(array_filter($keys, fn (string $key) => ! in_array($key, $seasonal, true)));

            $this->assertCount(
                count(array_unique($ordinary)),
                $ordinary,
                "month {$month} repeated an ordinary theme",
            );
        }
    }

    #[Test]
    public function the_same_date_always_yields_the_same_theme(): void
    {
        // bc:plan-coves drafts months ahead. If the theme were drawn at build
        // time the plan would describe an edition that never appears.
        $rotation = app(ThemeRotation::class);
        $date = CarbonImmutable::create(2027, 6, 14);

        $this->assertSame(
            $rotation->on($date, Market::BeNl)?->key,
            $rotation->on($date, Market::BeNl)?->key,
        );
    }

    #[Test]
    public function markets_diverge_over_a_month(): void
    {
        /*
         * Not a guarantee for any single date — two markets can collide — so
         * this asserts the weaker, meaningful thing. Five markets showing one
         * identical calendar would look automated, which is the impression the
         * whole feature exists to avoid.
         */
        $rotation = app(ThemeRotation::class);
        $start = CarbonImmutable::create(2027, 3, 1);
        $identical = 0;

        for ($i = 0; $i < 31; $i++) {
            $date = $start->addDays($i);

            if ($rotation->on($date, Market::BeNl)?->key === $rotation->on($date, Market::Es)?->key) {
                $identical++;
            }
        }

        $this->assertLessThan(10, $identical);
    }

    #[Test]
    public function named_days_win_over_the_rotation_and_are_not_marked_evergreen(): void
    {
        $calendar = app(ObservanceCalendar::class);

        $named = $calendar->themeFor(CarbonImmutable::create(2027, 10, 31), Market::BeNl);
        $this->assertNotNull($named);
        $this->assertSame('halloween', $named->key);
        $this->assertFalse($named->evergreen);

        // A run-up window that swallowed 31 October would replace the real
        // occasion with its own trailer.
        $this->assertNotSame('pre_halloween', $named->key);

        // 7 March is nothing in particular, so the rotation fills it.
        $evergreen = $calendar->themeFor(CarbonImmutable::create(2027, 3, 7), Market::BeNl);
        $this->assertNotNull($evergreen);
        $this->assertTrue($evergreen->evergreen);
    }

    #[Test]
    public function regional_run_ups_stay_in_their_own_markets(): void
    {
        $rotation = app(ThemeRotation::class);
        $start = CarbonImmutable::create(2027, 11, 11);
        $keys = [];

        for ($i = 0; $i < 24; $i++) {
            $keys[] = $rotation->on($start->addDays($i), Market::Es)?->key;
        }

        $this->assertNotContains('sinterklaas_run_up', $keys);
    }

    #[Test]
    public function every_configured_theme_has_a_title_in_every_market(): void
    {
        // A theme whose title is missing resolves to null and the day silently
        // loses its theme. Catch it here rather than on the front page.
        $untranslated = [];

        foreach ((array) config('cove_themes') as $theme) {
            foreach (Market::cases() as $market) {
                $observance = new Observance(key: (string) $theme['key'], evergreen: true);

                if ($observance->title($market) === null) {
                    $untranslated[] = $theme['key'].' / '.$market->value;
                }
            }
        }

        $this->assertSame([], $untranslated);
    }

    #[Test]
    public function every_configured_observance_has_a_title_in_every_market(): void
    {
        $untranslated = [];

        $entries = array_merge(
            array_values((array) config('observances.dates')),
            array_values((array) config('observances.moving')),
        );

        foreach ($entries as $entry) {
            foreach (Market::cases() as $market) {
                $observance = new Observance(key: (string) $entry['key']);

                if ($observance->title($market) === null) {
                    $untranslated[] = $entry['key'].' / '.$market->value;
                }
            }
        }

        $this->assertSame([], $untranslated);
    }

    /**
     * Keys of themes that carry a date window, and may therefore recur.
     *
     * @return list<string>
     */
    private function seasonalKeys(): array
    {
        return array_values(array_map(
            fn (array $theme) => (string) $theme['key'],
            array_filter(
                (array) config('cove_themes'),
                fn (array $theme) => isset($theme['window']),
            ),
        ));
    }
}

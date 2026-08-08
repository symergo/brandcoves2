<?php

declare(strict_types=1);

namespace App\Services\Cove;

use App\Enums\Market;
use Carbon\CarbonImmutable;

/**
 * A theme for every day the named calendar does not cover.
 *
 * Roughly a hundred days of the year are real observances worth naming. The
 * other two-thirds still need a shape, or the edition opens with "Today's
 * picks" and a shrug — which is precisely the reason nobody comes back.
 *
 * The assignment is a **deterministic permutation**, not a random draw:
 *
 *   1. Take the themes eligible for this month (some are seasonal).
 *   2. Shuffle them with a seed derived from year, month and market.
 *   3. Hand them out by day of month.
 *
 * Deterministic matters more than it sounds. `bc:plan-coves` drafts the
 * calendar months ahead so an editor can react to it; if the theme were drawn
 * at build time the plan would describe an edition that never appears. It also
 * means a re-run after a failed job produces the same edition rather than a
 * different one.
 *
 * The market is in the seed so five markets do not run the same theme on the
 * same day — someone comparing two markets should not find them identical, and
 * the catalogues differ anyway.
 *
 * Because step 3 is an index into a permutation with at least 31 entries, a
 * theme cannot repeat inside a month. Across a year a theme recurs about eight
 * times, on unrelated dates.
 */
class ThemeRotation
{
    public function on(CarbonImmutable $date, Market $market): ?Observance
    {
        $seed = $this->seed($date, $market);
        $chosen = $this->seasonal($date, $market, $seed) ?? $this->ordinary($date, $market, $seed);

        if ($chosen === null) {
            return null;
        }

        $observance = new Observance(
            key: (string) $chosen['key'],
            queries: array_values(array_map('strval', (array) ($chosen['queries'] ?? []))),
            evergreen: true,
        );

        // Same rule as the named calendar: a theme with no copy is not a theme.
        // Better to publish an untitled edition than a dotted translation key.
        return $observance->isUsable($market) ? $observance : null;
    }

    /**
     * The ordinary path: a month-eligible theme, one per day of the month.
     *
     * @return array<string, mixed>|null
     */
    private function ordinary(CarbonImmutable $date, Market $market, string $seed): ?array
    {
        $pool = $this->eligible($date, $market, windowed: false);

        if ($pool === []) {
            return null;
        }

        $ordered = $this->permute($pool, $seed);

        // day is 1-31; the pool is at least 31 long, so this never wraps and
        // therefore never repeats a theme inside a month.
        return $ordered[($date->day - 1) % count($ordered)];
    }

    /**
     * The seasonal path: roughly one day in three while a run-up is open.
     *
     * A whole fortnight of Halloween would make the site feel like a costume
     * shop, and the whole point of a daily edition is that tomorrow is not
     * today. One in three is enough to make the season unmistakable while
     * leaving room for the ordinary rotation to surprise.
     *
     * @return array<string, mixed>|null
     */
    private function seasonal(CarbonImmutable $date, Market $market, string $seed): ?array
    {
        $pool = $this->eligible($date, $market, windowed: true);

        if ($pool === [] || ! $this->isSeasonalSlot($date, $seed)) {
            return null;
        }

        $ordered = $this->permute($pool, $seed);

        // Indexed by day rather than by "how many seasonal slots so far",
        // which would need the whole window walked to answer. Windows are not
        // multiples of the pool size, so consecutive seasonal days land on
        // different themes in practice.
        return $ordered[$date->day % count($ordered)];
    }

    /**
     * Every third day, decided by hash rather than by `day % 3`.
     *
     * Modulo on the day number would put the seasonal slots on the same dates
     * in every market and every year, which reads as a pattern once you have
     * looked twice.
     */
    private function isSeasonalSlot(CarbonImmutable $date, string $seed): bool
    {
        $digest = hash('sha256', $seed.'|slot|'.$date->day);

        return hexdec(substr($digest, 0, 8)) % 3 === 0;
    }

    /**
     * Themes usable on this date.
     *
     * @param  bool  $windowed  true for run-up themes only, false for the rest
     * @return list<array<string, mixed>>
     */
    private function eligible(CarbonImmutable $date, Market $market, bool $windowed): array
    {
        $eligible = [];

        foreach ((array) config('cove_themes', []) as $theme) {
            if (! is_array($theme) || ! isset($theme['key'])) {
                continue;
            }

            $window = $theme['window'] ?? null;

            if ($windowed !== ($window !== null)) {
                continue;
            }

            $markets = (array) ($theme['markets'] ?? ['*']);

            if (! in_array('*', $markets, true) && ! in_array($market->value, $markets, true)) {
                continue;
            }

            if ($window !== null) {
                if ($this->inWindow($date, (array) $window)) {
                    $eligible[] = $theme;
                }

                continue;
            }

            $months = $theme['months'] ?? null;

            if ($months === null || in_array($date->month, (array) $months, true)) {
                $eligible[] = $theme;
            }
        }

        return $eligible;
    }

    /**
     * Is the date inside a MM-DD window?
     *
     * Compared as strings, which works because MM-DD sorts chronologically. A
     * window whose end is before its start wraps the year — the New Year reset
     * runs from 27 December to 20 January and would otherwise be empty.
     *
     * @param  array<string, mixed>  $window
     */
    private function inWindow(CarbonImmutable $date, array $window): bool
    {
        $from = (string) ($window['from'] ?? '');
        $to = (string) ($window['to'] ?? '');
        $today = $date->format('m-d');

        if ($from === '' || $to === '') {
            return false;
        }

        return $from <= $to
            ? $today >= $from && $today <= $to
            : $today >= $from || $today <= $to;
    }

    /**
     * A stable shuffle.
     *
     * Sorting by a hash of (seed, key) rather than calling shuffle() with a
     * seeded RNG, because mt_srand's sequence is not guaranteed stable across
     * PHP versions and this ordering has to survive an upgrade — a plan drafted
     * in March must still match the edition built in June.
     *
     * @param  list<array<string, mixed>>  $pool
     * @return list<array<string, mixed>>
     */
    private function permute(array $pool, string $seed): array
    {
        $keyed = [];

        foreach ($pool as $theme) {
            $keyed[] = [
                'sort' => hash('sha256', $seed.'|'.$theme['key']),
                'theme' => $theme,
            ];
        }

        usort($keyed, fn (array $a, array $b) => $a['sort'] <=> $b['sort']);

        return array_map(fn (array $row) => $row['theme'], $keyed);
    }

    private function seed(CarbonImmutable $date, Market $market): string
    {
        return $market->value.'|'.$date->year.'|'.$date->month;
    }
}

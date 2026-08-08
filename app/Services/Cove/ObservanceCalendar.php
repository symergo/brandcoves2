<?php

declare(strict_types=1);

namespace App\Services\Cove;

use App\Enums\Market;
use Carbon\CarbonImmutable;

/**
 * Which observance, if any, falls on a given day in a given market.
 *
 * Two kinds of entry, because two kinds exist in the world: fixed dates like
 * International Pet Day, and moving ones like Mother's Day, which is the second
 * Sunday of May here and a different date elsewhere. Treating the second kind as
 * fixed is the classic way to be a day wrong once a year, in public.
 */
class ObservanceCalendar
{
    public function on(CarbonImmutable $date, Market $market): ?Observance
    {
        return $this->moving($date, $market) ?? $this->fixed($date, $market);
    }

    /**
     * The next few observances, for a "coming up" strip.
     *
     * @return array<string, Observance> date (Y-m-d) => observance
     */
    public function upcoming(CarbonImmutable $from, Market $market, int $days = 30): array
    {
        $found = [];

        for ($i = 1; $i <= $days; $i++) {
            $date = $from->addDays($i);
            $observance = $this->on($date, $market);

            if ($observance !== null) {
                $found[$date->toDateString()] = $observance;
            }
        }

        return $found;
    }

    private function fixed(CarbonImmutable $date, Market $market): ?Observance
    {
        $entry = config('observances.dates.'.$date->format('m-d'));

        return $this->build($entry, $market);
    }

    /**
     * Moving observances, resolved for this specific year.
     *
     * `nth` occurrence of ISO weekday `day` in `month` — the second Sunday of
     * May, the fourth Friday of November.
     */
    private function moving(CarbonImmutable $date, Market $market): ?Observance
    {
        foreach ((array) config('observances.moving', []) as $entry) {
            if ((int) ($entry['month'] ?? 0) !== $date->month) {
                continue;
            }

            $first = $date->startOfMonth();
            // Days forward from the 1st to the first matching weekday, then
            // whole weeks for each further occurrence.
            $offset = ((int) $entry['day'] - $first->dayOfWeekIso + 7) % 7;
            $target = $first->addDays($offset + (((int) $entry['nth'] - 1) * 7));

            if ($target->month === $date->month && $target->day === $date->day) {
                $built = $this->build($entry, $market);

                if ($built !== null) {
                    return $built;
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed>|null $entry */
    private function build(?array $entry, Market $market): ?Observance
    {
        if (! is_array($entry) || ! isset($entry['key'])) {
            return null;
        }

        $markets = (array) ($entry['markets'] ?? ['*']);

        // Sinterklaas is not a Spanish event, and pretending otherwise is the
        // kind of detail that makes a site feel machine-made.
        if (! in_array('*', $markets, true) && ! in_array($market->value, $markets, true)) {
            return null;
        }

        return new Observance(
            key: (string) $entry['key'],
            queries: array_values(array_map('strval', (array) ($entry['queries'] ?? []))),
        );
    }
}

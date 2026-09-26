<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\Market;
use App\Services\Search\GiftIntentParser;
use App\Services\Search\ParsedIntent;
use Illuminate\Support\Facades\DB;

/**
 * What people search gifts for, as readings: "sister + yoga", "dad +
 * cooking", "someone who has everything". Counted, never remembered one by
 * one.
 *
 * Two sources, because each misses what the other holds:
 *
 * - `gift_search_demand`, written by {@see record()} from the search page.
 *   A gift search is answered by the suggestion engine and never reaches
 *   `search_log`, so this is the only place those searches are counted at
 *   all. It keeps the reading, not the words.
 * - `search_log`, read back through GiftIntentParser. It holds the short
 *   gift searches from before the search box learned to read them
 *   (2026-09-26), and every one somebody ran "as words".
 *
 * Aggregated counts only: neither table knows who searched. See
 * docs/features/persona-demand.md.
 */
class GiftSearchDemand
{
    public function __construct(private readonly GiftIntentParser $parser) {}

    /**
     * Count one gift search. Only the readings a persona could be about: at
     * least one interest, or "has everything". A search for "a present for
     * dad" alone is the landing page's job, not a persona's.
     */
    public function record(ParsedIntent $intent, Market $market): void
    {
        if (! $intent->isGift) {
            return;
        }

        $interests = $intent->interests !== [] ? $intent->interests : ($intent->hasEverything ? [''] : []);

        if ($interests === []) {
            return;
        }

        $now = now();
        $rows = array_map(fn (string $interest) => [
            'market' => $market->value,
            'day' => $now->toDateString(),
            'relationship' => (string) $intent->relationship,
            'interest' => $interest,
            'has_everything' => $intent->hasEverything,
            'searches' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], array_values(array_unique($interests)));

        DB::table('gift_search_demand')->upsert(
            $rows,
            ['market', 'day', 'relationship', 'interest', 'has_everything'],
            [
                'searches' => DB::raw('gift_search_demand.searches + 1'),
                'updated_at' => DB::raw('excluded.updated_at'),
            ],
        );
    }

    /**
     * Every reading searched in the last `$days` days, with how often and on
     * how many different days.
     *
     * @return list<array{relationship: string|null, interest: string|null, hasEverything: bool, searches: int, days: int}>
     */
    public function readings(Market $market, int $days, int $logRows = 2000): array
    {
        $since = now()->subDays($days)->startOfDay();

        /** @var array<string, array{relationship: string|null, interest: string|null, hasEverything: bool, searches: int, days: array<string, true>}> $readings */
        $readings = [];

        $add = function (?string $relationship, ?string $interest, bool $hasEverything, int $searches, array $days) use (&$readings): void {
            $relationship = $relationship === '' ? null : $relationship;
            $interest = $interest === '' ? null : $interest;
            $key = ($relationship ?? '').'|'.($interest ?? '').'|'.($hasEverything ? '1' : '0');

            $readings[$key] ??= ['relationship' => $relationship, 'interest' => $interest, 'hasEverything' => $hasEverything, 'searches' => 0, 'days' => []];
            $readings[$key]['searches'] += $searches;

            foreach ($days as $day) {
                $readings[$key]['days'][$day] = true;
            }
        };

        $counted = DB::table('gift_search_demand')
            ->where('market', $market->value)
            ->where('day', '>=', $since->toDateString())
            ->groupBy('relationship', 'interest', 'has_everything')
            ->select(
                'relationship',
                'interest',
                'has_everything',
                DB::raw('sum(searches)::int as searches'),
                DB::raw("string_agg(distinct to_char(day, 'YYYY-MM-DD'), ',') as days"),
            )
            ->get();

        foreach ($counted as $row) {
            $add((string) $row->relationship, (string) $row->interest, (bool) $row->has_everything, (int) $row->searches, explode(',', (string) $row->days));
        }

        /*
         * The search log, read through the same parser the search box uses.
         * Bounded to the most searched queries: a gift reading searched once
         * cannot reach the bar anyway, and the long tail is where the
         * crawler-minted strings live (SearchLog::MAX_LENGTH).
         */
        $logged = DB::table('search_log')
            ->where('market', $market->value)
            ->where('hour_bucket', '>=', $since)
            ->groupBy('query')
            ->select(
                'query',
                DB::raw('sum(search_count)::int as searches'),
                DB::raw("string_agg(distinct to_char(hour_bucket, 'YYYY-MM-DD'), ',') as days"),
            )
            ->orderByDesc('searches')
            ->limit($logRows)
            ->get();

        foreach ($logged as $row) {
            $intent = $this->parser->parse((string) $row->query, $market);

            if (! $intent->isGift) {
                continue;
            }

            $interests = $intent->interests !== [] ? $intent->interests : ($intent->hasEverything ? [null] : []);

            foreach (array_unique($interests) as $interest) {
                $add($intent->relationship, $interest, $intent->hasEverything, (int) $row->searches, explode(',', (string) $row->days));
            }
        }

        return array_values(array_map(fn (array $r) => [...$r, 'days' => count($r['days'])], $readings));
    }

    /**
     * Forget counts older than a year, the retention `search_log` has.
     * Nothing personal is in them; this only keeps the table from growing.
     */
    public function prune(): int
    {
        return DB::table('gift_search_demand')->where('day', '<', now()->subDays(365)->toDateString())->delete();
    }
}

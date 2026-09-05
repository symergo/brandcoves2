<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Every search, deduplicated per clock-hour.
 *
 * Not analytics for its own sake: this table is the input to the buying-guide
 * builder. What people search for here is the demand signal that decides which
 * guides get written.
 */
class SearchLog extends Model
{
    protected $table = 'search_log';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'hour_bucket' => 'datetime',
        ];
    }

    /**
     * Normalise before hashing so "  Bluetooth  Speaker" and "bluetooth speaker"
     * are one query rather than two — otherwise the volume that justifies a
     * guide is split across near-identical rows.
     */
    public static function normalise(string $query): string
    {
        $normalised = mb_strtolower(trim($query));

        return (string) preg_replace('/\s+/u', ' ', $normalised);
    }

    public static function hashFor(string $query, Market $market): string
    {
        return hash('sha256', self::normalise($query).'|'.$market->value);
    }

    /**
     * Upsert into the current day's bucket.
     *
     * One row per query per day. It was per *hour* until 2026-09-05, and that
     * resolution was buying nothing: no consumer reads the intra-day shape.
     * `TopicMiner` sums over a rolling window, `RelatedSearchQuery` and the
     * retention prune use the bucket as a date cutoff, and no admin screen reads
     * it at all. The one consumer that did depend on it, `RecentSearches`, now
     * orders by `updated_at` — which this upsert maintains on every conflict, and
     * which is exact at any bucket size.
     *
     * What it cost: a term searched every hour occupied up to 2,160 rows inside
     * the ninety days `RelatedSearchQuery` scans, every one of them fetched and
     * re-checked before the `GROUP BY` collapsed them to a single chip. That scan
     * is what made the canonical search page take seven seconds on production.
     * Per day the ceiling is 90.
     *
     * Not monthly, which would compress another thirty-fold: every consumer
     * filters with a day-precise cutoff, and month-start buckets make those
     * windows lumpy and the published 365-day retention enforceable only to the
     * nearest month. Days aggregate up to months later; months do not come back
     * apart.
     *
     * The column is still named `hour_bucket` and now holds a day boundary. The
     * rename is a breaking schema change and `migrate` runs while the previous
     * containers are still serving, so it ships as its own deploy.
     */
    public static function record(string $query, Market $market, int $resultCount): void
    {
        $normalised = self::normalise($query);
        if ($normalised === '') {
            return;
        }

        DB::table('search_log')->upsert(
            [[
                'query' => $normalised,
                'query_hash' => self::hashFor($query, $market),
                'market' => $market->value,
                'hour_bucket' => now()->startOfDay(),
                'search_count' => 1,
                'result_count' => $resultCount,
                'zero_result_count' => $resultCount === 0 ? 1 : 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['query_hash', 'hour_bucket'],
            [
                'search_count' => DB::raw('search_log.search_count + 1'),
                // Latest wins: the catalogue changes under us, and the most
                // recent count is the truest answer to "does this find anything".
                'result_count' => DB::raw('excluded.result_count'),
                'zero_result_count' => DB::raw('search_log.zero_result_count + excluded.zero_result_count'),
                'updated_at' => DB::raw('excluded.updated_at'),
            ],
        );
    }
}

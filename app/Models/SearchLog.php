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
     * Upsert into the current hour's bucket.
     *
     * One row per query per hour keeps the table small enough to aggregate over
     * 30 days without a warehouse, while still preserving the time shape that
     * makes seasonal topics visible.
     *
     * It was per *day* for one deploy on 2026-09-05, to shrink the trigram scan
     * that drew the related-search chips. That scan is gone — the chips were
     * removed rather than made cheaper — so the reason to coarsen the bucket
     * went with it, and the finer resolution is the one worth keeping: it is a
     * one-way door, and days never come back apart into hours.
     *
     * The rows folded during that deploy stay folded. The migration summed each
     * day's hours into one row and the hours are not recoverable, so history
     * before 2026-09-05 is day-resolution and everything after it is hourly.
     * Nothing reads the intra-day shape today, so this is a gap in a signal
     * nobody is consuming rather than a defect.
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
                'hour_bucket' => now()->startOfHour(),
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

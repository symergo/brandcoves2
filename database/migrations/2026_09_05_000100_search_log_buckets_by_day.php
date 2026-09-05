<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One row per query per day, where there were up to twenty-four.
 *
 * `search_log` is upserted on `(query_hash, hour_bucket)`, so a term searched
 * every hour occupied up to 2,160 rows inside the ninety days
 * `RelatedSearchQuery` scans — every one of them fetched, re-checked with
 * `word_similarity()`, and then collapsed to a single chip by the `GROUP BY`.
 * That scan is what made the canonical search page take 6.8-8.0s on production
 * (2026-09-04) while staging, on the identical commit, served the same terms in
 * 0.5s. Per day the ceiling is 90 rows instead of 2,160.
 *
 * The hourly resolution was buying nothing. Measured against every consumer:
 * `TopicMiner` sums over a rolling window, `RelatedSearchQuery` and
 * `PrunePersonalDataCommand` use the bucket as a date cutoff, and no admin
 * screen reads it at all. The one consumer that did depend on it —
 * `RecentSearches`, ordering by the bucket for recency — moves to `updated_at`
 * in the same change, which the upsert already maintains and which is exact at
 * any resolution.
 *
 * ## Why not monthly
 *
 * It would compress another thirty-fold, and two things argue against it. Every
 * consumer filters with a day-precise cutoff, and on month-start buckets those
 * windows go lumpy: `TopicMiner`'s queue would lurch as each month rolled over,
 * and the published 365-day retention could only be honoured to the nearest
 * month. And it is a one-way door — days aggregate up to months whenever we
 * want, months never come back apart. The compression is also being bought on a
 * path that is no longer hot, because the same change caches that scan.
 *
 * ## Why the column keeps its name
 *
 * `hour_bucket` now holds a day boundary, which is a name that lies. Renaming it
 * is a breaking schema change, and `migrate` runs as a one-shot service while
 * the previous containers are still serving — the old code would meet a column
 * it cannot read. Forward-only and expand/contract, so the rename ships as its
 * own later deploy. Recorded in docs/features/search.md so it is not forgotten.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Truncation is pinned to UTC rather than left to the session.
         *
         * `date_trunc` on a `timestamptz` truncates in the connection's TimeZone,
         * so the same statement produces different day boundaries depending on
         * who runs it. The application writes `now()->startOfDay()` in the app
         * timezone, which is UTC; saying so explicitly here is what keeps a
         * backfilled day and a freshly written one the same instant.
         */
        DB::statement(<<<'SQL'
            CREATE TEMPORARY TABLE search_log_rollup AS
            SELECT min(id) AS keep_id,
                   query_hash,
                   date_trunc('day', hour_bucket AT TIME ZONE 'UTC') AT TIME ZONE 'UTC' AS day_bucket,
                   sum(search_count)::int AS search_count,
                   sum(zero_result_count)::int AS zero_result_count,
                   -- Latest wins, exactly as the upsert does it: the catalogue
                   -- moves under us and the most recent count is the truest
                   -- answer to "does this term find anything".
                   (array_agg(result_count ORDER BY hour_bucket DESC, id DESC))[1] AS result_count,
                   max(updated_at) AS updated_at
            FROM search_log
            GROUP BY query_hash, date_trunc('day', hour_bucket AT TIME ZONE 'UTC') AT TIME ZONE 'UTC'
        SQL);

        /*
         * Delete before moving, never after.
         *
         * The survivors are about to land on the day boundary, and the unique
         * index on `(query_hash, hour_bucket)` is still live while they do. If
         * the folded-away rows were still present, a survivor moving to 00:00
         * would collide with whichever row was already written in hour zero —
         * and `min(id)` is not reliably that row.
         */
        DB::statement(<<<'SQL'
            DELETE FROM search_log s
            USING search_log_rollup r
            WHERE s.query_hash = r.query_hash
              AND date_trunc('day', s.hour_bucket AT TIME ZONE 'UTC') AT TIME ZONE 'UTC' = r.day_bucket
              AND s.id <> r.keep_id
        SQL);

        DB::statement(<<<'SQL'
            UPDATE search_log s
            SET hour_bucket = r.day_bucket,
                search_count = r.search_count,
                zero_result_count = r.zero_result_count,
                result_count = r.result_count,
                updated_at = r.updated_at
            FROM search_log_rollup r
            WHERE s.id = r.keep_id
        SQL);

        DB::statement('DROP TABLE search_log_rollup');

        /*
         * `RecentSearches` orders by `updated_at` from this change on, and
         * without an index that is a sort of every row in the market — the exact
         * shape of cost this migration exists to remove. The market leads
         * because the query always filters on it.
         */
        DB::statement('CREATE INDEX search_log_market_updated_at_index ON search_log (market, updated_at DESC)');
    }

    public function down(): void
    {
        // Forward-only: the folded rows are summed into their survivors and the
        // hours they were written in are gone. Dropping the index is the only
        // half that can be undone, and undoing it alone would leave the sort it
        // supports unindexed.
        DB::statement('DROP INDEX IF EXISTS search_log_market_updated_at_index');
    }
};

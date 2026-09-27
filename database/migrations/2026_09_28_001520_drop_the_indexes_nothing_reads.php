<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drop four indexes nothing reads.
 *
 * An index costs on every write: each INSERT, and each UPDATE that cannot be
 * done in place, adds an entry to every index on the table, and the catalogue
 * tables are written twice a day in bulk. The speed audit of 2026-09-27 read
 * `pg_stat_user_indexes` on production and found these with `idx_scan = 0`
 * since the statistics began, while their tables were written constantly.
 *
 *   products_status_last_seen_at_index
 *       Served the ingest's stale sweep (`status = 'active' AND last_seen_at <
 *       run start`), which production never planned through it: the sweep is
 *       per feed and went by `feed_id`. Since 2026-09-28 the sweep does not
 *       read `last_seen_at` at all (an anti-join against
 *       `ingestion_seen_offers`), so nothing is left that could use it. The
 *       admin offer table sorts by `last_seen_at` without a status filter,
 *       which an index leading on `status` does not serve either.
 *
 *   product_groups_market_slug_index
 *       A product page is found by its id; the slug in its URL is decoration.
 *       No query filters product_groups on slug.
 *
 *   events_kind_created_at_index
 *       `events` is a firehose (every gift suggestion, scan and swap), so this
 *       one costs the most per row. Its one possible reader is
 *       InterestCandidates (kind = 'gift.suggest' for a market over 90 days),
 *       behind an editorial API endpoint called by hand; on production the
 *       planner never chose the index for it. If that endpoint ever gets slow,
 *       a partial index `ON events (market, created_at) WHERE kind =
 *       'gift.suggest'` answers it for a fraction of the writes.
 *
 *   products_merchant_id_index
 *       Superseded by `products_merchant_market_status_idx` (merchant_id,
 *       market, status), added by `..._indexes_for_the_slow_queries`: a btree
 *       serves any query on its leading column, so every lookup by merchant
 *       (and the ON DELETE SET NULL from `merchants`) uses the new one. Dropped
 *       ONLY when that index exists and is valid, so a failed build of it can
 *       never leave `products.merchant_id` without an index.
 *
 * KEPT, although the audit listed it: `products_title_trgm_idx` (about 200 MB).
 * The admin offer search was rewritten on 2026-09-27 to `title ilike '%x%'`,
 * which is exactly what that trigram index serves; unused before, it is the
 * search box's index now.
 *
 * CONCURRENTLY, so no write waits on the drop, which is why this cannot run
 * inside a transaction. IF EXISTS, so a re-run and a database that never had
 * one of them (a fresh test database has all four) are both fine: a failing
 * migration is an outage, because Coolify stops the old containers before
 * `migrate` runs.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @var array<string, string> index name => its definition, for down() */
    private const UNUSED = [
        'products_status_last_seen_at_index' => 'products (status, last_seen_at)',
        'product_groups_market_slug_index' => 'product_groups (market, slug)',
        'events_kind_created_at_index' => 'events (kind, created_at)',
    ];

    private const SUPERSEDED = 'products_merchant_id_index';

    private const SUCCESSOR = 'products_merchant_market_status_idx';

    public function up(): void
    {
        foreach (array_keys(self::UNUSED) as $name) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        }

        $successorValid = DB::scalar(
            'SELECT i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid'
            .' WHERE c.relname = ? AND pg_table_is_visible(c.oid)',
            [self::SUCCESSOR],
        );

        if ($successorValid === true) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::SUPERSEDED);
        }
    }

    public function down(): void
    {
        foreach (self::UNUSED as $name => $on) {
            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON {$on}");
        }

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::SUPERSEDED.' ON products (merchant_id)');
    }
};

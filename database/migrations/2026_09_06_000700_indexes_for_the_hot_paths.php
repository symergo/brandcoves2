<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Four indexes for queries that ran without one, found in the 2026-09-06 review.
 *
 * - `products (feed_id, status)` — `IngestFeed::markStaleProducts()` filters on
 *   both at the end of every ingest, twice a day per feed, and the table's
 *   indexes lead on source, group, market, merchant or status: none on the feed.
 * - `price_history (captured_on)` — the 30-day median CTE in
 *   `ProductGrouper::recomputeAggregates()` filters on the date across the whole
 *   join; the two existing indexes lead on `product_id`.
 * - `product_groups (market, min_price)` and `(market, first_seen_at)` — the
 *   search sorts (`price_asc`, `price_desc`, `newest`, and the browse order)
 *   under a market filter. The partial indexes on the table are for the
 *   giftable and worth-showing surfaces and do not serve an unfiltered sort.
 *
 * Built CONCURRENTLY, which cannot run inside a transaction — hence
 * `$withinTransaction = false`. The migrate service runs before the app
 * containers start, so a plain CREATE INDEX would hold a lock on `products` for
 * the build while the site was already down for the deploy; concurrent costs
 * a little longer and locks nothing. `IF NOT EXISTS` makes a re-run harmless.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS products_feed_status_idx ON products (feed_id, status)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS price_history_captured_on_idx ON price_history (captured_on)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS product_groups_market_min_price_idx ON product_groups (market, min_price)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS product_groups_market_first_seen_idx ON product_groups (market, first_seen_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS products_feed_status_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS price_history_captured_on_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS product_groups_market_min_price_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS product_groups_market_first_seen_idx');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes for the slowest queries found by the speed audit of 2026-09-27,
 * each one measured on production with EXPLAIN ANALYZE. The measurements and
 * the queries each index serves are in docs/features/speed.md.
 *
 * - `product_groups (market, brand)`: the brand filter on search and the brand
 *   pages. Without it, a sequential scan of the whole table (255 ms).
 * - `product_groups (market, lower(brand))`: NextSteps::sameBrand() and
 *   SearchLanding::soldHere() match the brand case-insensitively (173 ms). The
 *   expression must be exactly `lower(brand)` for the planner to match it.
 * - `product_groups (market, category, merchant_count DESC, first_seen_at DESC)
 *   WHERE worth_showing AND in_stock`: CoveRail's "more from these categories",
 *   which filters on exactly those and sorts by exactly that, so Postgres reads
 *   the first rows of the index and stops.
 * - `products (merchant_id, market, status)`: what a shop's offers are, per
 *   market. The old index on `merchant_id` alone left the rest to a filter.
 * - `products (merchant_deep_link text_pattern_ops)`, partial: a pasted shop
 *   link is looked up by prefix (LinkRouter::feedMerchant(), `LIKE
 *   'https://shop/path%'`). A plain btree cannot serve LIKE outside the C
 *   collation; `text_pattern_ops` can. Limited to links under 2000 bytes
 *   because a btree entry has a hard size ceiling (about 2.7 kB): one freak URL
 *   in a feed would otherwise fail this build, and afterwards fail every
 *   ingest that tried to store it. LinkRouter asks the same condition.
 * - `wishlist_collaborators (user_id)`: "lists I help with". The unique index
 *   leads on `wishlist_id`, so it does not serve a lookup by user.
 * - `wishlists (recipient_id)`: the lists about a saved person.
 * - `restock_alerts (user_id)`: a person's own alerts. The unique index leads
 *   on `group_id`.
 * - `cove_plans (edition_id)`: from a published Cove back to its plan.
 * - `popular_ranks (market, captured_on)`: the latest charts of a market. The
 *   chart index leads on `source`.
 *
 * Built CONCURRENTLY, so nothing is locked while they build, which cannot run
 * inside a transaction: hence `$withinTransaction = false`. A concurrent build
 * that fails leaves an INVALID index behind under its name, which `IF NOT
 * EXISTS` would then skip forever; build() drops such a leftover first, so a
 * re-run after a failure really builds it. Expand only: nothing is dropped here
 * (the now-redundant `products_merchant_id_index` can go later, with the
 * other unused indexes).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @var array<string, string> index name => what follows ON */
    private const INDEXES = [
        'product_groups_market_brand_idx' => 'product_groups (market, brand)',
        'product_groups_market_lower_brand_idx' => 'product_groups (market, lower(brand))',
        'product_groups_category_rail_idx' => 'product_groups (market, category, merchant_count DESC, first_seen_at DESC) WHERE worth_showing = true AND in_stock = true',
        'products_merchant_market_status_idx' => 'products (merchant_id, market, status)',
        'products_deep_link_prefix_idx' => "products (merchant_deep_link text_pattern_ops) WHERE status = 'active' AND group_id IS NOT NULL AND octet_length(merchant_deep_link) < 2000",
        'wishlist_collaborators_user_idx' => 'wishlist_collaborators (user_id)',
        'wishlists_recipient_idx' => 'wishlists (recipient_id) WHERE recipient_id IS NOT NULL',
        'restock_alerts_user_idx' => 'restock_alerts (user_id) WHERE user_id IS NOT NULL',
        'cove_plans_edition_idx' => 'cove_plans (edition_id) WHERE edition_id IS NOT NULL',
        'popular_ranks_market_captured_idx' => 'popular_ranks (market, captured_on)',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $on) {
            $this->build($name, $on);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        }
    }

    private function build(string $name, string $on): void
    {
        $invalid = DB::scalar(
            'SELECT NOT i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid'
            .' WHERE c.relname = ? AND pg_table_is_visible(c.oid)',
            [$name],
        );

        if ($invalid === true) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        }

        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON {$on}");
    }
};

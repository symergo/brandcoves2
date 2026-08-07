<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Enums\Market;
use App\Enums\ProductStatus;
use Illuminate\Support\Facades\DB;

/**
 * Collapses offers into physical products.
 *
 * This is the mechanism the whole product rests on: without it there is no
 * offer comparison, no "cheapest across shops", and a search results page shows
 * eleven near-duplicate cards for one object.
 *
 * Entirely set-based — three statements over the catalogue rather than a loop
 * in PHP. Identity was already resolved at ingest, so this pass only has to
 * join, aggregate and write.
 */
class ProductGrouper
{
    /** @return array{groups: int, grouped_offers: int, comparable: int} */
    public function run(Market $market): array
    {
        $created = $this->createMissingGroups($market);
        $linked = $this->linkOffersToGroups($market);
        $this->recomputeAggregates($market);

        return [
            'groups' => $created,
            'grouped_offers' => $linked,
            'comparable' => $this->comparableCount($market),
        ];
    }

    /**
     * One group per (market, identity_key).
     *
     * Scoped to the market deliberately: the same product ingested for two
     * markets has different tax, shipping and availability, so those offers are
     * not interchangeable. Merging them lets a foreign price masquerade as the
     * cheapest one, which for a comparison site is the worst kind of wrong.
     *
     * The display fields are seeded from the cheapest in-stock offer, chosen by
     * DISTINCT ON. They are refreshed in recomputeAggregates() on every run.
     */
    private function createMissingGroups(Market $market): int
    {
        return DB::affectingStatement(<<<'SQL'
            INSERT INTO product_groups (
                market, identity_key, identity_kind,
                title, slug, brand, image_url, category,
                first_seen_at, created_at, updated_at
            )
            SELECT DISTINCT ON (p.identity_key)
                p.market,
                p.identity_key,
                p.identity_kind,
                p.title,
                left(regexp_replace(lower(unaccent(p.title)), '[^a-z0-9]+', '-', 'g'), 80),
                p.brand,
                p.image_url,
                p.merchant_category,
                now(), now(), now()
            FROM products p
            WHERE p.market = ?
              AND p.identity_key IS NOT NULL
              AND p.status = ?
            ORDER BY
                p.identity_key,
                -- Prefer a row that can actually be displayed and compared.
                (p.image_url IS NOT NULL) DESC,
                (p.price IS NOT NULL) DESC,
                p.price ASC NULLS LAST,
                p.id ASC
            ON CONFLICT (market, identity_key) DO NOTHING
        SQL, [$market->value, ProductStatus::Active->value]);
    }

    private function linkOffersToGroups(Market $market): int
    {
        return DB::affectingStatement(<<<'SQL'
            UPDATE products p
            SET group_id = g.id
            FROM product_groups g
            WHERE p.market = ?
              AND p.identity_key IS NOT NULL
              AND g.market = p.market
              AND g.identity_key = p.identity_key
              AND (p.group_id IS DISTINCT FROM g.id)
        SQL, [$market->value]);
    }

    /**
     * Recompute the denormalised aggregates a results page reads.
     *
     * These live on the group so rendering a page of results is one query
     * rather than one query plus N. Recomputed wholesale rather than
     * incrementally: prices change under us constantly and a drifted aggregate
     * is a wrong "cheapest" claim.
     *
     * Ties on price break on lowest id so repeated runs are stable. A group
     * whose best_offer_id flickers between two equally-priced merchants would
     * churn caches and produce a visibly jumpy UI for no reason.
     */
    private function recomputeAggregates(Market $market): void
    {
        DB::statement(<<<'SQL'
            WITH stats AS (
                SELECT
                    p.group_id,
                    count(*)                                          AS offer_count,
                    count(DISTINCT p.merchant_id)                     AS merchant_count,
                    min(p.price) FILTER (WHERE p.price IS NOT NULL)   AS min_price,
                    max(p.price) FILTER (WHERE p.price IS NOT NULL)   AS max_price,
                    bool_or(p.availability = 'in_stock')              AS in_stock
                FROM products p
                WHERE p.group_id IS NOT NULL
                  AND p.market = ?
                  AND p.status = 'active'
                GROUP BY p.group_id
            ),
            best AS (
                SELECT DISTINCT ON (p.group_id)
                    p.group_id,
                    p.id         AS best_offer_id,
                    p.title,
                    p.brand,
                    p.image_url,
                    p.merchant_category
                FROM products p
                WHERE p.group_id IS NOT NULL
                  AND p.market = ?
                  AND p.status = 'active'
                ORDER BY
                    p.group_id,
                    -- In stock beats cheap: an unbuyable price is not an offer.
                    (p.availability = 'in_stock') DESC,
                    p.price ASC NULLS LAST,
                    p.id ASC
            ),
            median AS (
                -- The reference for discount badges. Our own 30-day median,
                -- never a merchant-supplied "was" price, which is frequently
                -- fiction.
                SELECT
                    p.group_id,
                    percentile_cont(0.5) WITHIN GROUP (ORDER BY h.price)::int AS median_price
                FROM price_history h
                JOIN products p ON p.id = h.product_id
                WHERE p.group_id IS NOT NULL
                  AND p.market = ?
                  AND h.captured_on >= current_date - interval '30 days'
                GROUP BY p.group_id
            )
            UPDATE product_groups g
            SET offer_count    = stats.offer_count,
                merchant_count = stats.merchant_count,
                min_price      = stats.min_price,
                max_price      = stats.max_price,
                median_price   = median.median_price,
                in_stock       = stats.in_stock,
                best_offer_id  = best.best_offer_id,
                title          = best.title,
                brand          = best.brand,
                image_url      = best.image_url,
                category       = best.merchant_category,
                updated_at     = now()
            FROM stats
            JOIN best ON best.group_id = stats.group_id
            LEFT JOIN median ON median.group_id = stats.group_id
            WHERE g.id = stats.group_id
        SQL, [$market->value, $market->value, $market->value]);

        // Groups whose every offer vanished from the feeds. Zeroed rather than
        // deleted: a wishlist item or a published guide may still point here,
        // and a dead link is worse than an out-of-stock badge.
        DB::statement(<<<'SQL'
            UPDATE product_groups g
            SET offer_count = 0, merchant_count = 0, in_stock = false,
                min_price = NULL, max_price = NULL, best_offer_id = NULL,
                updated_at = now()
            WHERE g.market = ?
              AND NOT EXISTS (
                  SELECT 1 FROM products p
                  WHERE p.group_id = g.id AND p.status = 'active'
              )
              AND g.offer_count <> 0
        SQL, [$market->value]);
    }

    private function comparableCount(Market $market): int
    {
        return (int) DB::table('product_groups')
            ->where('market', $market->value)
            ->where('merchant_count', '>', 1)
            ->count();
    }
}

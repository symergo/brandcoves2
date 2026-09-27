<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
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
     *
     * ## Merges and splits
     *
     * An offer's group follows its *effective* key, not always its own:
     *
     *   override (a split) → else alias of that key (a merge) → else its own key
     *
     * Two statements rather than one, because nearly every offer has neither.
     * The first handles those plain offers exactly as before, skipping the few
     * an alias or override covers (two anti-joins against tables of a few
     * hundred rows). The second creates the group a split asked for. An aliased
     * offer never needs a new group: its alias points at a product that exists.
     * See docs/features/product-identity.md#merges-and-splits.
     */
    private function createMissingGroups(Market $market): int
    {
        $plain = DB::affectingStatement(<<<'SQL'
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
              AND NOT EXISTS (SELECT 1 FROM identity_overrides o WHERE o.product_id = p.id)
              AND NOT EXISTS (
                  SELECT 1 FROM identity_aliases a
                  WHERE a.market = p.market AND a.from_key = p.identity_key
              )
            ORDER BY
                p.identity_key,
                -- Prefer a row that can actually be displayed and compared.
                (p.image_url IS NOT NULL) DESC,
                (p.price IS NOT NULL) DESC,
                p.price ASC NULLS LAST,
                p.id ASC
            ON CONFLICT (market, identity_key) DO NOTHING
        SQL, [$market->value, ProductStatus::Active->value]);

        /*
         * The product a split asked for, keyed on the override.
         *
         * `identity_kind` is always `title` here, whatever the offer's own
         * kind: the kind says whether the key IS a barcode, and the product
         * page prints an EAN-kind key as the barcode. `split:123` is not one.
         * A split key that has since been merged somewhere (an alias on it) is
         * skipped for the same reason plain aliased keys are.
         */
        $split = DB::affectingStatement(<<<'SQL'
            INSERT INTO product_groups (
                market, identity_key, identity_kind,
                title, slug, brand, image_url, category,
                first_seen_at, created_at, updated_at
            )
            SELECT DISTINCT ON (o.forced_key)
                p.market,
                o.forced_key,
                'title',
                p.title,
                left(regexp_replace(lower(unaccent(p.title)), '[^a-z0-9]+', '-', 'g'), 80),
                p.brand,
                p.image_url,
                p.merchant_category,
                now(), now(), now()
            FROM identity_overrides o
            JOIN products p ON p.id = o.product_id
            WHERE p.market = ?
              AND p.status = ?
              AND NOT EXISTS (
                  SELECT 1 FROM identity_aliases a
                  WHERE a.market = p.market AND a.from_key = o.forced_key
              )
            ORDER BY o.forced_key, (p.image_url IS NOT NULL) DESC, p.price ASC NULLS LAST, p.id ASC
            ON CONFLICT (market, identity_key) DO NOTHING
        SQL, [$market->value, ProductStatus::Active->value]);

        return $plain + $split;
    }

    /**
     * Point every offer at the group of its effective key.
     *
     * The plain statement is the one that has always run, minus the offers an
     * alias or override covers. Without that exclusion it would move a merged
     * offer back to the product it was merged out of, and the second statement
     * would move it again: every run would rewrite those rows twice, and in
     * between a visitor would see the merge undone.
     */
    private function linkOffersToGroups(Market $market): int
    {
        $plain = DB::affectingStatement(<<<'SQL'
            UPDATE products p
            SET group_id = g.id
            FROM product_groups g
            WHERE p.market = ?
              AND p.identity_key IS NOT NULL
              AND g.market = p.market
              AND g.identity_key = p.identity_key
              AND (p.group_id IS DISTINCT FROM g.id)
              AND NOT EXISTS (SELECT 1 FROM identity_overrides o WHERE o.product_id = p.id)
              AND NOT EXISTS (
                  SELECT 1 FROM identity_aliases a
                  WHERE a.market = p.market AND a.from_key = p.identity_key
              )
        SQL, [$market->value]);

        /*
         * The offers a person moved. Found from the two small tables outward
         * (an override by product id, an alias through the `(market,
         * identity_key)` index on products), never by scanning the market.
         *
         * An override wins over the alias on the offer's own key, and is
         * itself followed through an alias: a product made by a split can be
         * merged into something else later, and its offers must follow.
         */
        $moved = DB::affectingStatement(<<<'SQL'
            UPDATE products p
            SET group_id = g.id
            FROM (
                SELECT o.product_id AS id, COALESCE(a.to_key, o.forced_key) AS key
                FROM identity_overrides o
                JOIN products p2 ON p2.id = o.product_id
                LEFT JOIN identity_aliases a ON a.market = p2.market AND a.from_key = o.forced_key
                WHERE p2.market = ?

                UNION ALL

                SELECT p2.id, a.to_key
                FROM identity_aliases a
                JOIN products p2 ON p2.market = a.market AND p2.identity_key = a.from_key
                WHERE a.market = ?
                  AND NOT EXISTS (SELECT 1 FROM identity_overrides o WHERE o.product_id = p2.id)
            ) e
            JOIN product_groups g ON g.market = ? AND g.identity_key = e.key
            WHERE p.id = e.id
              AND p.group_id IS DISTINCT FROM g.id
        SQL, [$market->value, $market->value, $market->value]);

        return $plain + $moved;
    }

    /**
     * Sources whose stored history may drive a visitor-facing price feature.
     *
     * Quoted for direct interpolation into an IN list.
     */
    private function trackableSources(): string
    {
        $allowed = array_filter(Source::cases(), fn (Source $s) => $s->allowsPriceTracking());

        return implode(', ', array_map(fn (Source $s) => "'{$s->value}'", $allowed));
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
    /**
     * Recompute the aggregates of a few products, now, rather than the market.
     *
     * For a merge or a split made in the admin: the product a person just
     * merged into must show its new offers and cheapest price on the next page
     * load, not after the next twice-daily run. The same statements as the
     * market pass, narrowed to these ids, so the two can never disagree about
     * which offer is the best one.
     *
     * @param  list<int>  $groupIds
     */
    public function recomputeGroups(Market $market, array $groupIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $groupIds)));

        if ($ids !== []) {
            $this->recomputeAggregates($market, $ids);
        }
    }

    /** @param list<int>|null $groupIds null for the whole market */
    private function recomputeAggregates(Market $market, ?array $groupIds = null): void
    {
        $sql = <<<'SQL'
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
                  AND p.market = ?%GROUPS%
                  AND p.status = 'active'
                GROUP BY p.group_id
            ),
            best AS (
                -- Which offer to LINK TO. Cheapest in-stock, and nothing else
                -- may influence it: this is the "best offer" the page sends a
                -- shopper to, and re-ranking it on anything cosmetic would send
                -- them to a dearer one.
                SELECT DISTINCT ON (p.group_id)
                    p.group_id,
                    p.id AS best_offer_id
                FROM products p
                WHERE p.group_id IS NOT NULL
                  AND p.market = ?%GROUPS%
                  AND p.status = 'active'
                ORDER BY
                    p.group_id,
                    -- In stock beats cheap: an unbuyable price is not an offer.
                    (p.availability = 'in_stock') DESC,
                    p.price ASC NULLS LAST,
                    p.id ASC
            ),
            display AS (
                /*
                 * Which offer to QUOTE. A separate question from which to link
                 * to, and it used to share an answer with it.
                 *
                 * One CTE supplied both, ordered on stock and price, so the
                 * title on 302,133 product pages was written by whichever
                 * merchant undercut the others this morning — and it arrived
                 * carrying that merchant's feed conventions. Measured on
                 * production 2026-09-05: 18,593 groups titled in ALL CAPS, and
                 * 38,495 whose brand was known and absent from the title. Both
                 * are the title *and* the <h1> *and* the schema.org name.
                 *
                 * `display_title` is deliberately not in the UPDATE: it is the
                 * hand-written title, owned by an editor, and a regrouping
                 * run must not take it back. See ProductGroup::displayTitle().
                 *
                 * Splitting the two lets the ordering below prefer a well-formed
                 * title without ever moving the offer a shopper is sent to. The
                 * price still breaks the tie, so among equally well-formed
                 * titles the cheapest seller still supplies it.
                 *
                 * Booleans sort false-first in Postgres, so each test is phrased
                 * as the defect and ordered ASC — the offers without it come
                 * first.
                 *
                 * Language is deliberately not one of the tests. ~4.5% of be-fr
                 * titles are Dutch, and telling one language from another in SQL
                 * needs a word list per market that would be wrong at the edges
                 * in a way these two mechanical tests are not.
                 * App\Services\Catalogue\ProductTitle cleans what survives.
                 */
                SELECT DISTINCT ON (p.group_id)
                    p.group_id,
                    p.title,
                    p.brand,
                    p.image_url,
                    p.merchant_category
                FROM products p
                WHERE p.group_id IS NOT NULL
                  AND p.market = ?%GROUPS%
                  AND p.status = 'active'
                ORDER BY
                    p.group_id,
                    -- Shouting. Four or more capitals, so a title that is only
                    -- "LG" or "JBL" is not mistaken for one.
                    (p.title = upper(p.title) AND p.title ~ '[A-Z]{4}') ASC,
                    -- Brand known and missing from the title. A substring test,
                    -- not the slug fold Str::slug() does — this only has to
                    -- order the candidates, and ProductTitle prefixes whatever
                    -- still lacks its brand at render.
                    (p.brand IS NOT NULL AND position(lower(p.brand) in lower(p.title)) = 0) ASC,
                    -- An out-of-stock offer's title is still a title, but a live
                    -- listing is likelier to be maintained.
                    (p.availability = 'in_stock') DESC,
                    p.price ASC NULLS LAST,
                    p.id ASC
            ),
            previous AS (
                -- The reference for discount badges: the price the offer we
                -- link to had before its last change. Until 2026-09-12 this
                -- was a 30-day median over price_history; the owner chose not
                -- to keep a history, so an offer carries first, previous and
                -- current price on its own row and the group quotes the
                -- previous price of its best offer, the way the price-drop
                -- mails put it: "was €189, now €149".
                --
                -- Trackable sources only, for the same reason the median was:
                -- an Amazon price may not drive a discount claim. See
                -- docs/features/amazon-compliance.md.
                SELECT b.group_id, p.previous_price
                FROM best b
                JOIN products p ON p.id = b.best_offer_id
                WHERE p.source IN (%TRACKABLE_SOURCES%)
            )
            UPDATE product_groups g
            SET offer_count    = stats.offer_count,
                merchant_count = stats.merchant_count,
                min_price      = stats.min_price,
                max_price      = stats.max_price,
                previous_price = previous.previous_price,
                in_stock       = stats.in_stock,
                best_offer_id  = best.best_offer_id,
                title          = display.title,
                brand          = display.brand,
                image_url      = display.image_url,
                category       = display.merchant_category,
                updated_at     = now()
            FROM stats
            JOIN best ON best.group_id = stats.group_id
            JOIN display ON display.group_id = stats.group_id
            LEFT JOIN previous ON previous.group_id = stats.group_id
            WHERE g.id = stats.group_id
              -- Only groups whose numbers or display moved (2026-09-28). Most
              -- do not between two runs, and rewriting an unchanged group
              -- cost a new row version and new entries in every index on
              -- product_groups, for the whole market twice a day. Also keeps
              -- `updated_at` meaning "changed".
              AND (g.offer_count, g.merchant_count, g.min_price, g.max_price,
                   g.previous_price, g.in_stock, g.best_offer_id,
                   g.title, g.brand, g.image_url, g.category)
                  IS DISTINCT FROM
                  (stats.offer_count::int, stats.merchant_count::int, stats.min_price, stats.max_price,
                   previous.previous_price, stats.in_stock, best.best_offer_id,
                   display.title, display.brand, display.image_url, display.merchant_category)
        SQL;

        /*
         * Interpolated, not bound: PDO cannot bind a list, and these values come
         * from an enum in this codebase rather than from a request. The same
         * reason the migrations build their CHECK constraints this way.
         */
        $sql = str_replace('%TRACKABLE_SOURCES%', $this->trackableSources(), $sql);

        // Ids are cast to int above, so interpolating them is as safe as the
        // enum values; a bound array would need the literal IncomingGrouper
        // builds, for a list that is never more than a handful.
        $filter = $groupIds === null ? '' : ' AND p.group_id IN ('.implode(',', $groupIds).')';
        $sql = str_replace('%GROUPS%', $filter, $sql);

        DB::statement($sql, [$market->value, $market->value, $market->value]);

        // Groups whose every offer vanished from the feeds. Zeroed rather than
        // deleted: a wishlist item or a published guide may still point here,
        // and a dead link is worse than an out-of-stock badge.
        DB::statement(str_replace('%ZERO_GROUPS%', $groupIds === null ? '' : ' AND g.id IN ('.implode(',', $groupIds).')', <<<'SQL'
            UPDATE product_groups g
            SET offer_count = 0, merchant_count = 0, in_stock = false,
                min_price = NULL, max_price = NULL, best_offer_id = NULL, previous_price = NULL,
                updated_at = now()
            WHERE g.market = ?
              AND NOT EXISTS (
                  SELECT 1 FROM products p
                  WHERE p.group_id = g.id AND p.status = 'active'
              )
              AND g.offer_count <> 0%ZERO_GROUPS%
        SQL), [$market->value]);
    }

    private function comparableCount(Market $market): int
    {
        return (int) DB::table('product_groups')
            ->where('market', $market->value)
            ->where('merchant_count', '>', 1)
            ->count();
    }
}

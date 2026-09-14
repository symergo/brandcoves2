<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Enums\Market;
use App\Services\Connectors\Offer;
use Illuminate\Support\Facades\DB;

/**
 * Attach offers that have just arrived to their product groups.
 *
 * Deliberately narrow. It creates groups for identities nobody has seen before
 * and links the incoming rows to them, but it does not recompute market-wide
 * aggregates — {@see ProductGrouper} owns that, nightly. What it must do is
 * make the new offer *countable*, which is why the offer count, merchant count,
 * price range and stock flag are refreshed for the touched groups: otherwise
 * the card a visitor is about to see says "1 shop" when a second shop's offer
 * landed a moment ago.
 *
 * ## Why this is a service rather than a method on the caller
 *
 * There are two ways an offer enters the catalogue outside a feed run: a
 * shopper's search pulls bol in live, and an author imports a page of products
 * from a merchant's own site. Both need exactly this, and a second copy of it
 * would be a second implementation of when an offer may join a group — which is
 * precisely where a wrong merge would come from, and a wrong merge is the worst
 * bug this site has, because it lets one market's price masquerade as another's
 * cheapest offer. One implementation, two callers.
 *
 * Every statement is scoped to the market as well as the ids, honouring the
 * rule that product identity is per market: `product_groups` is unique on
 * `(market, identity_key)` and an offer only ever joins a group in its own
 * market.
 */
class IncomingGrouper
{
    /**
     * Take the offers themselves rather than their ids.
     *
     * Both callers used to pass a bare list of ids, which is what let the
     * statements below forget the source. An offer carries its source, so the
     * pair cannot drift apart on the way in.
     *
     * @param  list<Offer>  $offers  as just written
     */
    public function attach(Market $market, array $offers): void
    {
        /** @var array<string, list<string>> $bySource source => external ids */
        $bySource = [];

        foreach ($offers as $offer) {
            $bySource[$offer->source->value][] = $offer->externalId;
        }

        foreach ($bySource as $source => $externalIds) {
            $this->attachFrom($market, $source, $externalIds);
        }
    }

    /**
     * One source's offers, found by source, id and market together.
     *
     * Never by id and market alone, for two reasons.
     *
     * **Speed.** The only index on the offer id is `(source, external_id,
     * market)`, and a filter that leaves out its leading column cannot seek into
     * it: Postgres walked the entire index for each of the three statements
     * below. That is what made the first search for any term take from 7 to
     * over 45 seconds on production, and the bol page import time out, on
     * 2026-09-14. Measured there, warm: 698 ms for one lookup as it was, 0.7 ms
     * with the source added.
     *
     * **Exactness.** An id is only unique within its source. Without the source,
     * another shop's row that happened to share the number was swept in too:
     * grouped outside the nightly run, and its group's counts rewritten, because
     * a bol offer arrived. Never a wrong merge — it relinks by its own identity —
     * but work nobody asked for, on rows nobody sent.
     *
     * Only the step that finds the touched groups is scoped to the source. The
     * counts that follow deliberately are not: a group's shop count is every
     * shop's offers, whichever source brought this one in.
     *
     * @param  list<string>  $externalIds
     */
    private function attachFrom(Market $market, string $source, array $externalIds): void
    {
        $ids = $this->literal($externalIds);

        DB::statement(<<<'SQL'
            INSERT INTO product_groups (
                market, identity_key, identity_kind, title, slug, brand, image_url, category,
                first_seen_at, created_at, updated_at
            )
            SELECT DISTINCT ON (p.identity_key)
                p.market, p.identity_key, p.identity_kind, p.title,
                left(regexp_replace(lower(unaccent(p.title)), '[^a-z0-9]+', '-', 'g'), 80),
                p.brand, p.image_url, p.merchant_category, now(), now(), now()
            FROM products p
            WHERE p.source = ? AND p.market = ? AND p.external_id = ANY(?) AND p.identity_key IS NOT NULL
            ORDER BY p.identity_key, (p.image_url IS NOT NULL) DESC, p.price ASC NULLS LAST, p.id
            ON CONFLICT (market, identity_key) DO NOTHING
        SQL, [$source, $market->value, $ids]);

        DB::statement(<<<'SQL'
            UPDATE products p SET group_id = g.id
            FROM product_groups g
            WHERE p.source = ? AND p.market = ? AND p.external_id = ANY(?)
              AND g.market = p.market AND g.identity_key = p.identity_key
              AND p.group_id IS DISTINCT FROM g.id
        SQL, [$source, $market->value, $ids]);

        DB::statement(<<<'SQL'
            WITH touched AS (
                SELECT DISTINCT group_id FROM products
                WHERE source = ? AND market = ? AND external_id = ANY(?) AND group_id IS NOT NULL
            ),
            stats AS (
                SELECT p.group_id,
                       count(*) AS offer_count,
                       count(DISTINCT p.merchant_id) AS merchant_count,
                       min(p.price) FILTER (WHERE p.price IS NOT NULL) AS min_price,
                       max(p.price) FILTER (WHERE p.price IS NOT NULL) AS max_price,
                       bool_or(p.availability = 'in_stock') AS in_stock
                FROM products p
                JOIN touched t ON t.group_id = p.group_id
                WHERE p.status = 'active'
                GROUP BY p.group_id
            )
            UPDATE product_groups g
            SET offer_count = stats.offer_count,
                merchant_count = stats.merchant_count,
                min_price = stats.min_price,
                max_price = stats.max_price,
                in_stock = stats.in_stock,
                updated_at = now()
            FROM stats WHERE g.id = stats.group_id
        SQL, [$source, $market->value, $ids]);
    }

    /**
     * A Postgres text-array literal for `= ANY(?)`.
     *
     * Built rather than bound as an array because PDO has no array type: the
     * driver would stringify a PHP array and the statement would match nothing,
     * silently. Every element is quoted, and any quote or backslash inside an
     * id is escaped — external ids come from third-party feeds, so an
     * unescaped one is both a broken statement and an injection.
     *
     * @param  list<string>  $ids
     */
    private function literal(array $ids): string
    {
        $quoted = array_map(
            fn (string $id): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $id).'"',
            array_values(array_unique($ids)),
        );

        return '{'.implode(',', $quoted).'}';
    }
}

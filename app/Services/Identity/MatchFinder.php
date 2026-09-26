<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\Market;
use App\Enums\MatchRule;
use App\Enums\MatchStatus;
use Illuminate\Support\Facades\DB;

/**
 * Find pairs of products that may be one and put them in the review queue.
 *
 * Three rules, run in order of how often they are right, so the most precise
 * rule claims a pair first (the queue is unique on the pair):
 *
 *  1. **Barcode**: offers in two products carry the same barcode. Happens when
 *     a barcode failed validation at ingest for one of them and it was grouped
 *     by title instead, or when an override split them (those are skipped).
 *  2. **Model number**: same brand and the same model number, from the title
 *     (ModelNumber::extract) or from a feed's own part number (`products.mpn`).
 *  3. **Similar title**: same brand and trigram similarity of the titles at or
 *     above `identity.matching.title_similarity`, using the trigram index on
 *     `product_groups.title` that search already has.
 *
 * Guards on every rule, beside "same market" (invariant 2):
 *
 *  - both products live: not merged away, and with offers;
 *  - never two barcode-keyed products. Two different valid barcodes are two
 *    products (very often two colours of one model), and a barcode is the one
 *    identity this site trusts outright. At least one side of every pair was
 *    grouped by title or split by hand;
 *  - rules 2 and 3 only within one brand, compared on a folded key so
 *    "Audio-Technica" and "Audio Technica" are one brand. Rule 1 does not ask:
 *    a shared barcode is stronger evidence than a feed's brand field;
 *  - rules 2 and 3 only when the numbers in the two titles agree (one
 *    title's numbers all appear in the other; ModelNumber::numbersAgree). On a
 *    copy of the catalogue this removed nearly every similar-title pair that
 *    was two neighbouring models ("Galaxy A27" beside "Galaxy A17").
 *
 * Nothing here merges. The owner has not set a bar for merging without a
 * person, so every pair waits for one; the review page shows each rule's
 * precision so that bar can be set from numbers later.
 */
class MatchFinder
{
    /**
     * The brand fold, in SQL: accents off, lower case, letters and digits only.
     * PHP's IdentityResolver folds the same way in spirit; here it only has to
     * put the same brand from two feeds side by side, and both sides of every
     * comparison go through this one expression.
     */
    private const BRAND_KEY = "regexp_replace(lower(unaccent(coalesce(%s, ''))), '[^a-z0-9]+', '', 'g')";

    /** @return array{barcode: int, model: int, title: int} */
    public function run(Market $market, bool $full = false): array
    {
        return [
            'barcode' => $this->byBarcode($market),
            'model' => $this->byModelNumber($market),
            'title' => $this->bySimilarTitle($market, $full),
        ];
    }

    /**
     * Each rule's record so far: how many pairs a person decided, and how many
     * of those were the same product. `manual` rows are left out, because no
     * rule proposed them.
     *
     * @return array<string, array{decided: int, merged: int, pending: int, precision: float|null}>
     */
    public function precision(): array
    {
        $rows = DB::table('match_candidates')
            ->selectRaw('rule, status, count(*) AS n')
            ->where('rule', '<>', MatchRule::Manual->value)
            ->groupBy('rule', 'status')
            ->get();

        $out = [];

        foreach ([MatchRule::Barcode, MatchRule::Model, MatchRule::Title] as $rule) {
            $count = fn (MatchStatus $s): int => (int) ($rows->first(fn ($r) => $r->rule === $rule->value && $r->status === $s->value)->n ?? 0);

            $merged = $count(MatchStatus::Merged);
            $decided = $merged + $count(MatchStatus::Rejected);

            $out[$rule->value] = [
                'decided' => $decided,
                'merged' => $merged,
                'pending' => $count(MatchStatus::Pending),
                'precision' => $decided > 0 ? $merged / $decided : null,
            ];
        }

        return $out;
    }

    private function byBarcode(Market $market): int
    {
        /*
         * Leading zeros off before comparing: a UPC-A and the same code as a
         * GTIN-13 differ only in them, and feeds send both.
         *
         * Offers with an override are left out: a person split them from the
         * rest on purpose, and they would otherwise be proposed straight back.
         */
        return DB::affectingStatement(<<<'SQL'
            WITH coded AS (
                SELECT ltrim(p.ean, '0') AS code, p.group_id
                FROM products p
                WHERE p.market = ?
                  AND p.ean IS NOT NULL
                  AND p.group_id IS NOT NULL
                  AND p.status = 'active'
                  AND NOT EXISTS (SELECT 1 FROM identity_overrides o WHERE o.product_id = p.id)
                GROUP BY 1, 2
            ),
            pairs AS (
                SELECT a.code, a.group_id AS ga, b.group_id AS gb
                FROM coded a
                JOIN coded b ON b.code = a.code AND a.group_id < b.group_id
                WHERE a.code <> ''
            )
            INSERT INTO match_candidates (market, group_a, group_b, rule, score, evidence, status, created_at, updated_at)
            SELECT DISTINCT ON (pairs.ga, pairs.gb)
                ?, pairs.ga, pairs.gb, 'barcode', similarity(g1.title, g2.title), left(pairs.code, 64), 'pending', now(), now()
            FROM pairs
            JOIN product_groups g1 ON g1.id = pairs.ga
            JOIN product_groups g2 ON g2.id = pairs.gb
            WHERE g1.merged_into_id IS NULL AND g2.merged_into_id IS NULL
              AND g1.offer_count > 0 AND g2.offer_count > 0
              AND (g1.identity_kind <> 'ean' OR g2.identity_kind <> 'ean')
            ORDER BY pairs.ga, pairs.gb, pairs.code
            ON CONFLICT (group_a, group_b) DO NOTHING
        SQL, [$market->value, $market->value]);
    }

    /**
     * Model numbers are extracted in PHP (ModelNumber is the tested rule) into
     * a temporary table, then paired in one statement. Memory stays flat
     * whatever the size of the market: groups are read in chunks by id and
     * never held all at once.
     */
    private function byModelNumber(Market $market): int
    {
        DB::statement('CREATE TEMPORARY TABLE IF NOT EXISTS match_models (group_id bigint NOT NULL, model varchar(64) NOT NULL)');
        DB::statement('TRUNCATE match_models');

        try {
            DB::table('product_groups')
                ->select(['id', 'title'])
                ->where('market', $market->value)
                ->whereNull('merged_into_id')
                ->where('offer_count', '>', 0)
                ->whereNotNull('brand')
                ->chunkById(2000, function ($groups): void {
                    $rows = [];

                    foreach ($groups as $group) {
                        foreach (ModelNumber::extract((string) $group->title) as $model) {
                            $rows[] = ['group_id' => $group->id, 'model' => substr($model, 0, 64)];
                        }
                    }

                    if ($rows !== []) {
                        DB::table('match_models')->insert($rows);
                    }
                });

            /*
             * The feed's own part numbers, normalised as ModelNumber::fromMpn()
             * does (upper case, letters and digits only, 4 to 40 long, at least
             * one digit, and not a barcode-shaped run of 8+ digits). In SQL
             * rather than PHP because there can be hundreds of thousands and
             * they never need to leave the database.
             */
            DB::statement(<<<'SQL'
                INSERT INTO match_models (group_id, model)
                SELECT DISTINCT p.group_id, m.model
                FROM products p
                CROSS JOIN LATERAL (SELECT upper(regexp_replace(p.mpn, '[^A-Za-z0-9]+', '', 'g')) AS model) m
                WHERE p.market = ?
                  AND p.mpn IS NOT NULL
                  AND p.group_id IS NOT NULL
                  AND p.status = 'active'
                  AND length(m.model) BETWEEN 4 AND 40
                  AND m.model ~ '[0-9]'
                  AND m.model !~ '^[0-9]{8,}$'
            SQL, [$market->value]);

            $brand = sprintf(self::BRAND_KEY, 'g.brand');

            $pairs = DB::select(<<<SQL
                WITH m AS (
                    SELECT DISTINCT mm.group_id, mm.model, {$brand} AS bk, g.identity_kind AS kind
                    FROM match_models mm
                    JOIN product_groups g ON g.id = mm.group_id
                    -- The part numbers come from offers, whose group may be
                    -- one merged away since; the titles were filtered already.
                    WHERE g.merged_into_id IS NULL AND g.offer_count > 0
                ),
                buckets AS (
                    SELECT bk, model FROM m
                    WHERE bk <> ''
                    GROUP BY bk, model
                    HAVING count(DISTINCT group_id) BETWEEN 2 AND ?
                )
                SELECT DISTINCT ON (a.group_id, b.group_id)
                    a.group_id AS ga, b.group_id AS gb, similarity(g1.title, g2.title) AS score, a.model AS evidence,
                    g1.title AS title_a, g2.title AS title_b
                FROM m a
                JOIN m b ON b.bk = a.bk AND b.model = a.model AND a.group_id < b.group_id
                JOIN buckets k ON k.bk = a.bk AND k.model = a.model
                JOIN product_groups g1 ON g1.id = a.group_id
                JOIN product_groups g2 ON g2.id = b.group_id
                WHERE (a.kind <> 'ean' OR b.kind <> 'ean')
                  AND NOT EXISTS (
                      SELECT 1 FROM match_candidates c WHERE c.group_a = a.group_id AND c.group_b = b.group_id
                  )
                ORDER BY a.group_id, b.group_id, a.model
            SQL, [(int) config('giftcoves.identity.matching.model_bucket_max')]);

            return $this->propose($market, MatchRule::Model, $pairs);
        } finally {
            DB::statement('DROP TABLE IF EXISTS match_models');
        }
    }

    /**
     * For each product, its closest few titles within the brand.
     *
     * `%` with `pg_trgm.similarity_threshold` set for this transaction only, so
     * the GIN trigram index on `product_groups.title` does the finding; search
     * sets its own thresholds and is not touched.
     *
     * Nightly, only products first seen in the last few days are looked up
     * from (against every product); a full pass over a whole market is minutes
     * of index lookups and is for a first run, via `bc:find-matches --full`.
     */
    private function bySimilarTitle(Market $market, bool $full): int
    {
        $threshold = (float) config('giftcoves.identity.matching.title_similarity');
        $partners = (int) config('giftcoves.identity.matching.title_partners');
        $since = now()->subDays((int) config('giftcoves.identity.matching.recent_days'));

        $brandA = sprintf(self::BRAND_KEY, 'a.brand');
        $brandB = sprintf(self::BRAND_KEY, 'b.brand');

        // In a full pass the non-barcode products are the starting side, which
        // reaches every allowed pair. Nightly, any new product starts, and the
        // pair guard inside keeps two barcode products apart.
        $scope = $full ? "AND a.identity_kind <> 'ean'" : 'AND a.first_seen_at >= ?';
        $bindings = $full ? [$partners * 4, $market->value] : [$partners * 4, $market->value, $since];

        $rows = DB::transaction(function () use ($threshold, $brandA, $brandB, $scope, $bindings): array {
            // SET cannot take a bound parameter; the value is a float from config.
            DB::statement('SET LOCAL pg_trgm.similarity_threshold = '.number_format($threshold, 3, '.', ''));

            return DB::select(<<<SQL
                SELECT a.id AS aid, c.id AS cid, c.s AS score, a.title AS title_a, c.title AS title_b
                FROM product_groups a
                CROSS JOIN LATERAL (
                    SELECT b.id, b.title, similarity(a.title, b.title) AS s
                    FROM product_groups b
                    WHERE b.title % a.title
                      AND b.market = a.market
                      AND b.id <> a.id
                      AND b.merged_into_id IS NULL
                      AND b.offer_count > 0
                      AND (a.identity_kind <> 'ean' OR b.identity_kind <> 'ean')
                      AND {$brandB} = {$brandA}
                    ORDER BY s DESC, b.id
                    LIMIT ?
                ) c
                WHERE a.market = ?
                  AND a.merged_into_id IS NULL
                  AND a.offer_count > 0
                  AND a.brand IS NOT NULL
                  {$scope}
                ORDER BY a.id, c.s DESC, c.id
            SQL, $bindings);
        });

        // The closest few per product among those whose numbers agree. Four
        // times as many are fetched as kept, because the closest titles of a
        // brand are very often its neighbouring models, which the number test
        // throws out; asking for exactly `$partners` would leave most
        // products with none.
        $pairs = [];
        $kept = [];

        foreach ($rows as $row) {
            if (($kept[$row->aid] ?? 0) >= $partners
                || ! ModelNumber::numbersAgree((string) $row->title_a, (string) $row->title_b)) {
                continue;
            }

            $kept[$row->aid] = ($kept[$row->aid] ?? 0) + 1;

            $pairs[] = (object) [
                'ga' => min($row->aid, $row->cid),
                'gb' => max($row->aid, $row->cid),
                'score' => $row->score,
                'evidence' => null,
                'title_a' => $row->title_a,
                'title_b' => $row->title_b,
            ];
        }

        return $this->propose($market, MatchRule::Title, $pairs);
    }

    /**
     * Write the pairs whose titles' numbers agree (ModelNumber::numbersAgree)
     * to the queue. A pair already there, pending or decided, is left as it
     * is: whichever rule found it first owns it, and a rejection stands.
     *
     * @param  iterable<object{ga: int, gb: int, score: float, evidence: ?string, title_a: string, title_b: string}>  $pairs
     */
    private function propose(Market $market, MatchRule $rule, iterable $pairs): int
    {
        $now = now();
        $rows = [];

        foreach ($pairs as $pair) {
            if (! ModelNumber::numbersAgree((string) $pair->title_a, (string) $pair->title_b)) {
                continue;
            }

            $rows[] = [
                'market' => $market->value,
                'group_a' => (int) $pair->ga,
                'group_b' => (int) $pair->gb,
                'rule' => $rule->value,
                'score' => (float) $pair->score,
                'evidence' => $pair->evidence === null ? null : substr((string) $pair->evidence, 0, 64),
                'status' => MatchStatus::Pending->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $written = 0;

        foreach (array_chunk($rows, 500) as $chunk) {
            $written += DB::table('match_candidates')->insertOrIgnore($chunk);
        }

        return $written;
    }
}

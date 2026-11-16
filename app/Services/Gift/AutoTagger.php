<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\Market;
use Illuminate\Support\Facades\DB;

/**
 * Interest tags for products nobody judged, learned from the ones somebody did
 * (owner, 2026-11-15: "implement automatic tagging where possible").
 *
 * ## Why
 *
 * The gift engine takes its candidates from tagged products where it can
 * ("tags first", docs/features/gift-tags.md). The judged tags covered the
 * catalogue of September 2026, about 343,000 products; by November some four
 * million giftable products had arrived since, untagged, and so could not be
 * a candidate for an interest any more. Judging four million products one by
 * one is not on; this fills the gap from what the judging already says.
 *
 * ## How
 *
 * No hand-written list of categories. Per market, a category earns a rule
 * when enough judged products sit in it (`min_products`, 20) and a clear
 * majority of them carry the same interest (`min_share`, 0.6): of the judged
 * products filed under "Kookboek", most are `interest:cooking` and
 * `interest:reading`, so an unjudged "Kookboek" gets both. A mixed category
 * ("Cadeaus", "Overige") clears no bar and tags nothing, which is the point:
 * where the judges did not agree, a rule would be a guess. Works in every
 * language the feeds use, because it never reads the category's words.
 *
 * Interests only. Who a product suits and for which occasion were judged per
 * product, and a category says too little about either.
 *
 * ## What it writes
 *
 * `product_groups.auto_tags`, never `gift_tags`: a judged tag stays what
 * somebody decided, and the engine weighs an automatic one lower
 * (`giftcoves.auto_tags.weight`). Only products nobody has judged
 * (`gift_tags` empty and `gift_tags_at` null); a product judged since loses
 * its automatic tags, and so does one whose category lost its rule.
 *
 * In ranges of ids, each its own statement, so a run over millions of rows
 * is a row of short writes rather than one long one (invariant 8).
 */
class AutoTagger
{
    /** Ids per statement. */
    private const RANGE = 100000;

    /** At most this many interests per category: the two the judges named most. */
    private const PER_CATEGORY = 2;

    /**
     * The categories that imply an interest, in this market.
     *
     * @return array<string, list<string>> category => interest tags, the most shared first
     */
    public function rules(Market $market): array
    {
        $rows = DB::select(
            <<<'SQL'
            WITH judged AS (
                SELECT id, category, gift_tags
                FROM product_groups
                WHERE market = ? AND category IS NOT NULL AND category <> '' AND gift_tags <> '[]'::jsonb
            ),
            totals AS (
                SELECT category, count(*) AS n FROM judged GROUP BY category HAVING count(*) >= ?
            ),
            shared AS (
                SELECT j.category, t.tag, count(*) AS c
                FROM judged j
                JOIN totals USING (category)
                CROSS JOIN LATERAL jsonb_array_elements_text(j.gift_tags) AS t(tag)
                WHERE t.tag LIKE 'interest:%'
                GROUP BY j.category, t.tag
            )
            SELECT shared.category, shared.tag, shared.c::float / totals.n AS share
            FROM shared JOIN totals USING (category)
            WHERE shared.c::float / totals.n >= ?
            ORDER BY shared.category, share DESC, shared.tag
            SQL,
            [$market->value, (int) config('giftcoves.auto_tags.min_products', 20), (float) config('giftcoves.auto_tags.min_share', 0.6)],
        );

        $rules = [];
        $skipped = array_map('mb_strtolower', (array) config('giftcoves.auto_tags.skip_categories', []));

        foreach ($rows as $row) {
            // Categories where the judged majority misleads; the list and the reasons are in the config.
            if (in_array(mb_strtolower((string) $row->category), $skipped, true)) {
                continue;
            }

            if (count($rules[$row->category] ?? []) < self::PER_CATEGORY) {
                $rules[$row->category][] = $row->tag;
            }
        }

        return $rules;
    }

    /**
     * Bring `auto_tags` in line with the rules.
     *
     * @return array{rules: int, tagged: int, cleared: int} products that would change, or did with $write
     */
    public function apply(Market $market, bool $write): array
    {
        $rules = $this->rules($market);
        $tagged = 0;
        $cleared = 0;

        // The rules as rows, so one statement can join them: (category, tags).
        $values = [];
        $bindings = [];

        foreach ($rules as $category => $tags) {
            $values[] = '(?::text, ?::jsonb)';
            $bindings[] = $category;
            $bindings[] = json_encode(array_values($tags), JSON_THROW_ON_ERROR);
        }

        $bounds = DB::selectOne('SELECT min(id) AS low, max(id) AS high FROM product_groups WHERE market = ?', [$market->value]);

        if ($bounds === null || $bounds->low === null) {
            return ['rules' => count($rules), 'tagged' => 0, 'cleared' => 0];
        }

        for ($from = (int) $bounds->low; $from <= (int) $bounds->high; $from += self::RANGE) {
            $range = [$market->value, $from, $from + self::RANGE - 1];

            if ($values !== []) {
                $rulesSql = 'VALUES '.implode(', ', $values);

                // Nobody judged it, its category has a rule, and it does not carry that rule's tags yet.
                $unjudged = 'g.market = ? AND g.id BETWEEN ? AND ? AND g.giftable'
                    ." AND g.gift_tags = '[]'::jsonb AND g.gift_tags_at IS NULL AND g.auto_tags IS DISTINCT FROM r.tags";

                $tagged += $write
                    ? DB::update(
                        "UPDATE product_groups g SET auto_tags = r.tags FROM ({$rulesSql}) AS r(category, tags) WHERE g.category = r.category AND {$unjudged}",
                        [...$bindings, ...$range],
                    )
                    : (int) DB::selectOne(
                        "SELECT count(*) AS n FROM product_groups g JOIN ({$rulesSql}) AS r(category, tags) ON g.category = r.category WHERE {$unjudged}",
                        [...$bindings, ...$range],
                    )->n;
            }

            // Judged since, no longer giftable, or its category has no rule any more.
            $stale = "g.market = ? AND g.id BETWEEN ? AND ? AND g.auto_tags <> '[]'::jsonb AND ("
                ."g.gift_tags <> '[]'::jsonb OR g.gift_tags_at IS NOT NULL OR NOT g.giftable OR g.category IS NULL"
                .($values === [] ? '' : " OR g.category NOT IN (SELECT r.category FROM ({$rulesSql}) AS r(category, tags))")
                .')';

            $cleared += $write
                ? DB::update("UPDATE product_groups g SET auto_tags = '[]'::jsonb WHERE {$stale}", [...$range, ...($values === [] ? [] : $bindings)])
                : (int) DB::selectOne("SELECT count(*) AS n FROM product_groups g WHERE {$stale}", [...$range, ...($values === [] ? [] : $bindings)])->n;
        }

        return ['rules' => count($rules), 'tagged' => $tagged, 'cleared' => $cleared];
    }
}

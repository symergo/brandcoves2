<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Models\ProductGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Retrieve → filter → score → diversify → explain.
 *
 * Four suggestions out of a catalogue of tens of thousands, in under 100 ms, on
 * a request that must never cost an AI call. Everything expensive or
 * non-deterministic happened earlier: giftability was classified after the last
 * ingest, and the interest → query map was widened overnight. This class is
 * pure retrieval and arithmetic.
 *
 * ## The stage that matters most is the last one
 *
 * Without diversification the top four are near-duplicates, because whatever
 * scores well scores well *for the same reasons* — four Bluetooth speakers at
 * four price points is a worse answer than a speaker, a cookbook, a plant pot
 * and a board game, even though the four speakers score higher individually.
 * Maximal Marginal Relevance is what turns a ranked list into a set of
 * suggestions. See {@see diversify()}.
 */
class GiftEngine
{
    /**
     * How many candidates to score.
     *
     * Enough that MMR has real choices to make; small enough that scoring stays
     * in the noise. Below roughly 150 the diversification stage runs out of
     * distinct categories to reach for and starts returning the duplicates it
     * exists to prevent.
     */
    private const CANDIDATE_POOL = 300;

    /** Angle queries per retrieval. Beyond this the tsquery stops being selective. */
    private const MAX_QUERIES = 24;

    public function __construct(private readonly AngleMap $angles) {}

    /** @return list<GiftPick> */
    public function suggest(GiftBrief $brief): array
    {
        $queries = array_slice(
            $this->angles->queriesFor($brief->market, $brief->interests, $brief->vibe),
            0,
            self::MAX_QUERIES,
        );

        $candidates = $this->retrieve($brief, $queries);

        if ($candidates->isEmpty() && $queries !== []) {
            // Nothing matched the interests. Falling back to a budget-and-vibe
            // browse is better than an empty page: the person told us who they
            // are shopping for, and "we found nothing" wastes that.
            $candidates = $this->retrieve($brief, []);
        }

        $scored = $candidates
            ->map(fn (ProductGroup $group) => $this->score($group, $brief, $queries))
            ->sortByDesc(fn (GiftPick $pick) => $pick->score)
            ->values();

        return $this->diversify($scored, $brief->limit);
    }

    /**
     * Candidate groups: giftable, presentable, in budget, not excluded.
     *
     * One query. The angle queries are folded into a single `websearch_to_tsquery`
     * with OR rather than a subquery per term — twenty EXISTS clauses against a
     * table this size is the difference between 40 ms and four seconds.
     *
     * @param  list<string>  $queries
     * @return Collection<int, ProductGroup>
     */
    private function retrieve(GiftBrief $brief, array $queries): Collection
    {
        $groups = ProductGroup::query()
            ->forMarket($brief->market)
            ->giftable()
            ->presentable()
            ->where('min_price', '>=', $brief->floor())
            ->where('min_price', '<=', $brief->ceiling());

        if ($brief->excludeGroupIds !== []) {
            $groups->whereNotIn('id', $brief->excludeGroupIds);
        }

        /*
         * "Avoid" is a hard filter, never a penalty.
         *
         * Someone who wrote "no alcohol" or "she is allergic to wool" is not
         * expressing a preference to be weighed against price — a single
         * violation makes the whole page untrustworthy. ILIKE rather than FTS
         * because the exclusion has to catch the word wherever it sits,
         * including inside a Dutch compound.
         */
        foreach ($brief->avoid as $avoid) {
            $avoid = trim($avoid);

            if ($avoid !== '') {
                $groups->where('title', 'not ilike', '%'.$this->escapeLike($avoid).'%');
            }
        }

        if ($queries !== []) {
            $tsquery = implode(' OR ', array_map(fn (string $q) => trim($q), $queries));

            $groups->whereExists(fn ($sub) => $sub
                ->select(DB::raw(1))
                ->from('products')
                ->whereColumn('products.group_id', 'product_groups.id')
                ->where('products.status', 'active')
                ->whereRaw(
                    'products.search_vector @@ websearch_to_tsquery(bc_text_config(products.market), ?)',
                    [$tsquery]
                ));
        }

        return $groups
            // Comparable products first: a suggestion the shopper can price
            // against a second shop is a suggestion they can act on.
            ->orderByDesc('merchant_count')
            ->orderByDesc('first_seen_at')
            ->limit(self::CANDIDATE_POOL)
            ->get();
    }

    /**
     * Weighted score out of 100, with every contribution recorded.
     *
     * @param  list<string>  $queries
     */
    private function score(ProductGroup $group, GiftBrief $brief, array $queries): GiftPick
    {
        $weights = (array) config('brandcoves.gift.weights');
        $haystack = mb_strtolower($group->title.' '.($group->category ?? ''));

        $matched = array_values(array_filter(
            $queries,
            fn (string $q) => str_contains($haystack, mb_strtolower($q)),
        ));

        $breakdown = [
            'interest_fit' => $this->interestFit($matched, $queries) * (float) ($weights['interest_fit'] ?? 40),
            'budget_fit' => $this->budgetFit($group, $brief) * (float) ($weights['budget_fit'] ?? 20),
            'surprise' => $this->surprise($group) * (float) ($weights['surprise'] ?? 20),
            'vibe' => $this->vibeFit($haystack, $brief) * (float) ($weights['vibe'] ?? 10),
            'values' => $this->valuesFit($haystack, $brief) * (float) ($weights['values'] ?? 10),
        ];

        return new GiftPick(
            group: $group,
            score: array_sum($breakdown),
            breakdown: $breakdown,
            matchedQueries: $matched,
            primaryInterest: $matched[0] ?? null,
        );
    }

    /**
     * How squarely this answers what the person likes.
     *
     * Weighted by *where* the matching query sat in the list, not just how many
     * matched: the angle map returns queries in the order the interests were
     * picked, and the first interest someone thinks of is the one that matters.
     * A product matching two low-priority queries should not beat one matching
     * the very first.
     *
     * @param  list<string>  $matched
     * @param  list<string>  $queries
     */
    private function interestFit(array $matched, array $queries): float
    {
        if ($queries === [] || $matched === []) {
            return 0.0;
        }

        $best = 0.0;

        foreach ($matched as $query) {
            $position = (int) array_search($query, $queries, true);
            $best = max($best, 1.0 - ($position / max(1, count($queries))));
        }

        // A second match is worth something, but far less than the first —
        // otherwise a product that name-drops five keywords wins on padding.
        $bonus = min(0.2, (count($matched) - 1) * 0.05);

        return min(1.0, $best + $bonus);
    }

    /**
     * Budget fit peaks below the ceiling, not at the cheapest option.
     *
     * A €12 gift against a €100 budget reads as thoughtless rather than
     * thrifty. The sweet spot sits at 85% of the stated maximum, and the score
     * falls away on both sides — so the engine spends the budget without
     * exceeding it.
     */
    private function budgetFit(ProductGroup $group, GiftBrief $brief): float
    {
        $price = $group->min_price;

        if ($price === null) {
            return 0.0;
        }

        $ceiling = max(1, $brief->ceiling());
        $sweetSpot = $ceiling * (float) config('brandcoves.gift.budget_sweet_spot');
        $ratio = $price / $sweetSpot;

        // Symmetric falloff around 1.0, clamped. Distance of 1.0 in either
        // direction scores zero, which puts a €10 item against a €100 budget at
        // roughly 0.12 — present, but not competitive.
        return max(0.0, 1.0 - abs(1.0 - $ratio));
    }

    /**
     * How unlikely the person is to have seen this already.
     *
     * Uses the precomputed `surprise_score` when Phase 5 has filled it in. Until
     * then, a deliberately crude proxy: something stocked by every shop is
     * something they have already been shown. It is a weak signal and it is
     * weighted as one — but a neutral constant would let the most-stocked
     * bestseller win every tie, which is exactly the failure this whole feature
     * exists to avoid.
     */
    private function surprise(ProductGroup $group): float
    {
        if ($group->surprise_score !== null) {
            return max(0.0, min(1.0, $group->surprise_score / 100));
        }

        return match (true) {
            $group->merchant_count >= 4 => 0.2,
            $group->merchant_count === 3 => 0.4,
            $group->merchant_count === 2 => 0.6,
            default => 0.7,
        };
    }

    /**
     * A nudge, never a filter.
     *
     * Someone who said "playful" still wants the good headphones if headphones
     * are the right answer; the vibe decides between two equally good ones.
     */
    private function vibeFit(string $haystack, GiftBrief $brief): float
    {
        if ($brief->vibe === null) {
            // No stated vibe is not a zero — it is "this signal does not
            // apply". Scoring it zero would silently shrink the total for
            // everyone who skipped the question.
            return 0.5;
        }

        foreach ($brief->vibe->keywords() as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return 1.0;
            }
        }

        return 0.3;
    }

    /** @var array<string, list<string>> */
    private const VALUE_MARKERS = [
        'sustainable' => ['duurzaam', 'gerecycled', 'recycled', 'bio', 'eco', 'fairtrade', 'fsc'],
        'local' => ['belgisch', 'nederlands', 'lokaal', 'made in belgium', 'local'],
        'handmade' => ['handgemaakt', 'handmade', 'artisanaal', 'ambachtelijk', 'fait main'],
    ];

    private function valuesFit(string $haystack, GiftBrief $brief): float
    {
        if ($brief->values === []) {
            return 0.5;
        }

        foreach ($brief->values as $value) {
            foreach (self::VALUE_MARKERS[$value] ?? [] as $marker) {
                if (str_contains($haystack, $marker)) {
                    return 1.0;
                }
            }
        }

        // Not a penalty worth much: feeds rarely label these even when true, so
        // absence is weak evidence of anything.
        return 0.4;
    }

    /**
     * Maximal Marginal Relevance.
     *
     * Greedy: take the best remaining candidate by
     * `λ·score − (1−λ)·maxSimilarityToAlreadyPicked`. λ = 0.65 favours relevance
     * while still breaking up clusters.
     *
     * This is the difference between a ranked list and a set of suggestions. It
     * is tested directly — the top four must be near-duplicates *without* it and
     * not *with* it, because a diversifier that quietly stops working looks
     * exactly like one that works.
     *
     * @param  Collection<int, GiftPick>  $scored
     * @return list<GiftPick>
     */
    private function diversify(Collection $scored, int $limit): array
    {
        $lambda = (float) config('brandcoves.gift.mmr_lambda');
        $pool = $scored->all();
        $picked = [];

        while (count($picked) < $limit && $pool !== []) {
            $bestIndex = null;
            $bestValue = -INF;

            foreach ($pool as $index => $candidate) {
                $penalty = 0.0;

                foreach ($picked as $chosen) {
                    $penalty = max($penalty, $this->similarity($candidate, $chosen));
                }

                // Scores are out of 100 and similarity is 0-1, so the penalty is
                // scaled to the same range or it would never bite.
                $value = ($lambda * $candidate->score) - ((1 - $lambda) * $penalty * 100);

                if ($value > $bestValue) {
                    $bestValue = $value;
                    $bestIndex = $index;
                }
            }

            if ($bestIndex === null) {
                break;
            }

            $picked[] = $pool[$bestIndex];
            unset($pool[$bestIndex]);
        }

        return $picked;
    }

    /**
     * How alike two suggestions are, 0 to 1.
     *
     * Category and brand dominate because they are what a person actually
     * notices — two headphones from different brands still read as "you showed
     * me headphones twice". Title overlap catches the rest, and matters most
     * where the feed's category field is empty, which is often.
     */
    private function similarity(GiftPick $a, GiftPick $b): float
    {
        $score = 0.0;

        if ($a->group->category !== null && $a->group->category === $b->group->category) {
            $score += 0.6;
        }

        if ($a->group->brand !== null && $a->group->brand === $b->group->brand) {
            $score += 0.2;
        }

        $score += 0.4 * $this->titleOverlap($a->group->title, $b->group->title);

        return min(1.0, $score);
    }

    private function titleOverlap(string $left, string $right): float
    {
        $tokenise = static function (string $text): array {
            $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            // Two-letter tokens are model numbers and noise words; they make
            // unrelated products look similar.
            return array_values(array_unique(array_filter($words, fn (string $w) => mb_strlen($w) > 2)));
        };

        $a = $tokenise($left);
        $b = $tokenise($right);

        if ($a === [] || $b === []) {
            return 0.0;
        }

        $shared = count(array_intersect($a, $b));

        // Jaccard, so a long title cannot look similar to everything just by
        // containing more words.
        return $shared / count(array_unique([...$a, ...$b]));
    }

    /** LIKE wildcards inside user text are literal characters, not operators. */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}

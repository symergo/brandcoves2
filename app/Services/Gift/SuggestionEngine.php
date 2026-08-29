<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Models\ProductGroup;
use App\Services\Charts\ChartDemand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Retrieve → filter → score → diversify → explain.
 *
 * One pipeline, two audiences. The brief may describe another person (the Gift
 * Whisperer) or the person holding the keyboard (building your own list), and
 * everything here is identical for both except the weights — which live in a
 * {@see SuggestionProfile} rather than in this class for that reason.
 *
 * The brief may also carry a typed query, in which case this is a *search
 * driven by a brief*: the angle queries and the person's own words retrieve
 * together, and the budget and `avoid` filters bind to both. That is what stops
 * "no alcohol" holding on the suggestions page and quietly not holding the
 * moment somebody uses the search box.
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
class SuggestionEngine
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

    /**
     * Share of the candidate pool reserved for products that are actually
     * selling.
     *
     * A correction, not a preference. The pool is ordered by `merchant_count`,
     * and a bestseller pulled from a retailer's chart is sold by that retailer
     * alone — so it sorts last and falls off the end of a 300-row pool, however
     * well it answers the brief. The things people demonstrably buy would be
     * systematically absent from gift suggestions, and nothing in the output
     * would show it. A sixth is enough to guarantee presence without crowding
     * out the comparable products the ordering exists to favour.
     */
    private const DEMAND_POOL_SHARE = 0.16;

    public function __construct(
        private readonly AngleMap $angles,
        private readonly ChartDemand $demand,
    ) {}

    /** @return list<Suggestion> */
    public function suggest(TasteBrief $brief): array
    {
        $profile = $brief->profile();

        $queries = array_slice(
            $this->queries($brief),
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
            ->map(fn (ProductGroup $group) => $this->score($group, $brief, $queries, $profile))
            ->sortByDesc(fn (Suggestion $pick) => $pick->score)
            ->values();

        return $this->diversify($scored, $brief->limit, $profile);
    }

    /**
     * What to retrieve on.
     *
     * A typed query goes **first**, ahead of every derived angle. Someone who
     * wrote "espresso tamper" has told us precisely what they want, and burying
     * that under a guess derived from "coffee" is the fastest way to make a
     * search box feel broken. Interest position already drives `interestFit()`,
     * so first place here is also the strongest scoring position.
     *
     * @return list<string>
     */
    private function queries(TasteBrief $brief): array
    {
        $angles = $this->angles->queriesFor($brief->market, $brief->interests, $brief->vibe);

        return $brief->query === null
            ? $angles
            : array_values(array_unique([$brief->query, ...$angles]));
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
    private function retrieve(TasteBrief $brief, array $queries): Collection
    {
        $pool = $this->pool($brief, $queries)
            ->orderByDesc('merchant_count')
            ->orderByDesc('first_seen_at')
            ->limit(self::CANDIDATE_POOL)
            ->get();

        return $this->withDemandCoverage($pool, $brief, $queries);
    }

    /**
     * The filters every candidate must pass, whichever ordering finds it.
     *
     * Extracted so the demand slice runs through exactly the same gauntlet as
     * the main pool. A second query with its own copy of these conditions is how
     * "no alcohol" ends up holding on one path and not the other.
     *
     * @param  list<string>  $queries
     * @return Builder<ProductGroup>
     */
    private function pool(TasteBrief $brief, array $queries)
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

            /*
             * The market is bound, not read from products.market.
             *
             * Same reason as in SearchService, where this cost a 90x difference
             * on the same rows: a tsquery built from the scanned row's own column
             * is not constant for the scan, so it cannot be an index condition
             * and Postgres falls back to parsing a fresh tsquery per row. The
             * pool is already `forMarket($brief->market)` and offers only ever
             * join a group in their own market (invariant 2), so binding it
             * selects exactly the same rows — and it doubles as the explicit
             * filter that makes that reasoning checkable.
             */
            $groups->whereExists(fn ($sub) => $sub
                ->select(DB::raw(1))
                ->from('products')
                ->whereColumn('products.group_id', 'product_groups.id')
                ->where('products.market', $brief->market->value)
                ->where('products.status', 'active')
                ->whereRaw(
                    'products.search_vector @@ websearch_to_tsquery(bc_text_config(?), ?)',
                    [$brief->market->value, $tsquery]
                ));
        }

        // Comparable products first: a suggestion the shopper can price against
        // a second shop is a suggestion they can act on. Applied by the caller,
        // because the demand slice orders itself differently.
        return $groups;
    }

    /**
     * Top up the pool with products that are actually selling.
     *
     * Runs only when the main pool is full — a short pool has already returned
     * everything that matches, so there is nothing to have been crowded out. The
     * slice passes the identical filters and is deduplicated against what is
     * already there, so this can add candidates and can never replace or reorder
     * them.
     *
     * Scoring is untouched by this. A chart product that reaches the pool still
     * has to earn its place on interest, budget and vibe like everything else.
     *
     * @param  Collection<int, ProductGroup>  $pool
     * @param  list<string>  $queries
     * @return Collection<int, ProductGroup>
     */
    private function withDemandCoverage(Collection $pool, TasteBrief $brief, array $queries): Collection
    {
        if ($pool->count() < self::CANDIDATE_POOL) {
            return $pool;
        }

        $slice = (int) round(self::CANDIDATE_POOL * self::DEMAND_POOL_SHARE);

        $chartIds = $this->demand->topGroupIds($brief->market, self::CANDIDATE_POOL);

        if ($chartIds === [] || $slice < 1) {
            return $pool;
        }

        $missing = array_values(array_diff($chartIds, $pool->pluck('id')->all()));

        if ($missing === []) {
            return $pool;
        }

        $extra = $this->pool($brief, $queries)
            ->whereIn('id', array_slice($missing, 0, $slice))
            ->get();

        return $pool->concat($extra);
    }

    /**
     * Weighted score out of 100, with every contribution recorded.
     *
     * @param  list<string>  $queries
     */
    private function score(ProductGroup $group, TasteBrief $brief, array $queries, SuggestionProfile $profile): Suggestion
    {
        $haystack = mb_strtolower($group->title.' '.($group->category ?? ''));

        $matched = array_values(array_filter(
            $queries,
            fn (string $q) => str_contains($haystack, mb_strtolower($q)),
        ));

        $breakdown = [
            'interest_fit' => $this->interestFit($matched, $queries) * $profile->weight('interest_fit', 40),
            'budget_fit' => $profile->budgetFit($group->min_price, $brief->ceiling()) * $profile->weight('budget_fit', 20),
            'surprise' => $this->surprise($group) * $profile->weight('surprise', 20),
            'vibe' => $this->vibeFit($haystack, $brief) * $profile->weight('vibe', 10),
            'values' => $this->valuesFit($haystack, $brief) * $profile->weight('values', 10),
            'occasion' => $this->occasionFit($haystack, $brief) * $profile->weight('occasion', 0),
            /*
             * Zero by default, and zero for `for_someone` on purpose.
             *
             * Buying for another person is the case `surprise()` exists for —
             * "something stocked by every shop is something they have already
             * been shown". Rewarding demand here would pull directly against
             * that and turn the Whisperer into a chart. Your own wishlist is the
             * opposite question: nobody wants a surprising kettle, they want the
             * good one, so `for_myself` carries a small weight.
             *
             * This is the difference SuggestionProfile exists to hold.
             */
            'demand' => $this->demand->score($brief->market, $group->id) * $profile->weight('demand', 0),
        ];

        return new Suggestion(
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
    private function vibeFit(string $haystack, TasteBrief $brief): float
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

    /**
     * Words a product carries when it suits a particular occasion.
     *
     * Occasions are free text — the wizard offers a few and accepts anything —
     * so this is keyed on the ones people actually type, matched as substrings
     * for the same reason the giftability classifier is: Dutch and German write
     * compounds closed, and `\bkerst\b` matches none of `kerstcadeau`,
     * `kerstpakket` or `Weihnachtsgeschenk`.
     *
     * @var array<string, list<string>>
     */
    private const OCCASION_MARKERS = [
        'birthday' => ['verjaardag', 'birthday', 'anniversaire', 'cumpleanos'],
        'christmas' => ['kerst', 'christmas', 'noel', 'navidad', 'weihnacht', 'sinterklaas'],
        'wedding' => ['bruiloft', 'huwelijk', 'wedding', 'mariage', 'boda'],
        'newborn' => ['baby', 'geboorte', 'newborn', 'naissance', 'kraamcadeau'],
        'housewarming' => ['housewarming', 'nieuwe woning', 'inhuizing', 'hogar'],
        'anniversary' => ['jubileum', 'anniversary', 'aniversario'],
        'thanks' => ['bedankt', 'thank', 'merci', 'gracias'],

        /*
         * The keys `EventType` uses, so the two vocabularies agree.
         *
         * They are still two systems — this matches `recipients.occasion`,
         * which is free text, while `EventType` sits on the list — and that is
         * precisely why the overlap is written out. An occasion typed on one
         * side and chosen on the other should score the same, and `baby` /
         * `newborn` and `thank_you` / `thanks` are the same occasion under two
         * spellings. An unlisted key falls through to matching itself, which is
         * weak rather than broken, so this is about quality and not correctness.
         */
        'baby' => ['baby', 'geboorte', 'newborn', 'naissance', 'kraamcadeau'],
        'thank_you' => ['bedankt', 'thank', 'merci', 'gracias'],
        'graduation' => ['geslaagd', 'diploma', 'graduation', 'abschluss', 'graduacion'],
        'retirement' => ['pensioen', 'retirement', 'retraite', 'jubilacion'],
        'farewell' => ['afscheid', 'farewell', 'leaving', 'despedida'],
        'valentines' => ['valentijn', 'valentine', 'valentin'],
        'mothers_day' => ['moederdag', 'mother', 'maman', 'madre'],
        'fathers_day' => ['vaderdag', 'father', 'papa', 'padre'],
    ];

    /**
     * Does this product suit the occasion?
     *
     * Weighted at **zero by default**, and that is the point rather than an
     * oversight. `occasion` was collected by the wizard, carried through the
     * brief and read by nothing for the whole of Phase 4 — a field that looks
     * like an input and does nothing is worse than an absent one, because the
     * person answering believes it changed the result.
     *
     * So it is scored, and given a weight only where it earns one. A profile can
     * raise it; the catalogue is currently too thin in seasonal goods for it to
     * carry real weight without becoming noise, and claiming otherwise would be
     * the "plausible wrong answer" failure the discovery docs warn about.
     */
    private function occasionFit(string $haystack, TasteBrief $brief): float
    {
        if ($brief->occasion === null || $brief->occasion === '') {
            // An unanswered question scores 0.5, not 0 — "does not apply" is not
            // "scores badly", and every step after the first is skippable.
            return 0.5;
        }

        $occasion = mb_strtolower($brief->occasion);
        $markers = self::OCCASION_MARKERS[$occasion] ?? [$occasion];

        foreach ($markers as $marker) {
            if (str_contains($haystack, $marker)) {
                return 1.0;
            }
        }

        // Absence is weak evidence: most good presents are not labelled with the
        // occasion they suit, and a real penalty would rank the whole catalogue
        // below a handful of novelty items with "kerst" in the title.
        return 0.45;
    }

    /** @var array<string, list<string>> */
    private const VALUE_MARKERS = [
        'sustainable' => ['duurzaam', 'gerecycled', 'recycled', 'bio', 'eco', 'fairtrade', 'fsc'],
        'local' => ['belgisch', 'nederlands', 'lokaal', 'made in belgium', 'local'],
        'handmade' => ['handgemaakt', 'handmade', 'artisanaal', 'ambachtelijk', 'fait main'],
    ];

    private function valuesFit(string $haystack, TasteBrief $brief): float
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
     * @param  Collection<int, Suggestion>  $scored
     * @return list<Suggestion>
     */
    private function diversify(Collection $scored, int $limit, SuggestionProfile $profile): array
    {
        $lambda = $profile->mmrLambda;
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
    private function similarity(Suggestion $a, Suggestion $b): float
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

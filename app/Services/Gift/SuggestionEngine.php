<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\Interest;
use App\Enums\Preference;
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

        $slots = $this->slots($brief);
        $queries = $this->flatten($slots);

        $candidates = $this->retrieve($brief, $queries);

        if ($candidates->isEmpty() && $queries !== []) {
            // Nothing matched the interests. Falling back to a budget-and-vibe
            // browse is better than an empty page: the person told us who they
            // are shopping for, and "we found nothing" wastes that.
            $candidates = $this->retrieve($brief, []);
        }

        $matches = $this->matches($brief, $queries, $candidates->pluck('id')->all());

        $scored = $candidates
            ->map(fn (ProductGroup $group) => $this->score(
                $group,
                $brief,
                $slots,
                $matches[$group->id] ?? [],
                $profile,
            ))
            ->sortByDesc(fn (Suggestion $pick) => $pick->score)
            ->values();

        return $this->diversify($scored, $brief->limit, $profile);
    }

    /**
     * What to retrieve on, grouped by what it answers.
     *
     * A typed query goes **first**, as a slot of its own ahead of every
     * interest. Someone who wrote "espresso tamper" has told us precisely what
     * they want, and burying that under a guess derived from "coffee" is the
     * fastest way to make a search box feel broken. Slot position drives
     * `interestFit()`, so first place here is also the strongest scoring
     * position.
     *
     * Capped at MAX_QUERIES in slot order, so a brief with eight interests
     * still retrieves on the first ones the person thought of.
     *
     * @return list<array{interest: string, queries: list<string>}>
     */
    private function slots(TasteBrief $brief): array
    {
        $slots = $this->angles->queriesByInterest($brief->market, $brief->interests, $brief->vibe);

        if ($brief->query !== null) {
            $typed = $brief->query;

            $slots = array_values(array_filter(array_map(
                fn (array $slot) => [
                    'interest' => $slot['interest'],
                    'queries' => array_values(array_filter($slot['queries'], fn (string $q) => $q !== $typed)),
                ],
                $slots,
            ), fn (array $slot) => $slot['queries'] !== []));

            array_unshift($slots, ['interest' => $typed, 'queries' => [$typed]]);
        }

        $budget = self::MAX_QUERIES;
        $capped = [];

        foreach ($slots as $slot) {
            if ($budget <= 0) {
                break;
            }

            $queries = array_slice($slot['queries'], 0, $budget);
            $budget -= count($queries);
            $capped[] = ['interest' => $slot['interest'], 'queries' => $queries];
        }

        return $capped;
    }

    /**
     * @param  list<array{interest: string, queries: list<string>}>  $slots
     * @return list<string>
     */
    private function flatten(array $slots): array
    {
        return array_merge([], ...array_map(fn (array $slot) => $slot['queries'], $slots));
    }

    /**
     * Which queries each candidate answers, and how squarely.
     *
     * Asked of Postgres rather than decided in PHP, and that is the fix for a
     * bug that was invisible in every test and visible on every brief with two
     * interests. Retrieval matches on stems — "schilderen" finds a product
     * whose title says "schilder" — but the scorer used to look for the query
     * text literally in the title, so the products the search had just found
     * for the first interest scored **zero** on interest fit, while a product
     * whose title happened to spell a second-interest query out in full
     * ("bluetooth speaker") scored top marks. The scorer now credits exactly
     * what the search matched, by the same tsquery against the same vector.
     *
     * Two strengths. A match in the title, brand or category (weights A-C) is
     * 1.0; a match only in the description (weight D) is 0.5, because a games
     * console whose blurb mentions painting is not a painting present, and
     * the description is where feeds put everything they could think of.
     *
     * One query over the candidate ids and the terms, tsqueries parsed once in
     * a CTE; 300 groups × 24 terms is a few milliseconds.
     *
     * @param  list<string>  $queries
     * @param  list<int>  $ids
     * @return array<int, array<int, float>> group id => [query index => strength]
     */
    private function matches(TasteBrief $brief, array $queries, array $ids): array
    {
        if ($queries === [] || $ids === []) {
            return [];
        }

        $rows = DB::select(
            <<<'SQL'
            WITH q AS (
                SELECT t.idx, websearch_to_tsquery(bc_text_config(?), t.term) AS tsq
                FROM unnest(?::text[]) WITH ORDINALITY AS t(term, idx)
            )
            SELECT p.group_id, q.idx,
                   bool_or(ts_filter(p.search_vector, '{a,b,c}') @@ q.tsq) AS strong
            FROM products p
            JOIN q ON p.search_vector @@ q.tsq
            WHERE p.market = ?
              AND p.status = 'active'
              AND p.group_id = ANY(?::bigint[])
            GROUP BY p.group_id, q.idx
            SQL,
            [
                $brief->market->value,
                $this->pgTextArray($queries),
                $brief->market->value,
                '{'.implode(',', array_map('intval', $ids)).'}',
            ],
        );

        $matches = [];

        foreach ($rows as $row) {
            // WITH ORDINALITY counts from one; the query list from zero.
            $matches[(int) $row->group_id][(int) $row->idx - 1] = $row->strong ? 1.0 : 0.5;
        }

        return $matches;
    }

    /** A Postgres text[] literal, every element quoted so commas and quotes in user text survive. */
    private function pgTextArray(array $values): string
    {
        return '{'.implode(',', array_map(
            fn (string $v) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $v).'"',
            $values,
        )).'}';
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
             * The tags an editor gave a product are the other way in.
             *
             * "coffee" retrieves on the angle queries, and it also retrieves
             * anything tagged `interest:coffee` — a product an editor decided
             * is a coffee present, whatever its title says. Retrieval is an
             * OR of the two because a tag is an editor's decision and a text
             * match is a guess, and the decision must not need the guess to
             * agree. Both branches are indexed: the tsquery on the offers'
             * vector, the tags on their GIN index (`?|`, spelled as the
             * function because a bare `?` is a placeholder to PDO).
             */
            $tags = array_map(
                fn (string $interest) => GiftTags::interest($interest),
                array_values(array_filter($brief->interests, fn (string $i) => Interest::tryFrom(mb_strtolower(trim($i))) !== null)),
            );

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
            $groups->where(function ($either) use ($brief, $tsquery, $tags): void {
                $either->whereExists(fn ($sub) => $sub
                    ->select(DB::raw(1))
                    ->from('products')
                    ->whereColumn('products.group_id', 'product_groups.id')
                    ->where('products.market', $brief->market->value)
                    ->where('products.status', 'active')
                    ->whereRaw(
                        'products.search_vector @@ websearch_to_tsquery(bc_text_config(?), ?)',
                        [$brief->market->value, $tsquery]
                    ));

                if ($tags !== []) {
                    $either->orWhereRaw('jsonb_exists_any(product_groups.gift_tags, ?::text[])', [$this->pgTextArray($tags)]);
                }
            });
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
     * @param  list<array{interest: string, queries: list<string>}>  $slots
     * @param  array<int, float>  $matches  query index => strength, from {@see matches()}
     */
    private function score(ProductGroup $group, TasteBrief $brief, array $slots, array $matches, SuggestionProfile $profile): Suggestion
    {
        $haystack = mb_strtolower($group->title.' '.($group->category ?? ''));

        // The strongest match per slot, and the terms that matched, in slot
        // order — so `primaryInterest` is a term from the interest that won.
        $strengths = [];
        $matched = [];
        $interests = [];
        $index = 0;

        $tags = $group->giftTags();

        foreach ($slots as $slotIndex => $slot) {
            /*
             * An editor's tag is a match at full strength, ahead of any
             * text: `interest:coffee` on the product and "coffee" in the
             * brief is the strongest evidence this engine ever gets, and it
             * needs no word in the title to agree.
             */
            if (in_array(GiftTags::interest($slot['interest']), $tags, true)) {
                $strengths[$slotIndex] = 1.0;
                $matched[] = $slot['interest'];
            }

            foreach ($slot['queries'] as $query) {
                if (isset($matches[$index])) {
                    $strengths[$slotIndex] = max($strengths[$slotIndex] ?? 0.0, $matches[$index]);
                    $matched[] = $query;
                }

                $index++;
            }

            // The interest itself, not the angle query that found it: the card
            // says "cooking", never "cast iron pan". A typed query is its own
            // slot and names no interest, so it drops out here.
            if (isset($strengths[$slotIndex]) && $slot['interest'] !== '') {
                $interests[] = $slot['interest'];
            }
        }

        $breakdown = [
            'interest_fit' => $this->interestFit($strengths, count($slots)) * $profile->weight('interest_fit', 40),
            'budget_fit' => $profile->budgetFit($group->min_price, $brief->ceiling()) * $profile->weight('budget_fit', 20),
            'surprise' => $this->surprise($group) * $profile->weight('surprise', 20),
            'vibe' => $this->vibeFit($haystack, $brief, $tags) * $profile->weight('vibe', 10),
            /*
             * Which way their taste goes, which the vibe cannot say.
             *
             * Five, half of vibe: a person who asks for "vintage" means it,
             * but they would still rather have the right kind of present in
             * the wrong finish than the wrong present in the right one.
             */
            'preference' => $this->preferenceFit($haystack, $brief, $tags) * $profile->weight('preference', 5),
            'values' => $this->valuesFit($haystack, $brief, $tags) * $profile->weight('values', 10),
            /*
             * Five since 2026-09-14, from zero. It was zero because the
             * catalogue was too thin in seasonal goods for title words to
             * carry weight; an editor's `occasion:` tag is not a title word.
             * The text fallback rides along at the same weight, which is
             * small enough that "kerst" in a novelty title cannot outrank a
             * real present.
             */
            'occasion' => $this->occasionFit($haystack, $brief, $tags) * $profile->weight('occasion', 0),
            /*
             * Who the present is for, from an editor's tag only.
             *
             * `recipient:mother` on a product and "mother" in the brief is a
             * fact about the present nothing in a title can say, so there is
             * no text fallback here. Small: it decides between two good
             * answers, it does not choose one. Zero for `for_myself`, where
             * there is no other person to be for.
             */
            'recipient_fit' => $this->recipientFit($brief, $tags) * $profile->weight('recipient_fit', 0),
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
            matchedInterests: array_values(array_unique($interests)),
            matchedTastes: $this->matchedTastes($haystack, $brief, $tags),
        );
    }

    /**
     * How squarely this answers what the person likes: half the best single
     * interest it answers, half how much of the whole brief it covers.
     *
     * Each interest is a slot with a weight: the first interest someone
     * thinks of is worth 1.0, the last 0.5, spread evenly between. Under the
     * old per-query position, "schilderen" followed by "techniek" put the
     * first tech query at 0.94 of the painting one — close enough for a
     * speaker at the budget's sweet spot to beat every paint set on price
     * alone. At 0.5 the second interest is a real second.
     *
     * The blend is the owner's ask (2026-09-14) that matching be vectorial: a
     * product answering three of four interests should beat one answering
     * only the first, and until now it did not — the best slot decided and
     * every extra match added a fixed crumb. Coverage is the weighted share of
     * the brief's slots the product answers, so with four interests (weights
     * 1.0, 0.83, 0.67, 0.5) a product answering the first alone scores
     * 0.5·1.0 + 0.5·0.33 = 0.67, one answering the first two 0.81, one
     * answering the last three 0.75, and one answering all four 1.0: more of
     * the brief wins, and the first interest still weighs most among equals.
     * A single-interest brief is unchanged at 1.0.
     *
     * The strength per slot is the match quality: 1.0 for an editor's tag or
     * a title, brand or category match, 0.5 for a description-only one.
     *
     * @param  array<int, float>  $strengths  slot index => strongest match
     */
    private function interestFit(array $strengths, int $slotCount): float
    {
        if ($slotCount === 0 || $strengths === []) {
            return 0.0;
        }

        $weight = fn (int $slotIndex): float => $slotCount === 1 ? 1.0 : 1.0 - (0.5 * $slotIndex / ($slotCount - 1));

        $best = 0.0;
        $covered = 0.0;
        $total = 0.0;

        for ($i = 0; $i < $slotCount; $i++) {
            $total += $weight($i);

            if (isset($strengths[$i])) {
                $scored = $weight($i) * $strengths[$i];
                $best = max($best, $scored);
                $covered += $scored;
            }
        }

        return min(1.0, 0.5 * $best + 0.5 * ($covered / $total));
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
    private function vibeFit(string $haystack, TasteBrief $brief, array $tags = []): float
    {
        if ($brief->vibe === null) {
            // No stated vibe is not a zero — it is "this signal does not
            // apply". Scoring it zero would silently shrink the total for
            // everyone who skipped the question.
            return 0.5;
        }

        // An editor said how it feels; no need to find "luxe" in the title.
        if (in_array(GiftTags::vibe($brief->vibe->value), $tags, true)) {
            return 1.0;
        }

        foreach ($brief->vibe->keywords() as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return 1.0;
            }
        }

        return 0.3;
    }

    /**
     * The taste the brief named that this product actually sits at.
     *
     * The same three questions {@see vibeFit()}, {@see preferenceFit()} and
     * {@see valuesFit()} score, asked one pole at a time so the card can name
     * which one landed rather than report that something did. An editor's tag
     * counts first and a title word second, the order of trust those three
     * use. A pole the brief did not ask for is never reported: the card is
     * about the overlap, not about the product.
     *
     * @param  list<string>  $tags
     * @return list<array{kind: string, value: string}>
     */
    private function matchedTastes(string $haystack, TasteBrief $brief, array $tags): array
    {
        $fits = [];

        if ($brief->vibe !== null && $this->vibeFit($haystack, $brief, $tags) >= 1.0) {
            $fits[] = ['kind' => 'vibe', 'value' => $brief->vibe->value];
        }

        foreach ($brief->preferences as $pole) {
            $keywords = Preference::tryFrom($pole)?->keywords() ?? [];

            if (in_array(GiftTags::preference($pole), $tags, true) || $this->mentions($haystack, $keywords)) {
                $fits[] = ['kind' => 'preference', 'value' => $pole];
            }
        }

        foreach ($brief->values as $value) {
            if (in_array(GiftTags::value($value), $tags, true)
                || $this->mentions($haystack, self::VALUE_MARKERS[$value] ?? [])) {
                $fits[] = ['kind' => 'values', 'value' => $value];
            }
        }

        return $fits;
    }

    /** @param list<string> $needles */
    private function mentions(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * How close a product is to the taste the brief described.
     *
     * Several poles may be asked for and any one of them matching is a
     * match: they are one taste, not a list of requirements. An editor's
     * `preference:` tag beats a title word, the same order of trust
     * {@see valuesFit()} uses and for the same reason — a feed says "eiken"
     * by accident and an editor says `preference:natural` on purpose.
     *
     * The opposite pole is not scored against the product. A person who
     * asked for "cosy" and is shown something sleek has been shown a present
     * that is merely not what they said, and the rest of the brief is a
     * better judge of it than this signal is.
     *
     * @param  list<string>  $tags
     */
    private function preferenceFit(string $haystack, TasteBrief $brief, array $tags = []): float
    {
        if ($brief->preferences === []) {
            // Unasked is not unmet. Same neutral 0.5 every skipped question
            // scores, so a brief that leaves this out is not quietly ranked
            // against one that answered it.
            return 0.5;
        }

        foreach ($brief->preferences as $preference) {
            if (in_array(GiftTags::preference($preference), $tags, true)) {
                return 1.0;
            }

            foreach ((Preference::tryFrom($preference)?->keywords() ?? []) as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    return 1.0;
                }
            }
        }

        // Weak evidence, like values: most feeds never describe a look at
        // all, so silence is not a "no".
        return 0.4;
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
    private function occasionFit(string $haystack, TasteBrief $brief, array $tags = []): float
    {
        if ($brief->occasion === null || $brief->occasion === '') {
            // An unanswered question scores 0.5, not 0 — "does not apply" is not
            // "scores badly", and every step after the first is skippable.
            return 0.5;
        }

        $occasion = mb_strtolower($brief->occasion);

        // An editor said so; no need to find the word in the title.
        if (in_array(GiftTags::occasion($occasion), $tags, true)) {
            return 1.0;
        }

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

    /**
     * Whether an editor tagged this product for the person the brief
     * describes: who they are to the giver, and how old they are.
     *
     * The relationship is free text, folded to lower case and compared as
     * it is: "mother" meets `recipient:mother`, "my mum" meets nothing. The
     * age band is one of the fixed groups the wizard offers, the same
     * strings an editor tags with, so "13-17" meets `age:13-17` and a stored
     * value from before the groups existed meets nothing. Nothing recognised
     * scores neutral rather than badly, the same rule every skipped question
     * follows. One signal for the two because they answer one
     * question, "is this for them", and a product tagged for a teenager
     * given to a teenager is as right as one tagged for a mother given to a
     * mother.
     *
     * @param  list<string>  $tags
     */
    private function recipientFit(TasteBrief $brief, array $tags): float
    {
        $asked = [];

        if ($brief->relationship !== null && trim($brief->relationship) !== '') {
            $asked[GiftTags::RECIPIENT] = GiftTags::recipient($brief->relationship);
        }

        if ($brief->ageBand !== null && in_array($brief->ageBand, GiftTags::AGE_BANDS, true)) {
            $asked[GiftTags::AGE] = GiftTags::age($brief->ageBand);
        }

        if ($asked === []) {
            return 0.5;
        }

        foreach ($asked as $tag) {
            if (in_array($tag, $tags, true)) {
                return 1.0;
            }
        }

        // A product tagged for somebody else, or some other age, is weak
        // evidence against; an untagged one is no evidence at all.
        foreach (array_keys($asked) as $vocabulary) {
            if (array_filter($tags, fn (string $t) => str_starts_with($t, $vocabulary.':')) !== []) {
                return 0.45;
            }
        }

        return 0.5;
    }

    /** @var array<string, list<string>> */
    private const VALUE_MARKERS = [
        'sustainable' => ['duurzaam', 'gerecycled', 'recycled', 'bio', 'eco', 'fairtrade', 'fsc'],
        'local' => ['belgisch', 'nederlands', 'lokaal', 'made in belgium', 'local'],
        'handmade' => ['handgemaakt', 'handmade', 'artisanaal', 'ambachtelijk', 'fait main'],
    ];

    private function valuesFit(string $haystack, TasteBrief $brief, array $tags = []): float
    {
        if ($brief->values === []) {
            return 0.5;
        }

        foreach ($brief->values as $value) {
            // An editor's tag beats a title word: feeds rarely say
            // "handgemaakt" even when it is true.
            if (in_array(GiftTags::value($value), $tags, true)) {
                return 1.0;
            }

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
     * `λ·score − (1−λ)·maxPenalty`. λ = 0.65 favours relevance while still
     * breaking up clusters.
     *
     * ## The share, which runs before the penalty
     *
     * Similarity spreads a board across *categories*, which is not the same
     * as spreading it across what the person told us. Someone who said
     * "painting and cycling" can be shown a brush, an easel, a palette knife
     * and a canvas — four categories, one interest, and half the brief
     * ignored (owner's report, 2026-09-14). Scaling the penalty could not fix
     * that: two products of the same interest are already near-identical to
     * the similarity term, so the interest a board opens on keeps winning by
     * raw score long after it has said everything it has to say.
     *
     * So each interest gets a share of the board — `limit ÷ interests with
     * candidates`, rounded up — and while any interest is still under its
     * share, only those are eligible. When every interest has had its share,
     * or nothing eligible is left, the whole pool opens again and the ranking
     * finishes the board. An interest with two good products and a share of
     * four therefore gives its spare seats back rather than holding them.
     *
     * This is the difference between a ranked list and a set of suggestions. It
     * is tested directly — the top picks must be near-duplicates *without* it
     * and not *with* it, because a diversifier that quietly stops working looks
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

        // The fair share of the board per interest, counted over the
        // interests that actually have candidates rather than the ones that
        // were asked for: an interest the catalogue cannot answer should not
        // reserve seats nothing can fill.
        $interests = $scored
            ->map(fn (Suggestion $pick) => $this->interestOf($pick))
            ->filter()
            ->unique()
            ->count();
        $share = max(1, (int) ceil($limit / max(1, $interests)));

        /** @var array<string, int> $taken interest => picks so far */
        $taken = [];

        while (count($picked) < $limit && $pool !== []) {
            $bestIndex = null;
            $bestValue = -INF;

            // Under-share interests first; if none of them can still field a
            // candidate, everything is eligible again.
            $eligible = array_filter(
                $pool,
                fn (Suggestion $candidate) => $this->interestOf($candidate) !== null
                    && ($taken[$this->interestOf($candidate)] ?? 0) < $share,
            );

            foreach (($eligible !== [] ? $eligible : $pool) as $index => $candidate) {
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
            $interest = $this->interestOf($pool[$bestIndex]);

            if ($interest !== null) {
                $taken[$interest] = ($taken[$interest] ?? 0) + 1;
            }

            unset($pool[$bestIndex]);
        }

        return $picked;
    }

    /**
     * The interest a suggestion counts against its share.
     *
     * `primaryInterest` is the first *term* that matched, which is the
     * interest's name only when an editor tagged the product and the angle
     * query the text matched otherwise — "airfryer", "powerbank". Sharing the
     * board out over those spreads it across queries and leaves the interests
     * as lopsided as before, which is exactly what it looked like on staging
     * before this was fixed: seven of eight from one interest. The slot is
     * the thing the person actually named.
     */
    private function interestOf(Suggestion $pick): ?string
    {
        return $pick->matchedInterests[0] ?? null;
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

<?php

declare(strict_types=1);

namespace App\Services\Discover\Retrievers;

use App\Models\PopularRank;
use App\Models\ProductGroup;
use App\Services\Discover\Candidate;
use App\Services\Discover\DiscoveryRequest;
use Illuminate\Support\Facades\DB;

/**
 * What people are actually buying, and what is climbing.
 *
 * The first demand signal in this codebase that measures demand. Everything else
 * approximates it: `fresh` reads how recently a product appeared and how many
 * shops picked it up, `curated` reads what an editor or a job already chose. A
 * retailer's bestseller chart is the real quantity, measured by someone with
 * millions of transactions, and `popular_ranks` is our record of it.
 *
 * ## Movement is the signal, not position
 *
 * A permanent number one is popular and is not *news*. A product that went from
 * #40 to #6 in a week is what "what's current" actually means, and it is the
 * reason rank history is stored at all rather than a single snapshot being
 * overwritten. Position feeds `relevance`; movement feeds `novelty` — and with
 * the Trends profile at γ = 0.7, the climber wins.
 *
 * Signals only, never a score: the profile's α/β/γ decide what any of it is
 * worth. See docs/features/popularity-charts.md.
 */
class PopularRetriever implements Retriever
{
    /**
     * How stale a snapshot may be before this retriever stands down.
     *
     * A fortnight of nothing means the puller is broken or the credentials
     * lapsed. Ranking on a month-old chart would present stale demand as current
     * — the mode renormalises onto `fresh` instead, which is honest.
     */
    private const MAX_SNAPSHOT_AGE_DAYS = 14;

    /**
     * The window movement is measured over.
     *
     * A week, not a day. Bestseller charts jitter: a product moves three places
     * overnight for reasons that are noise — a competitor's stock-out, an
     * afternoon of one shop's traffic. Over seven days a move means something.
     */
    private const MOVEMENT_DAYS = 7;

    public function key(): string
    {
        return 'popular';
    }

    public function isAvailable(DiscoveryRequest $request): bool
    {
        $latest = PopularRank::latestCapturedOn($request->market);

        return $latest !== null
            && $latest >= now()->subDays(self::MAX_SNAPSHOT_AGE_DAYS)->toDateString();
    }

    public function retrieve(DiscoveryRequest $request, int $take): array
    {
        $latest = PopularRank::latestCapturedOn($request->market);

        if ($latest === null) {
            return [];
        }

        $current = $this->ranksOn($request, $latest, $take * 3);

        if ($current === []) {
            return [];
        }

        /*
         * No baseline means novelty is unknowable, not maximal.
         *
         * On the day a new environment first pulls a chart there is exactly one
         * snapshot, and treating every product as a new entry would hand the
         * whole chart the strongest possible novelty — at Trends' γ = 0.7, a
         * page ranked almost entirely by a gap in our own data, explaining every
         * result as "new" on the one day that claim cannot be supported. Leaving
         * the signal unset lets the ranker apply its neutral default instead.
         */
        $comparison = $this->comparisonDate($request, $latest);
        $previous = $this->ranksOn($request, $comparison, count($current) * 4);

        $groups = ProductGroup::query()
            ->whereIn('id', array_keys($current))
            ->forMarket($request->market)
            // The quality gate is not optional per retriever. A chart entry we
            // could not price, stock or picture is not made showable by being
            // popular.
            ->presentable()
            ->when($request->budgetMax !== null, fn ($q) => $q->where('min_price', '<=', $request->budgetMax))
            ->get();

        return $groups
            /*
             * Sorted before it is cut.
             *
             * The pool is three times the asked-for size, and `whereIn` returns
             * rows in whatever order Postgres finds them — so taking the first
             * `$take` off an unsorted collection would discard the top of the
             * chart at random and hand the ranker a middling third of it. The
             * ranker reorders afterwards, which is exactly why this is easy to
             * miss: the page still looks plausible, built from the wrong
             * candidates.
             */
            ->sortBy(fn (ProductGroup $group) => $current[$group->id])
            ->take($take)
            ->map(fn (ProductGroup $group) => (new Candidate($group))->withSignals([
                'relevance' => $this->positionSignal($current[$group->id]),
                'quality' => $this->quality($group),
                'unexpectedness' => min(1.0, ((float) ($group->surprise_score ?? 30)) / 100),
                ...($comparison === null ? [] : [
                    'novelty' => $this->movement($current[$group->id], $previous[$group->id] ?? null),
                ]),
            ], $this->key()))
            ->values()
            ->all();
    }

    /**
     * Best rank per group on a given day.
     *
     * *Best*, because one product can sit on several charts at once — the
     * market-wide list and two categories. Its strongest position is the honest
     * reading of how well it sells; averaging would punish a product for
     * charting in a big category as well as a small one.
     *
     * @return array<int, int> group id => rank
     */
    private function ranksOn(DiscoveryRequest $request, ?string $date, int $limit): array
    {
        if ($date === null) {
            return [];
        }

        $rows = DB::table('popular_ranks')
            ->where('market', $request->market->value)
            ->where('captured_on', $date)
            ->whereNotNull('group_id')
            ->when(
                $request->excludeGroupIds !== [],
                fn ($q) => $q->whereNotIn('group_id', $request->excludeGroupIds),
            )
            ->groupBy('group_id')
            ->select('group_id', DB::raw('min(rank) as rank'))
            ->orderByRaw('min(rank)')
            ->limit($limit)
            ->get();

        $ranks = [];

        foreach ($rows as $row) {
            $ranks[(int) $row->group_id] = (int) $row->rank;
        }

        return $ranks;
    }

    /**
     * The most recent snapshot at least a week old.
     *
     * Not "seven days ago exactly": a run can be skipped, a deploy can eat a
     * night, and a missing date would silently make every product look like a
     * new entry — the highest possible novelty, awarded for a gap in our own
     * data.
     */
    private function comparisonDate(DiscoveryRequest $request, string $latest): ?string
    {
        $cutoff = now()->parse($latest)->subDays(self::MOVEMENT_DAYS)->toDateString();

        $date = DB::table('popular_ranks')
            ->where('market', $request->market->value)
            ->where('captured_on', '<=', $cutoff)
            ->max('captured_on');

        return $date === null ? null : (string) $date;
    }

    /**
     * Rank position, log-decayed.
     *
     * #1 ≈ 1.0, #10 ≈ 0.30, #100 ≈ 0.18. Log rather than linear because the head
     * of a bestseller list is worth incomparably more than its tail: the gap
     * between #1 and #10 is real, the gap between #90 and #100 is rounding.
     */
    private function positionSignal(int $rank): float
    {
        return min(1.0, 1.0 / (1.0 + log(max(1, $rank))));
    }

    /**
     * How far up the chart this has come, 0..1.
     *
     * No previous sample means a new entry, which is the strongest form of this
     * signal — something arrived and started selling. A product that is flat or
     * falling scores a floor rather than zero: still popular, just not news, and
     * a zero would annihilate the whole multiplicative score.
     */
    private function movement(int $current, ?int $previous): float
    {
        if ($previous === null) {
            return 1.0;
        }

        if ($current >= $previous) {
            return 0.2;
        }

        // Proportional to where it came from: #40 → #6 is a bigger story than
        // #6 → #2, even though the second moved fewer places.
        return min(1.0, ($previous - $current) / $previous);
    }

    /**
     * A chart entry is single-merchant by construction — it came from one
     * retailer's list. A second shop stocking it is therefore real added
     * comparability rather than the norm, and worth the last tenth.
     */
    private function quality(ProductGroup $group): float
    {
        return $group->merchant_count > 1 ? 1.0 : 0.9;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Charts;

use App\Enums\Market;
use App\Models\PopularRank;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Does this actually sell?" — one number per product group, 0..1.
 *
 * The read side of the rank history, and deliberately the *only* one that
 * request-path code touches. Charts are pulled once a day, so this is a lookup
 * against a table that changed hours ago; recomputing it per request would be a
 * join per suggestion for an answer that is stable until tomorrow morning.
 *
 * ## What this is allowed to be used for
 *
 * Two things: making sure chart products reach a candidate pool at all, and
 * evidence that a product is real. It is **not** a popularity boost for the Gift
 * Whisperer. `SuggestionEngine::surprise()` exists precisely to stop the
 * best-stocked bestseller winning every tie, and `SerendipityEngine` inverts
 * merchant count on purpose — a demand term added on top of either would quietly
 * undo the thing those classes were built to do. See
 * docs/features/popularity-charts.md.
 */
class ChartDemand
{
    /**
     * A day is the natural period — the puller runs daily — but an hour is the
     * cache. Long enough to make this free, short enough that an operator who
     * runs `bc:pull-charts` by hand sees the result while still looking at it.
     */
    private const TTL_SECONDS = 3600;

    /** @var array<string, array<int, float>> per-request memo, keyed by market */
    private array $memo = [];

    /**
     * Demand per group in a market: group id => 0..1.
     *
     * Absent means "not on any chart", which is not the same as zero demand — it
     * is no evidence either way. Callers must treat a missing key as neutral
     * rather than as a penalty.
     *
     * @return array<int, float>
     */
    public function scores(Market $market): array
    {
        return $this->memo[$market->value] ??= Cache::remember(
            "bc:charts:demand:{$market->value}",
            self::TTL_SECONDS,
            function () use ($market): array {
                $latest = PopularRank::latestCapturedOn($market);

                if ($latest === null) {
                    return [];
                }

                $rows = DB::table('popular_ranks')
                    ->where('market', $market->value)
                    ->where('captured_on', $latest)
                    ->whereNotNull('group_id')
                    // Best position across every chart it appears on. A product
                    // charting in both a category and the market-wide list is
                    // not less popular for having done so.
                    ->groupBy('group_id')
                    ->select('group_id', DB::raw('min(rank) as rank'))
                    ->get();

                $scores = [];

                foreach ($rows as $row) {
                    $scores[(int) $row->group_id] = $this->fromRank((int) $row->rank);
                }

                return $scores;
            },
        );
    }

    /** Neutral (0.0 — no evidence) for anything that has never charted. */
    public function score(Market $market, int $groupId): float
    {
        return $this->scores($market)[$groupId] ?? 0.0;
    }

    /**
     * The best-selling groups in a market, best first.
     *
     * @return list<int>
     */
    public function topGroupIds(Market $market, int $limit): array
    {
        $scores = $this->scores($market);

        arsort($scores);

        return array_slice(array_keys($scores), 0, max(0, $limit));
    }

    public function hasData(Market $market): bool
    {
        return $this->scores($market) !== [];
    }

    /**
     * Drop the cached map for a market.
     *
     * Called by the puller once a run has written and linked its ranks. Without
     * it an operator runs `bc:pull-charts` by hand and then spends an hour
     * wondering why nothing downstream reflects it — the answer being a cache
     * doing exactly its job, which is the least debuggable kind of wrong.
     */
    public function forget(Market $market): void
    {
        unset($this->memo[$market->value]);

        Cache::forget("bc:charts:demand:{$market->value}");
    }

    /**
     * Rank → strength, log-decayed.
     *
     * #1 ≈ 1.0, #10 ≈ 0.30, #100 ≈ 0.18. The same curve the discovery retriever
     * uses, and the same reason: the head of a bestseller list is worth
     * incomparably more than its tail, so a linear scale would rate #90 and #100
     * as meaningfully different and #1 and #10 as barely so — both backwards.
     */
    private function fromRank(int $rank): float
    {
        return min(1.0, 1.0 / (1.0 + log(max(1, $rank))));
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Discover\Retrievers;

use App\Models\ProductGroup;
use App\Models\Wishlist;
use App\Services\Discover\Candidate;
use App\Services\Discover\DiscoveryRequest;
use App\Support\Owner;
use Illuminate\Support\Facades\DB;

/**
 * "People who saved this also saved…" — collaborative signal, honestly scoped.
 *
 * Named for the two-tower architecture the spec asks for, and deliberately not
 * that yet. A two-tower model needs interaction volume we do not have: a
 * learned user embedding from three weeks of a new site's traffic is a
 * confident-looking function of noise, and it would be indistinguishable from a
 * working one until it had been shaping results for months.
 *
 * What this is instead: **item-item co-occurrence over saved lists**. If two
 * products keep landing on the same wishlist, that is a real signal from real
 * people, computable exactly, and explainable in one sentence. When there is
 * enough interaction data to train something better, this class is where it
 * goes — the retriever interface is the seam.
 *
 * ## It reports itself unavailable rather than guessing
 *
 * With no seeds and no history it returns nothing and says so, and the engine
 * renormalises onto the profile's other retrievers. A collaborative retriever
 * that falls back to "popular products" is the single most common way a
 * recommender becomes a bestseller list wearing a personalisation badge.
 */
class TwoTowerRetriever implements Retriever
{
    /** Below this, co-occurrence is coincidence. */
    private const MIN_CO_OCCURRENCE = 2;

    public function key(): string
    {
        return 'twoTower';
    }

    public function isAvailable(DiscoveryRequest $request): bool
    {
        return $this->seeds($request) !== [];
    }

    public function retrieve(DiscoveryRequest $request, int $take): array
    {
        $seeds = $this->seeds($request);

        if ($seeds === []) {
            return [];
        }

        /*
         * Products that share a list with a seed, ranked by how many distinct
         * lists they co-occur on.
         *
         * DISTINCT on the list, not the row: one person adding the same pair to
         * six of their own lists is one person's opinion, and counting it six
         * times lets a single enthusiastic user steer the whole surface.
         */
        $rows = DB::table('wishlist_items as a')
            ->join('wishlist_items as b', 'b.wishlist_id', '=', 'a.wishlist_id')
            ->join('product_groups as g', 'g.id', '=', 'b.group_id')
            ->whereIn('a.group_id', $seeds)
            ->whereNotIn('b.group_id', [...$seeds, ...$request->excludeGroupIds])
            ->where('g.market', $request->market->value)
            ->where('g.in_stock', true)
            ->whereNotNull('g.min_price')
            ->whereNotNull('g.image_url')
            ->groupBy('b.group_id')
            ->havingRaw('count(DISTINCT a.wishlist_id) >= ?', [self::MIN_CO_OCCURRENCE])
            ->select('b.group_id', DB::raw('count(DISTINCT a.wishlist_id)::int as strength'))
            ->orderByDesc('strength')
            ->limit($take)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $strengths = $rows->mapWithKeys(fn ($r) => [(int) $r->group_id => (int) $r->strength])->all();
        $strongest = max($strengths);

        return ProductGroup::query()
            ->whereIn('id', array_keys($strengths))
            ->get()
            ->map(fn (ProductGroup $group) => (new Candidate($group))->withSignals([
                // Normalised against the strongest pair in this result set, not
                // an absolute scale: co-occurrence counts mean completely
                // different things on a site with a hundred lists and one with
                // a hundred thousand.
                'relevance' => min(1.0, $strengths[$group->id] / max(1, $strongest)),
                'quality' => 1.0,
                'novelty' => 0.4,
                'unexpectedness' => min(1.0, ((float) ($group->surprise_score ?? 40)) / 100),
            ], $this->key()))
            ->values()
            ->all();
    }

    /**
     * What to find neighbours of.
     *
     * Explicit seeds first; otherwise whatever this visitor has already saved,
     * which is the closest thing to a taste profile that exists without a
     * model.
     *
     * @return list<int>
     */
    private function seeds(DiscoveryRequest $request): array
    {
        if ($request->seedGroupIds !== []) {
            return $request->seedGroupIds;
        }

        $owner = Owner::fromRequest(request());

        if (! $owner->exists()) {
            return [];
        }

        return $owner->scope(Wishlist::query())
            ->join('wishlist_items', 'wishlist_items.wishlist_id', '=', 'wishlists.id')
            ->whereNotNull('wishlist_items.group_id')
            ->limit(20)
            ->pluck('wishlist_items.group_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Discover\Retrievers;

use App\Models\ProductGroup;
use App\Services\Discover\Candidate;
use App\Services\Discover\DiscoveryRequest;
use Illuminate\Support\Facades\DB;

/**
 * New arrivals and things on the way up.
 *
 * Two different questions wearing one name, and the mode needs both:
 *
 * - **New** is `first_seen_at` — this appeared in a feed recently.
 * - **Rising** is velocity — more shops started stocking it, or its price
 *   started moving. A product that three shops picked up this month is
 *   "current" in a way a genuinely new listing from one merchant is not.
 *
 * Newness alone would make this a feed-ingest changelog: a big advertiser's
 * first import would flood it with ten thousand products that are new to *us*
 * and years old in the world. Velocity is what makes the distinction.
 */
class FreshRetriever implements Retriever
{
    /** What counts as new. Long enough to survive a quiet fortnight. */
    private const NEW_DAYS = 30;

    /** The window velocity is measured over. */
    private const VELOCITY_DAYS = 14;

    public function key(): string
    {
        return 'fresh';
    }

    public function isAvailable(DiscoveryRequest $request): bool
    {
        return true;
    }

    public function retrieve(DiscoveryRequest $request, int $take): array
    {
        $rising = $this->rising($request, $take);

        $groups = ProductGroup::query()
            ->forMarket($request->market)
            ->presentable()
            ->when(
                $request->excludeGroupIds !== [],
                fn ($q) => $q->whereNotIn('id', $request->excludeGroupIds),
            )
            ->when($request->budgetMax !== null, fn ($q) => $q->where('min_price', '<=', $request->budgetMax))
            ->where(function ($q) use ($rising): void {
                $q->where('first_seen_at', '>=', now()->subDays(self::NEW_DAYS));

                if ($rising !== []) {
                    $q->orWhereIn('id', array_keys($rising));
                }
            })
            // Newest first, but the ranker's γ is what actually orders the page
            // — this is only about which candidates make it into the pool.
            ->orderByDesc('first_seen_at')
            ->limit($take * 3)
            ->get();

        return $groups
            ->map(fn (ProductGroup $group) => (new Candidate($group))->withSignals([
                'novelty' => max(
                    $this->newness($group),
                    $rising[$group->id] ?? 0.0,
                ),
                'relevance' => 0.5,
                'quality' => $group->merchant_count > 1 ? 1.0 : 0.8,
                'unexpectedness' => min(1.0, ((float) ($group->surprise_score ?? 30)) / 100),
            ], $this->key()))
            ->take($take)
            ->values()
            ->all();
    }

    /**
     * Products more shops have started stocking lately.
     *
     * Counts offers whose row was created inside the window against the group's
     * total. A group that went from one merchant to three in a fortnight is
     * rising; one that has been at four merchants for a year is not, however
     * many offers it has.
     *
     * @return array<int, float> group id => velocity 0..1
     */
    private function rising(DiscoveryRequest $request, int $take): array
    {
        $rows = DB::table('products as p')
            ->join('product_groups as g', 'g.id', '=', 'p.group_id')
            ->where('g.market', $request->market->value)
            ->where('p.status', 'active')
            ->groupBy('p.group_id')
            ->havingRaw('count(*) FILTER (WHERE p.created_at >= ?) > 0', [now()->subDays(self::VELOCITY_DAYS)])
            ->select(
                'p.group_id',
                DB::raw('count(*) FILTER (WHERE p.created_at >= \''.now()->subDays(self::VELOCITY_DAYS)->toDateTimeString().'\')::float as recent'),
                DB::raw('count(*)::float as total'),
            )
            ->orderByRaw('count(*) FILTER (WHERE p.created_at >= \''.now()->subDays(self::VELOCITY_DAYS)->toDateTimeString().'\') DESC')
            ->limit($take * 4)
            ->get();

        $velocity = [];

        foreach ($rows as $row) {
            // Share of this product's shops that arrived in the window. A group
            // where two of three offers are new scores 0.67; one where two of
            // twenty are new scores 0.1, which is right — it was already well
            // stocked.
            $velocity[(int) $row->group_id] = min(1.0, (float) $row->recent / max(1.0, (float) $row->total));
        }

        return $velocity;
    }

    private function newness(ProductGroup $group): float
    {
        if ($group->first_seen_at === null) {
            return 0.0;
        }

        return max(0.0, min(1.0, 1.0 - ($group->first_seen_at->diffInDays(now()) / self::NEW_DAYS)));
    }
}

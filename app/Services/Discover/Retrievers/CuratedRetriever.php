<?php

declare(strict_types=1);

namespace App\Services\Discover\Retrievers;

use App\Enums\PublishStatus;
use App\Models\ProductGroup;
use App\Services\Discover\Candidate;
use App\Services\Discover\DiscoveryRequest;
use Illuminate\Support\Facades\DB;

/**
 * Everything a human or a scheduled job already decided was worth showing.
 *
 * The editorial pool: recent Daily Cove editions and published buying-guide
 * shortlists. This is what makes guides a *mode* rather than a separate
 * subsystem — a guide's shortlist is a curated pool, and the Guides surface is
 * the same pipeline reading it. A "ghost shop" persona would be another pool
 * feeding the same retriever, not another codebase.
 *
 * Curated candidates carry a high quality floor by construction: something got
 * into a guide because it survived the shortlist rules, and into an edition
 * because it survived the serendipity gate. That is exactly the evidence the
 * lean-back modes need, since they have no query to measure relevance against.
 */
class CuratedRetriever implements Retriever
{
    /** How far back the pool reaches. Beyond this the picks stop being current. */
    private const WINDOW_DAYS = 120;

    public function key(): string
    {
        return 'curated';
    }

    public function isAvailable(DiscoveryRequest $request): bool
    {
        return true;
    }

    public function retrieve(DiscoveryRequest $request, int $take): array
    {
        $ids = $this->pool($request);

        if ($ids === []) {
            return [];
        }

        shuffle($ids);
        $ids = array_slice($ids, 0, $take);

        return ProductGroup::query()
            ->whereIn('id', $ids)
            ->presentable()
            ->get()
            ->map(fn (ProductGroup $group) => (new Candidate($group))->withSignals([
                // Someone (or something that answers to someone) chose this.
                // That is the strongest quality evidence available without a
                // query, and it is why the lean-back modes lean on it.
                'quality' => 1.0,
                'relevance' => 0.5,
                'unexpectedness' => min(1.0, ((float) ($group->surprise_score ?? 40)) / 100),
                'novelty' => 0.5,
            ], $this->key()))
            ->values()
            ->all();
    }

    /**
     * Group ids from both editorial sources.
     *
     * @return list<int>
     */
    private function pool(DiscoveryRequest $request): array
    {
        $picks = DB::table('daily_picks as p')
            ->join('daily_pick_sets as s', 's.id', '=', 'p.set_id')
            ->where('s.market', $request->market->value)
            ->where('s.status', PublishStatus::Published->value)
            ->where('s.drop_date', '>=', now()->subDays(self::WINDOW_DAYS)->toDateString())
            ->whereNotNull('p.group_id')
            ->pluck('p.group_id');

        $guideItems = DB::table('guide_items as i')
            ->join('guides as g', 'g.id', '=', 'i.guide_id')
            ->where('g.market', $request->market->value)
            ->where('g.status', PublishStatus::Published->value)
            ->where('i.unavailable', false)
            ->pluck('i.group_id');

        return array_values(array_diff(
            array_unique(array_map('intval', [...$picks, ...$guideItems])),
            $request->excludeGroupIds,
        ));
    }
}

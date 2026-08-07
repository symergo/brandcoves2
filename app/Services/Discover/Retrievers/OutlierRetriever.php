<?php

declare(strict_types=1);

namespace App\Services\Discover\Retrievers;

use App\Models\ProductGroup;
use App\Services\Discover\Candidate;
use App\Services\Discover\DiscoveryRequest;

/**
 * The long tail — things almost nobody stocks and almost nobody describes that way.
 *
 * Reads `surprise_score`, which the Serendipity Engine wrote after the last
 * ingest. Nothing is scored per request: the score is a comparison against the
 * whole catalogue's word distribution, which is a job's worth of work.
 *
 * The spec calls for embedding-space oddballs. Until pgvector lands this uses
 * the lexical-rarity surrogate the Serendipity Engine already computes — which
 * approximates "far from its category's centre" using word frequencies, and is
 * good enough to ship without an embedding pipeline to keep in sync. Swapping
 * the implementation later is a change inside this class and nowhere else,
 * which is the point of the retriever interface.
 */
class OutlierRetriever implements Retriever
{
    public function key(): string
    {
        return 'outlier';
    }

    public function isAvailable(DiscoveryRequest $request): bool
    {
        return true;
    }

    public function retrieve(DiscoveryRequest $request, int $take): array
    {
        /*
         * Sample from a wide top slice rather than taking the top N.
         *
         * A discovery surface that returns the same twenty rows to everyone
         * forever is not a discovery surface. Ranking first and shuffling
         * second keeps every candidate one that genuinely scored well, which
         * `ORDER BY random()` over the whole table would not.
         */
        $pool = ProductGroup::query()
            ->forMarket($request->market)
            ->presentable()
            ->where('surprise_score', '>', 0)
            ->when(
                $request->excludeGroupIds !== [],
                fn ($q) => $q->whereNotIn('id', $request->excludeGroupIds),
            )
            ->when($request->budgetMax !== null, fn ($q) => $q->where('min_price', '<=', $request->budgetMax))
            ->when($request->budgetMin !== null, fn ($q) => $q->where('min_price', '>=', $request->budgetMin))
            ->orderByDesc('surprise_score')
            ->limit(max($take * 6, 120))
            ->get(['id', 'market', 'identity_key', 'identity_kind', 'title', 'slug', 'brand', 'category',
                'image_url', 'min_price', 'max_price', 'median_price', 'merchant_count', 'in_stock',
                'surprise_score', 'surprise_breakdown', 'first_seen_at']);

        if ($pool->isEmpty()) {
            return [];
        }

        return $pool->shuffle()
            ->take($take)
            ->map(fn (ProductGroup $group) => (new Candidate($group))->withSignals([
                'unexpectedness' => min(1.0, ((float) $group->surprise_score) / 100),
                // No query means no relevance to measure. Neutral rather than
                // zero — scoring it zero would make the multiplicative
                // objective collapse to nothing for every candidate.
                'relevance' => $request->hasQuery() ? 0.3 : 0.5,
                'quality' => $group->in_stock ? 1.0 : 0.0,
                'novelty' => $this->novelty($group),
            ], $this->key()))
            ->values()
            ->all();
    }

    private function novelty(ProductGroup $group): float
    {
        if ($group->first_seen_at === null) {
            return 0.0;
        }

        return max(0.0, min(1.0, 1.0 - ($group->first_seen_at->diffInDays(now()) / 30)));
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Discover\Retrievers;

use App\Models\ProductGroup;
use App\Services\Discover\Candidate;
use App\Services\Discover\DiscoveryRequest;
use App\Services\Search\SearchQuery;
use App\Services\Search\SearchService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Lexical search — full text plus trigram, and the live sources.
 *
 * Wraps the existing SearchService rather than reimplementing it. That service
 * already folds live bol offers into the stored graph mid-request, handles the
 * `<%` word_similarity path that makes typos work, and logs the query; a
 * parallel implementation would drift from it within a month.
 *
 * Sets `relevance` from title similarity, so the ranker has something with a
 * meaning rather than a rank position.
 */
class KeywordRetriever implements Retriever
{
    public function __construct(private readonly SearchService $search) {}

    public function key(): string
    {
        return 'keyword';
    }

    public function isAvailable(DiscoveryRequest $request): bool
    {
        // Nothing to match against. The engine will renormalise onto the other
        // retrievers rather than returning an empty page.
        return $request->hasQuery();
    }

    public function retrieve(DiscoveryRequest $request, int $take): array
    {
        try {
            $result = $this->search->search(new SearchQuery(
                market: $request->market,
                term: (string) $request->query,
                // Not "discounted only" — that is the Deals profile's job, and
                // baking it in here would mean Search silently hides anything
                // at its normal price.
                discountedOnly: false,
                page: 1,
            ));
        } catch (Throwable $e) {
            // Degrade rather than throw: a source being down costs its
            // candidates and nothing else.
            report($e);

            return [];
        }

        $groups = collect($result->groups->items())
            ->reject(fn (ProductGroup $g) => in_array($g->id, $request->excludeGroupIds, true))
            ->values();

        if ($groups->isEmpty()) {
            return [];
        }

        $similarity = $this->similarity((string) $request->query, $groups->pluck('id')->all());

        return $groups
            ->map(fn (ProductGroup $group) => (new Candidate($group))->withSignals([
                'relevance' => $similarity[$group->id] ?? 0.5,
                // Comparability is a quality signal here, not a relevance one:
                // a card carrying three shops' prices is more useful than one
                // showing a single price, at equal relevance.
                'quality' => $this->quality($group),
            ], $this->key()))
            ->all();
    }

    /**
     * Title similarity per group, in one query.
     *
     * Read back from Postgres rather than recomputed in PHP, so relevance here
     * means the same thing it means in the search ranking — two different
     * definitions of "relevant" on the same corpus is a bug waiting to be
     * argued about.
     *
     * @param  list<int>  $ids
     * @return array<int, float>
     */
    private function similarity(string $term, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return collect(DB::table('product_groups')
            ->whereIn('id', $ids)
            ->select('id', DB::raw('word_similarity(?, title) as sim'))
            ->addBinding($term, 'select')
            ->get())
            ->mapWithKeys(fn ($row) => [(int) $row->id => min(1.0, (float) $row->sim)])
            ->all();
    }

    private function quality(ProductGroup $group): float
    {
        $quality = $group->in_stock ? 1.0 : 0.2;

        if ($group->image_url === null) {
            $quality *= 0.5;
        }

        if ($group->merchant_count > 1) {
            $quality = min(1.0, $quality * 1.15);
        }

        return $quality;
    }
}

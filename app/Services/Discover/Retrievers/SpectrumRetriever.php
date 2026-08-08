<?php

declare(strict_types=1);

namespace App\Services\Discover\Retrievers;

use App\Models\ProductGroup;
use App\Services\Discover\Candidate;
use App\Services\Discover\DiscoveryRequest;
use Illuminate\Support\Facades\DB;

/**
 * A category laid out cheap → premium, with the dupes marked.
 *
 * The Compare mode's retriever. Someone deciding between options does not want
 * a ranked list — a ranking answers "which is best", and they have already
 * rejected that question by asking to compare. They want the *shape* of the
 * category: what the floor buys, what the ceiling buys, and where the curve
 * stops being worth it.
 *
 * ## Two ways in
 *
 * From **seed items** (they pointed at something) the category and the price
 * band come from the seeds. From a **query** the category comes from what the
 * query matches. Either way the output is the same: an ordered ladder.
 *
 * ## Dupes
 *
 * The genuinely useful answer in most categories is "this cheaper one is the
 * same thing". A dupe here is a product that is close in title terms and
 * materially cheaper — not a claim about build quality, which we cannot know
 * and will not pretend to.
 */
class SpectrumRetriever implements Retriever
{
    public function key(): string
    {
        return 'spectrum';
    }

    public function isAvailable(DiscoveryRequest $request): bool
    {
        // Needs something to be a spectrum *of*. Without a seed or a query it
        // would be "the whole catalogue, by price", which is not a comparison.
        return $request->seedGroupIds !== [] || $request->hasQuery();
    }

    public function retrieve(DiscoveryRequest $request, int $take): array
    {
        $anchor = $this->anchor($request);

        if ($anchor === null) {
            return [];
        }

        $groups = ProductGroup::query()
            ->forMarket($request->market)
            ->presentable()
            ->when(
                $request->excludeGroupIds !== [],
                fn ($q) => $q->whereNotIn('id', $request->excludeGroupIds),
            )
            ->when(
                $anchor['category'] !== null,
                fn ($q) => $q->where('category', $anchor['category']),
                // No usable category — fall back to matching the anchor's own
                // words. A spectrum of near-misses beats no spectrum.
                fn ($q) => $q->whereExists(fn ($sub) => $sub
                    ->select(DB::raw(1))
                    ->from('products')
                    ->whereColumn('products.group_id', 'product_groups.id')
                    ->where('products.status', 'active')
                    ->whereRaw(
                        'products.search_vector @@ websearch_to_tsquery(bc_text_config(products.market), ?)',
                        [$anchor['term']]
                    )),
            )
            ->orderBy('min_price')
            ->limit(120)
            ->get();

        if ($groups->isEmpty()) {
            return [];
        }

        /*
         * Sample evenly across the price range rather than taking the cheapest
         * N. Taking the head returns the bottom of the market and calls it a
         * comparison; an even sample is what makes the ladder legible.
         *
         * Spread to the *requested* size, not to `$take`. The engine over-fetches
         * so MMR has choices to make, and here that is actively wrong: handing
         * the ranker four times the rungs lets it discard the top of the ladder
         * as near-duplicates of the bottom and return the cheap end again.
         * This retriever's selection IS the answer, so it hands over exactly
         * the answer.
         */
        $ladder = $this->spread($groups->all(), $request->limit);
        $cheapest = $ladder[0]->min_price ?? 0;

        return array_map(function (ProductGroup $group) use ($anchor, $cheapest) {
            $dupe = $anchor['title'] !== null
                && $group->min_price !== null
                && $cheapest > 0
                && $group->min_price <= $cheapest * 1.25
                && $this->titleOverlap($anchor['title'], $group->title) > 0.4;

            return (new Candidate($group))->withSignals([
                'relevance' => $dupe ? 1.0 : 0.7,
                'quality' => $group->merchant_count > 1 ? 1.0 : 0.8,
                'novelty' => 0.3,
                'unexpectedness' => $dupe ? 0.8 : 0.3,
            ], $this->key());
        }, $ladder);
    }

    /**
     * What the spectrum is a spectrum of.
     *
     * @return array{category: string|null, term: string, title: string|null}|null
     */
    private function anchor(DiscoveryRequest $request): ?array
    {
        if ($request->seedGroupIds !== []) {
            $seed = ProductGroup::query()
                ->forMarket($request->market)
                ->whereIn('id', $request->seedGroupIds)
                ->first();

            if ($seed !== null) {
                return ['category' => $seed->category, 'term' => $seed->title, 'title' => $seed->title];
            }
        }

        if (! $request->hasQuery()) {
            return null;
        }

        // The category the query lands in most often — a better anchor than the
        // query's own words, because the shopper typed a product and wants its
        // neighbours.
        $category = ProductGroup::query()
            ->forMarket($request->market)
            ->presentable()
            ->whereNotNull('category')
            ->whereRaw('? <% product_groups.title', [$request->query])
            ->groupBy('category')
            ->orderByRaw('count(*) DESC')
            ->limit(1)
            ->value('category');

        return ['category' => $category, 'term' => (string) $request->query, 'title' => null];
    }

    /**
     * Take an evenly spaced sample from a price-ordered list.
     *
     * @param  list<ProductGroup>  $ordered
     * @return list<ProductGroup>
     */
    private function spread(array $ordered, int $take): array
    {
        $count = count($ordered);

        if ($count <= $take) {
            return $ordered;
        }

        $picked = [];
        $step = ($count - 1) / max(1, $take - 1);

        for ($i = 0; $i < $take; $i++) {
            $picked[] = $ordered[(int) round($i * $step)];
        }

        return array_values(array_unique($picked, SORT_REGULAR));
    }

    private function titleOverlap(string $left, string $right): float
    {
        $tokenise = static function (string $text): array {
            $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            return array_values(array_unique(array_filter($words, fn (string $w) => mb_strlen($w) > 2)));
        };

        $a = $tokenise($left);
        $b = $tokenise($right);

        if ($a === [] || $b === []) {
            return 0.0;
        }

        return count(array_intersect($a, $b)) / count(array_unique([...$a, ...$b]));
    }
}

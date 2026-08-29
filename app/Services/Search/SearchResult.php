<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\ProductGroup;
use App\Services\Connectors\Offer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class SearchResult
{
    /**
     * @param  LengthAwarePaginator<int, ProductGroup>  $groups
     * @param  array{brands: list<array{value: string, count: int}>, merchants: list<array{id: int, name: string, count: int}>, price: array{min: int|null, max: int|null}}  $facets
     * @param  list<Offer>  $liveOffers  Live sources that may not be mirrored — Amazon.
     *                                   They are absent from `$groups` by construction: nothing
     *                                   wrote them, so no SQL query can return them, and a page
     *                                   that wants them has to render them itself. See
     *                                   docs/features/amazon-compliance.md.
     */
    public function __construct(
        public LengthAwarePaginator $groups,
        public SearchQuery $query,
        public int $liveOffersAdded,
        public array $facets,
        public array $liveOffers = [],
    ) {}

    /**
     * The facets, with their counts left behind.
     *
     * The count is what *orders* the facet list — most-stocked brand first,
     * fifteen of them — and it is not something to print beside a checkbox. A
     * number there describes our catalogue rather than the thing being looked
     * for, and it is computed against a cached aggregate while the grid beside
     * it is live, so the two disagree by design.
     *
     * Shaped here rather than in each controller because both the search page
     * and the brand page render the same rail, and a count reintroduced on one
     * of them is exactly the sort of drift that goes unnoticed.
     *
     * @return array{brands: list<array{value: string}>, merchants: list<array{id: int, name: string}>, price: array{min: int|null, max: int|null}}
     */
    public function facetsWithoutCounts(): array
    {
        return [
            'brands' => array_map(
                fn (array $f) => ['value' => $f['value']],
                $this->facets['brands'] ?? [],
            ),
            'merchants' => array_map(
                fn (array $f) => ['id' => $f['id'], 'name' => $f['name']],
                $this->facets['merchants'] ?? [],
            ),
            // The price range is a filter bound, not a count of anything.
            'price' => $this->facets['price'] ?? ['min' => null, 'max' => null],
        ];
    }

    public function isEmpty(): bool
    {
        return $this->groups->total() === 0;
    }

    /**
     * Whether an empty result is likely to be the filters rather than the term.
     *
     * Drives the difference between "nothing matches, try another word" and
     * "nothing matches these filters, here is the one to remove" — which are
     * very different messages to a shopper.
     */
    public function emptyBecauseOfFilters(): bool
    {
        return $this->isEmpty() && $this->query->hasFilters();
    }
}

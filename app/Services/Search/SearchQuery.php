<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\Market;
use Illuminate\Http\Request;

/**
 * A parsed, validated search request.
 *
 * A value object rather than loose parameters so the search service cannot be
 * called with a half-built filter set, and so the cache key is derivable from
 * one place.
 */
final readonly class SearchQuery
{
    /**
     * @param  list<int>  $merchantIds
     * @param  list<string>  $brands
     */
    public function __construct(
        public Market $market,
        public string $term = '',
        public ?int $minPrice = null,
        public ?int $maxPrice = null,
        public array $merchantIds = [],
        public array $brands = [],
        public bool $inStockOnly = true,
        public bool $discountedOnly = true,
        public bool $comparableOnly = false,
        public string $sort = 'relevance',
        public int $page = 1,
        public string $view = 'grid',
    ) {}

    public static function fromRequest(Request $request, Market $market): self
    {
        return new self(
            market: $market,
            term: trim((string) $request->query('q', '')),
            // Prices arrive as euros from the UI but are stored as cents.
            minPrice: self::cents($request->query('min')),
            maxPrice: self::cents($request->query('max')),
            merchantIds: array_values(array_filter(array_map(
                'intval',
                (array) $request->query('merchant', []),
            ))),
            brands: array_values(array_filter(array_map(
                'strval',
                (array) $request->query('brand', []),
            ))),
            // Defaults to true: an unbuyable price is not an offer, and a page
            // of out-of-stock results is worse than a shorter page.
            inStockOnly: $request->boolean('in_stock', true),
            discountedOnly: $request->boolean('discounted'),
            comparableOnly: $request->boolean('comparable'),
            sort: in_array($request->query('sort'), ['relevance', 'price_asc', 'price_desc', 'discount', 'newest'], true)
                ? (string) $request->query('sort')
                : 'relevance',
            page: max(1, (int) $request->query('page', 1)),
            view: $request->query('view') === 'store' ? 'store' : 'grid',
        );
    }

    private static function cents(mixed $euros): ?int
    {
        if ($euros === null || $euros === '') {
            return null;
        }

        $value = (float) str_replace(',', '.', (string) $euros);

        return $value > 0 ? (int) round($value * 100) : null;
    }

    /**
     * The same query, pinned to one brand's spellings.
     *
     * A brand page is a search with the brand preselected, and the spellings come
     * from the resolved `brand_stats` row rather than from the URL — so this
     * REPLACES any `?brand[]=` a visitor supplied instead of adding to it.
     * Allowing both would let `/brand/sony?brand[]=Philips` render a page whose
     * copy talks about Sony and whose results are Philips.
     *
     * A list rather than one string because feeds disagree about punctuation:
     * "Audio-Technica" and "Audio Technica" are one brand with one page, and
     * filtering on a single spelling would hide half its offers.
     *
     * @param  list<string>  $brands
     */
    public function withBrands(array $brands): self
    {
        return new self(
            market: $this->market,
            term: $this->term,
            minPrice: $this->minPrice,
            maxPrice: $this->maxPrice,
            merchantIds: $this->merchantIds,
            brands: array_values($brands),
            inStockOnly: $this->inStockOnly,
            discountedOnly: $this->discountedOnly,
            comparableOnly: $this->comparableOnly,
            sort: $this->sort,
            page: $this->page,
            view: $this->view,
        );
    }

    public function hasTerm(): bool
    {
        return $this->term !== '';
    }

    public function hasFilters(): bool
    {
        return $this->minPrice !== null
            || $this->maxPrice !== null
            || $this->merchantIds !== []
            || $this->brands !== []
            || $this->discountedOnly
            || $this->comparableOnly;
    }

    /** Stable key for caching the live-source half of a search. */
    public function liveCacheKey(): string
    {
        return 'bc:search:live:'.$this->market->value.':'.sha1(mb_strtolower($this->term));
    }

    /** @return array<string, mixed> Query string for building filter links. */
    public function toArray(): array
    {
        return array_filter([
            'q' => $this->term ?: null,
            'min' => $this->minPrice ? $this->minPrice / 100 : null,
            'max' => $this->maxPrice ? $this->maxPrice / 100 : null,
            'merchant' => $this->merchantIds ?: null,
            'brand' => $this->brands ?: null,
            'in_stock' => $this->inStockOnly ? null : '0',
            'discounted' => $this->discountedOnly ? '1' : null,
            'comparable' => $this->comparableOnly ? '1' : null,
            'sort' => $this->sort !== 'relevance' ? $this->sort : null,
            'view' => $this->view !== 'grid' ? $this->view : null,
            'page' => $this->page > 1 ? $this->page : null,
        ], fn ($v) => $v !== null);
    }
}

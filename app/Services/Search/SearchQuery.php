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

        /**
         * Whether this search counts as public demand.
         *
         * `search_log` is not a debugging record — it feeds the related-search
         * chips on every narrative page and the demand signal that decides which
         * buying guides get written. So a term typed there comes back out in
         * front of strangers.
         *
         * That is right for the search box and wrong for a search run inside
         * somebody's shared gift list, which is an unauthenticated private URL
         * where the terms are about one named person. "engagement ring" typed
         * into a friend's list should not surface as a suggested search on a
         * public page.
         *
         * Defaults to true so the ordinary path keeps logging by forgetting
         * about this; the private callers opt out, and there are few of them.
         */
        public bool $logged = true,

        /**
         * What the live sources are asked for, when that is not `$term`.
         *
         * bol and Amazon have no brand filter on the endpoints we use, so the
         * only way to ask them about a brand is to search for its name. A brand
         * page therefore has a query it runs against Postgres — the brand filter,
         * on every spelling — and a *different* one it runs against the live
         * sources, which is the brand's name as words.
         *
         * Null means "the same as `$term`", which is the ordinary search page and
         * the reason this defaults to null rather than to the term. An empty
         * string means "ask nobody", which is how a page turns the live half off
         * for a variant where it would only cost requests.
         */
        public ?string $liveTerm = null,
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
     * `$liveTerm` is what the live sources get asked instead, because none of
     * them takes a brand filter — see the property.
     *
     * @param  list<string>  $brands
     */
    public function withBrands(array $brands, ?string $liveTerm = null): self
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
            // Carried, not re-defaulted: a derived query is the same search and
            // must not start logging because it was narrowed or rewritten.
            logged: $this->logged,
            liveTerm: $liveTerm ?? $this->liveTerm,
        );
    }

    /**
     * The same query, searching for something else.
     *
     * Used when the box did not contain a search term at all — a pasted Amazon
     * URL, which is rewritten to the product's title words. Filters, sort and
     * view survive, because the visitor chose those and the paste did not
     * change their mind about them.
     */
    public function withTerm(string $term): self
    {
        return new self(
            market: $this->market,
            term: trim($term),
            minPrice: $this->minPrice,
            maxPrice: $this->maxPrice,
            merchantIds: $this->merchantIds,
            brands: $this->brands,
            inStockOnly: $this->inStockOnly,
            discountedOnly: $this->discountedOnly,
            comparableOnly: $this->comparableOnly,
            sort: $this->sort,
            page: $this->page,
            view: $this->view,
            // Carried, not re-defaulted: a derived query is the same search and
            // must not start logging because it was narrowed or rewritten.
            logged: $this->logged,
        );
    }

    public function hasTerm(): bool
    {
        return $this->term !== '';
    }

    /** What the live sources are asked for. See the property. */
    public function liveTerm(): string
    {
        return trim($this->liveTerm ?? $this->term);
    }

    public function hasLiveTerm(): bool
    {
        return $this->liveTerm() !== '';
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

    /**
     * Stable key for the live-source half of a search.
     *
     * Keyed on what the live sources are actually asked — a brand page and a
     * typed search for the same brand are one question upstream, and giving them
     * two keys would fold the same offers twice.
     */
    public function liveCacheKey(): string
    {
        return 'bc:search:live:'.$this->market->value.':'.sha1(mb_strtolower($this->liveTerm()));
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

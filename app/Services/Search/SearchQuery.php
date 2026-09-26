<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\Interest;
use App\Enums\Market;
use App\Enums\RecipientType;
use App\Services\Gift\GiftTags;
use App\Support\Crawlers;
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
        /*
         * False, as of 2026-09-06. It defaulted to true, which no caller
         * wanted: the request parser reads the box explicitly and every one
         * of the seven internal callers overrode it with a comment saying the
         * default was wrong. The eighth caller to forget would have silently
         * searched only discounted products.
         */
        public bool $discountedOnly = false,
        public bool $comparableOnly = false,
        public string $sort = 'relevance',
        public int $page = 1,
        public string $view = 'grid',

        /**
         * Whether this search counts as public demand.
         *
         * `search_log` is not a debugging record — it feeds the popular-searches
         * page and the demand signal that decides which buying guides get
         * written. So a term typed there comes back out in front of strangers.
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

        /*
         * Who it is for, what they love, what it is for: `?for=father`,
         * `?interest=cooking`, `?occasion=christmas` (roadmap step 4, part 3).
         *
         * Values of the gift vocabulary only (App\Services\Gift\GiftTags);
         * anything else is dropped at the parse, so a filter can never be a
         * string nothing is tagged with. Several values of one kind mean
         * "any of them"; kinds combine with "and". Matched against the
         * editors' tags and the crowd's (docs/features/list-signals.md).
         */
        /** @var list<string> RecipientType values */
        public array $recipients = [],
        /** @var list<string> Interest values */
        public array $interests = [],
        /** @var list<string> occasion values from the gift vocabulary */
        public array $occasions = [],
    ) {}

    /** The deepest page a request may ask for. */
    public const MAX_PAGE = 200;

    /**
     * Is this search worth remembering as demand?
     *
     * The log feeds the guide topic queue and the popular-searches page, and
     * on 2026-09-08 five in six of its rows were crawler steps through search
     * links. Two tests, and a request must pass both:
     *
     * - The user agent does not name a crawler. A name match; nobody spoofs
     *   Googlebot to stay out of a statistics table.
     * - The request carries this site's session cookie. Crawlers keep no
     *   cookies, whatever they call themselves, so a crawler that does not
     *   announce itself fails here instead. The cost is a person's very first
     *   search after landing from elsewhere, which is not a pattern yet; every
     *   search after it counts.
     *
     * Asked for as "a crawler cannot trigger a pill addition": the length rule
     * on SearchLog stops the long strings, this stops the short ones.
     */
    private static function fromAPerson(Request $request): bool
    {
        return ! Crawlers::looksLikeOne($request->userAgent())
            && $request->cookies->has((string) config('session.cookie'));
    }

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
            /*
             * Bounded above as well as below. `?page=500000` is a deep OFFSET
             * over the whole match set on a route anyone can hit; nothing a
             * person scrolls to lives past a couple of hundred pages.
             */
            page: min(self::MAX_PAGE, max(1, (int) $request->query('page', 1))),
            logged: self::fromAPerson($request),
            view: $request->query('view') === 'store' ? 'store' : 'grid',
            recipients: self::known($request->query('for'), RecipientType::values()),
            interests: self::known($request->query('interest'), Interest::values()),
            occasions: self::known($request->query('occasion'), GiftTags::vocabulary()[GiftTags::OCCASION]),
        );
    }

    /**
     * One value or several (`?interest=cooking` or `?interest[]=cooking&interest[]=coffee`),
     * kept only when the vocabulary knows it.
     *
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private static function known(mixed $values, array $allowed): array
    {
        $wanted = array_map(
            fn ($v) => is_string($v) ? mb_strtolower(trim($v)) : '',
            is_array($values) ? $values : [$values],
        );

        return array_slice(array_values(array_unique(array_filter(
            $wanted,
            fn (string $v) => in_array($v, $allowed, true),
        ))), 0, 8);
    }

    /** Whether any who, interest or occasion filter is set. */
    public function hasTagFilters(): bool
    {
        return $this->recipients !== [] || $this->interests !== [] || $this->occasions !== [];
    }

    /**
     * The tag filters as fully qualified gift tags, one list per kind.
     *
     * @return list<list<string>>
     */
    public function tagGroups(): array
    {
        return array_values(array_filter([
            array_map(fn (string $v) => GiftTags::recipient($v), $this->recipients),
            array_map(fn (string $v) => GiftTags::interest($v), $this->interests),
            array_map(fn (string $v) => GiftTags::occasion($v), $this->occasions),
        ]));
    }

    /**
     * The same search without one tag filter value: what a chip's × runs.
     */
    public function withoutTag(string $kind, string $value): self
    {
        $drop = fn (array $values) => array_values(array_filter($values, fn (string $v) => $v !== $value));

        return $this->copy(
            recipients: $kind === 'for' ? $drop($this->recipients) : $this->recipients,
            interests: $kind === 'interest' ? $drop($this->interests) : $this->interests,
            occasions: $kind === 'occasion' ? $drop($this->occasions) : $this->occasions,
        );
    }

    /**
     * @param  list<string>  $recipients
     * @param  list<string>  $interests
     * @param  list<string>  $occasions
     */
    private function copy(array $recipients, array $interests, array $occasions): self
    {
        return new self(
            market: $this->market,
            term: $this->term,
            minPrice: $this->minPrice,
            maxPrice: $this->maxPrice,
            merchantIds: $this->merchantIds,
            brands: $this->brands,
            inStockOnly: $this->inStockOnly,
            discountedOnly: $this->discountedOnly,
            comparableOnly: $this->comparableOnly,
            sort: $this->sort,
            page: 1,
            view: $this->view,
            logged: $this->logged,
            liveTerm: $this->liveTerm,
            recipients: $recipients,
            interests: $interests,
            occasions: $occasions,
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
            recipients: $this->recipients,
            interests: $this->interests,
            occasions: $this->occasions,
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
            recipients: $this->recipients,
            interests: $this->interests,
            occasions: $this->occasions,
        );
    }

    /**
     * The same search with a price range, in cents.
     *
     * Used when the words themselves carried a budget ("koptelefoon onder
     * 100"): GiftIntentParser takes it out of the term and it lands here, as
     * the same filter the price boxes set.
     */
    public function withPrices(?int $min, ?int $max): self
    {
        return new self(
            market: $this->market,
            term: $this->term,
            minPrice: $min,
            maxPrice: $max,
            merchantIds: $this->merchantIds,
            brands: $this->brands,
            inStockOnly: $this->inStockOnly,
            discountedOnly: $this->discountedOnly,
            comparableOnly: $this->comparableOnly,
            sort: $this->sort,
            page: $this->page,
            view: $this->view,
            logged: $this->logged,
            recipients: $this->recipients,
            interests: $this->interests,
            occasions: $this->occasions,
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
            || $this->comparableOnly
            || $this->hasTagFilters();
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

    /**
     * Stable key for this search's facet counts.
     *
     * Only market, term and in-stock are in it, because only those three reach
     * the facet queries — the counts deliberately ignore the active filters so
     * that selecting a brand does not erase every other brand from the sidebar.
     *
     * That is what makes caching them worth doing: every brand, price, merchant,
     * sort and page variant of one term shares this key, so refining a search
     * recomputes nothing. Getting the key wrong in the other direction would be
     * worse than not caching, so it is derived here next to the fields rather
     * than assembled at the call site.
     */
    public function facetCacheKey(): string
    {
        return 'bc:search:facets:'.$this->market->value
            .':'.($this->inStockOnly ? '1' : '0')
            .':'.sha1(mb_strtolower($this->term));
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
            // One value as a plain parameter (?for=father), several as a list.
            'for' => self::param($this->recipients),
            'interest' => self::param($this->interests),
            'occasion' => self::param($this->occasions),
            'sort' => $this->sort !== 'relevance' ? $this->sort : null,
            'view' => $this->view !== 'grid' ? $this->view : null,
            'page' => $this->page > 1 ? $this->page : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * @param  list<string>  $values
     * @return string|list<string>|null
     */
    private static function param(array $values): string|array|null
    {
        return match (count($values)) {
            0 => null,
            1 => $values[0],
            default => $values,
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ProductGroup;
use App\Services\Search\SearchQuery;
use App\Services\Search\SearchResult;
use App\Services\Search\SearchService;
use App\Services\Seo\PageMeta;
use App\Services\Seo\StructuredData;
use App\Support\CurrentMarket;
use Illuminate\Http\Request;
use Illuminate\Support\Number;
use Inertia\Inertia;
use Inertia\Response;

class SearchController extends Controller
{
    public function __invoke(Request $request, CurrentMarket $current, SearchService $search): Response
    {
        $query = SearchQuery::fromRequest($request, $current->get());
        $result = $search->search($query);

        $this->seo($query, $result, $current);

        return Inertia::render('Search', [
            'q' => $query->term,
            'filters' => $query->toArray(),
            'sort' => $query->sort,
            'view' => $query->view,
            'facets' => $result->facets,
            'results' => $this->present($result),
            'lanes' => $query->view === 'store'
                ? $this->presentLanes($search->storeLanes($query))
                : null,
            'intro' => $this->intro($query, $result),
            'emptyBecauseOfFilters' => $result->emptyBecauseOfFilters(),
        ]);
    }

    /**
     * Search-page SEO.
     *
     * Crawl budget is the real concern here, not ranking. Every filter
     * combination is a distinct URL, and a facet UI generates a combinatorial
     * explosion of them — left indexable, a crawler spends its entire budget on
     * near-identical filtered pages and never reaches the product and guide
     * pages that are actually worth ranking.
     *
     * So: the bare landing page is indexable; anything filtered, sorted or
     * paginated is `noindex, follow`. Links are still followed, so products are
     * still discovered through them.
     */
    private function seo(SearchQuery $query, SearchResult $result, CurrentMarket $current): void
    {
        $thin = $query->hasFilters()
            || $query->page > 1
            || $query->sort !== 'relevance'
            || $result->isEmpty();

        app(PageMeta::class)
            ->set(
                title: $query->hasTerm()
                    ? __('site.search.results_for', ['term' => $query->term])
                    : __('site.search.title'),
                description: $query->hasTerm()
                    ? __('site.search.seo_term', ['term' => $query->term, 'count' => $result->groups->total()])
                    : __('site.search.seo_default'),
                // Canonical points at the unfiltered term, so any ranking signal
                // a filtered variant picks up consolidates onto one URL.
                canonical: url($current->url('search')).($query->hasTerm() ? '?q='.urlencode($query->term) : ''),
                robots: $thin ? 'noindex, follow' : null,
            )
            ->addJsonLd(StructuredData::website(url('/'), $current->get()));
    }

    /**
     * Indexable prose above the results, built from what the query actually found.
     *
     * A results grid is almost pure markup — product titles, prices, a filter
     * rail. There is nothing on it for a crawler to understand the page *about*,
     * which is why comparison sites rank for their guides and not for their
     * search pages.
     *
     * The copy states real, page-specific facts: how many products, how many
     * shops, the price range, the leading brands. Not filler with the keyword
     * stuffed in — every clause here is a number the page can back up, which is
     * both the only version worth writing and the only version that survives a
     * helpful-content update.
     *
     * Null on thin pages. A paginated or filtered variant is `noindex` anyway,
     * and repeating this text across them would be the doorway-page pattern.
     *
     * @return array{lead: string, detail: string|null}|null
     */
    private function intro(SearchQuery $query, SearchResult $result): ?array
    {
        if (! $query->hasTerm() || $result->isEmpty() || $query->page > 1 || $query->hasFilters()) {
            return null;
        }

        $total = $result->groups->total();
        $items = $result->groups->items();

        $prices = array_values(array_filter(array_map(fn ($g) => $g->min_price, $items)));
        $merchants = array_sum(array_map(fn ($g) => max(1, (int) $g->merchant_count), $items));

        $brands = array_values(array_unique(array_filter(array_map(fn ($g) => $g->brand, $items))));

        $lead = __('site.search.intro_lead', [
            'term' => $query->term,
            'count' => $total,
            'shops' => $merchants,
        ]);

        $detail = null;

        if ($prices !== []) {
            $detail = __('site.search.intro_prices', [
                'term' => $query->term,
                'low' => $this->money(min($prices), $query),
                'high' => $this->money(max($prices), $query),
            ]);
        }

        if ($brands !== []) {
            $detail = trim(($detail ?? '').' '.__('site.search.intro_brands', [
                'brands' => implode(', ', array_slice($brands, 0, 5)),
            ]));
        }

        return ['lead' => $lead, 'detail' => $detail];
    }

    private function money(int $cents, SearchQuery $query): string
    {
        return Number::currency(
            $cents / 100,
            $query->market->currency(),
            $query->market->hrefLang(),
        );
    }

    /** @return array<string, mixed> */
    private function present(SearchResult $result): array
    {
        return [
            'total' => $result->groups->total(),
            'currentPage' => $result->groups->currentPage(),
            'lastPage' => $result->groups->lastPage(),
            'items' => array_map($this->card(...), $result->groups->items()),
        ];
    }

    /** @param array<string, list<ProductGroup>> $lanes */
    private function presentLanes(array $lanes): array
    {
        return array_map(
            fn (array $groups) => array_map($this->card(...), $groups),
            $lanes,
        );
    }

    /**
     * The offer-comparison card.
     *
     * Everything a shopper needs to decide whether to open the product: what it
     * is, the cheapest price, how many shops have it, and whether that price is
     * actually a good one.
     *
     * @return array<string, mixed>
     */
    private function card(ProductGroup $group): array
    {
        return [
            'id' => $group->id,
            'title' => $group->title,
            'slug' => $group->slug,
            'brand' => $group->brand,
            'image' => $group->image_url,
            // Cents cross the wire exactly as stored; the client formats them
            // for the market, so a float never enters the pipeline.
            'minPrice' => $group->min_price,
            'maxPrice' => $group->max_price,
            'offerCount' => $group->offer_count,
            'merchantCount' => $group->merchant_count,
            'inStock' => $group->in_stock,
            'discountPercent' => $group->discountPercent(),
        ];
    }
}

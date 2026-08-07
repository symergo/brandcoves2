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

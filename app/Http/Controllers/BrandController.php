<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BrandStat;
use App\Models\Guide;
use App\Models\ProductGroup;
use App\Services\Search\SearchQuery;
use App\Services\Search\SearchService;
use App\Services\Seo\BrandCopy;
use App\Services\Seo\PageMeta;
use App\Services\Seo\StructuredData;
use App\Support\CurrentMarket;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A brand page.
 *
 * ## It is a search page, deliberately
 *
 * `/{market}/brand/sony` runs the same `SearchService` with the brand filter
 * preselected and renders the same React page. Not a parallel implementation:
 * the filter rail, the by-shop lanes, the sort, the pagination and the offer
 * cards all already exist and all already work, and a second copy of them would
 * drift within a month.
 *
 * What the brand page adds is what a bare filtered search cannot have —
 * **indexability and prose**. `?brand[]=Sony` is `noindex` because facet URLs
 * are a crawl-budget trap; `/brand/sony` is one canonical URL per brand per
 * market, with paragraphs of checkable copy above the results and links out to
 * the Coves that mention the brand.
 *
 * ## Why the slug is looked up rather than computed
 *
 * `brand_stats.slug` is written by the same `Str::slug()` that builds the links,
 * so the link and the lookup cannot disagree. Folding the slug back to a brand
 * name in SQL would mean reimplementing transliteration in Postgres, and every
 * Kärcher link would 404.
 */
class BrandController extends Controller
{
    /**
     * `string $marketSegment` is declared and unused on purpose.
     *
     * Laravel splices resolved class dependencies in at their parameter position
     * and then passes the remaining route parameters positionally, in the order
     * the URI declares them. `{market}` comes first, so a signature that omits it
     * hands "be-nl" to `$slug` and every brand page 404s — silently, because the
     * lookup simply finds nothing. Same reason `GuideController::show()` declares
     * it. The market itself comes from `CurrentMarket`, which the middleware has
     * already validated.
     */
    public function show(Request $request, CurrentMarket $current, SearchService $search, string $marketSegment, string $slug): Response
    {
        $market = $current->get();

        $stat = BrandStat::query()
            ->forMarket($market)
            ->pageworthy()
            ->where('slug', $slug)
            ->with('topMerchant')
            ->first();

        if ($stat === null) {
            /*
             * An honest 404, including for a brand that has dropped below the
             * three-product threshold. A page with one product on it cannot
             * support paragraphs about a brand, and publishing it anyway is the
             * doorway-page pattern that discounts a whole domain.
             */
            throw new NotFoundHttpException("No brand page for '{$slug}' in {$market->value}.");
        }

        // The brand comes from the resolved row, never from the URL, so the
        // filter can only ever be a brand that exists in this market.
        $query = SearchQuery::fromRequest($request, $market)->withBrand($stat->brand);
        $result = $search->search($query);

        $this->seo($stat, $result->groups->total(), $current, $query);

        return Inertia::render('Brand', [
            'brand' => [
                'name' => $stat->brand,
                'slug' => $stat->slug,
                'productCount' => $stat->product_count,
                'merchantCount' => $stat->merchant_count,
                'discountedCount' => $stat->discounted_count,
                'minPrice' => $stat->min_price,
                'maxPrice' => $stat->max_price,
                'category' => $stat->top_category,
                'topMerchant' => $stat->topMerchant?->name,
            ],
            'copy' => app(BrandCopy::class)->forBrand($stat, $market),
            'filters' => $query->toArray(),
            'sort' => $query->sort,
            'view' => $query->view,
            'facets' => $result->facets,
            'results' => [
                'total' => $result->groups->total(),
                'currentPage' => $result->groups->currentPage(),
                'lastPage' => $result->groups->lastPage(),
                'items' => array_map($this->card(...), $result->groups->items()),
            ],
            // Editorial, written by the AI pass, that happens to mention this
            // brand. The templated copy above carries the facts; this is where
            // any personality on the page comes from.
            'coves' => $this->coves($stat, $current),
            'related' => $this->related($stat, $current),
        ]);
    }

    /**
     * The brand index.
     *
     * Exists so brand pages are reachable by a crawler that has not seen a
     * search result — an orphaned URL space is one Google finds slowly and
     * trusts less, however good the individual pages are.
     */
    public function index(CurrentMarket $current): Response
    {
        $market = $current->get();

        $brands = BrandStat::query()
            ->forMarket($market)
            ->pageworthy()
            ->orderByDesc('product_count')
            ->limit(500)
            ->get(['brand', 'slug', 'product_count']);

        app(PageMeta::class)->set(
            title: __('site.brand.index_title'),
            description: __('site.brand.index_intro'),
            canonical: url($current->url('brands')),
        );

        return Inertia::render('Brands', [
            'brands' => $brands->map(fn (BrandStat $stat) => [
                'name' => $stat->brand,
                'url' => $current->url("brand/{$stat->slug}"),
                'count' => $stat->product_count,
            ])->all(),
        ]);
    }

    /**
     * Coves that mention this brand.
     *
     * Two sources, because they answer slightly different questions and both are
     * worth linking:
     *
     *  - A Cove whose prose contains `[[brand:X]]` — the writer chose to name it.
     *  - A Cove that features one of the brand's products — structural, and true
     *    even if the prose never spells the brand out.
     *
     * The token match is a LIKE on `body_md`. Not elegant, and correct: the
     * tokens are a closed syntax written by our own builder, so there is no
     * false-positive risk beyond a brand whose name appears inside another
     * brand's token, which the delimiters rule out.
     *
     * @return list<array<string, mixed>>
     */
    private function coves(BrandStat $stat, CurrentMarket $current): array
    {
        $token = '%[[brand:'.$stat->brand.']]%';
        $tokenLabelled = '%[[brand:'.$stat->brand.'|%';

        $featured = Guide::query()
            ->forMarket($current->get())
            ->published()
            ->whereHas('items.group', fn ($q) => $q->where('brand', $stat->brand))
            ->pluck('id');

        return Guide::query()
            ->forMarket($current->get())
            ->published()
            ->where(fn ($q) => $q
                ->whereIn('id', $featured)
                ->orWhere('body_md', 'like', $token)
                ->orWhere('body_md', 'like', $tokenLabelled))
            ->orderByDesc('published_at')
            ->limit(4)
            ->get(['slug', 'title', 'intro'])
            ->map(fn (Guide $guide) => [
                'title' => $guide->title,
                'intro' => $guide->intro,
                'url' => $current->url("guides/{$guide->slug}"),
            ])
            ->all();
    }

    /**
     * Other brands in the same category.
     *
     * The internal-link half of the job. A brand page with no outbound links to
     * sibling brands is a leaf, and a crawler that reaches a leaf stops. Same
     * category rather than "most popular", because a shopper comparing kettles
     * wants other kettle brands, not the biggest brand in the catalogue.
     *
     * @return list<array<string, mixed>>
     */
    private function related(BrandStat $stat, CurrentMarket $current): array
    {
        if ($stat->top_category === null) {
            return [];
        }

        return BrandStat::query()
            ->forMarket($current->get())
            ->pageworthy()
            ->where('top_category', $stat->top_category)
            ->where('id', '!=', $stat->id)
            ->orderByDesc('product_count')
            ->limit(12)
            ->get(['brand', 'slug', 'product_count'])
            ->map(fn (BrandStat $other) => [
                'name' => $other->brand,
                'url' => $current->url("brand/{$other->slug}"),
                'count' => $other->product_count,
            ])
            ->all();
    }

    /**
     * Brand-page SEO.
     *
     * The whole reason this route exists rather than a filtered search URL: one
     * canonical URL per brand per market, indexable, with the filter and sort
     * variants of it pointing back here.
     *
     * Pagination is `noindex, follow` beyond page one — page 12 of a brand's
     * products has nothing to rank for and everything to spend crawl budget on —
     * but the canonical still names the bare page, so any signal consolidates.
     */
    private function seo(BrandStat $stat, int $total, CurrentMarket $current, SearchQuery $query): void
    {
        $thin = $query->page > 1
            || $query->minPrice !== null
            || $query->maxPrice !== null
            || $query->merchantIds !== []
            || $query->sort !== 'relevance';

        app(PageMeta::class)
            ->set(
                title: __('site.brand.title', ['brand' => $stat->brand]),
                description: __('site.brand.seo_description', [
                    'brand' => $stat->brand,
                    'count' => $total,
                ]),
                canonical: url($current->url("brand/{$stat->slug}")),
                robots: $thin ? 'noindex, follow' : null,
            )
            ->addJsonLd(StructuredData::brand(
                $stat->brand,
                url($current->url("brand/{$stat->slug}")),
            ));
    }

    /** @return array<string, mixed> */
    private function card(ProductGroup $group): array
    {
        return [
            'id' => $group->id,
            'title' => $group->title,
            'slug' => $group->slug,
            'brand' => $group->brand,
            'image' => $group->image_url,
            'minPrice' => $group->min_price,
            'maxPrice' => $group->max_price,
            'offerCount' => $group->offer_count,
            'merchantCount' => $group->merchant_count,
            'inStock' => $group->in_stock,
            'discountPercent' => $group->discountPercent(),
        ];
    }
}

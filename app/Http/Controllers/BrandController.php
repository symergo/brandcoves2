<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Availability;
use App\Enums\Market;
use App\Models\BrandStat;
use App\Models\Guide;
use App\Models\ProductGroup;
use App\Services\Connectors\Offer;
use App\Services\Search\SearchQuery;
use App\Services\Search\SearchResult;
use App\Services\Search\SearchService;
use App\Services\Seo\PageMeta;
use App\Services\Seo\PageNarrative;
use App\Services\Seo\ResultTerms;
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
 * market, with paragraphs of checkable copy below the results and links out to
 * the Coves that mention the brand.
 *
 * Above the results there is now only the brand's own vocabulary, as links. The
 * templated statistics that used to open the page said nothing a reader could
 * not see by looking at the grid under them.
 *
 * ## The live sources are asked too
 *
 * A brand page used to show the stored index and nothing else, because the live
 * half of `SearchService` fires on a search *term* and a brand page has none. So
 * every brand page was Awin-only, and bol — which stocks a great deal that no
 * Awin advertiser carries — was invisible on the one page dedicated to the brand.
 *
 * It now sends the brand's name as the live query. See `liveTerm()` for why a
 * keyword search is the only question these APIs answer, and `BrandAttribution`
 * for how the answers are tied back to the brand.
 *
 * Amazon joins the moment its connector is registered, without a change here: the
 * registry decides which sources exist, and the one thing that differs about
 * Amazon — that it may not be mirrored — is handled in `SearchService`, which
 * hands those offers back to be rendered live instead of stored.
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
        $query = SearchQuery::fromRequest($request, $market);
        $query = $query->withBrands($stat->brandSpellings(), liveTerm: $this->liveTerm($stat, $query));

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
            /*
             * The words that come up across this brand's products, each linking
             * to a search of its own. What used to sit here was four paragraphs
             * of templated statistics — see terms() below.
             */
            'terms' => $this->terms($stat, $result, $query, $market, $current),
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
            /*
             * Live sources that may not be mirrored — Amazon. Everything bol
             * returned is already in `results` above, folded into the grid as
             * extra offers on existing cards or as cards of its own; these are
             * the ones nothing is allowed to write down, so they are rendered
             * from this request's own fetch and are gone when it ends.
             */
            'liveOffers' => array_map($this->liveCard(...), $result->liveOffers),

            // Editorial, written by the AI pass, that happens to mention this
            // brand. The narrative below carries the facts; this is where any
            // personality on the page comes from.
            'coves' => $this->coves($stat, $current),
            'related' => $this->related($stat, $current),

            // The long copy, below the grid. Same reasoning as the search page:
            // a results grid has nothing on it for a crawler to understand the
            // page as being about, and a shopper should not have to scroll past
            // three hundred words to reach a product.
            'narrative' => $this->narrative($stat, $result, $query, $market),
        ]);
    }

    /**
     * What bol and Amazon get asked about this brand.
     *
     * ## Why the brand's name and not a filter
     *
     * The stored half of this page is a brand filter: `whereIn('brand', ...)`
     * across every spelling, which is exact. The live sources have no equivalent
     * — bol's catalogue API and Amazon's PA-API both take a search term and
     * neither takes a brand — so the only way to ask them about Kärcher is to
     * search for the word. That is the whole mechanism: **a brand page is a
     * keyword search upstream and a filter downstream.**
     *
     * What comes back is therefore approximate, and two things narrow it again.
     * `BrandAttribution` decides which results actually belong to the brand, and
     * offers that are stored then meet the same SQL filter as everything else.
     *
     * ## Only the canonical page asks
     *
     * Page two is `noindex` and is being read by someone who has already
     * scrolled past everything a live source could add. A sub-search — `?q=`,
     * which the term chips now build up a word at a time — is worse: the chips
     * are a combinatorial URL space over the pages that are already the site's
     * crawl target, and a crawler walking it would fire one upstream search per
     * URL. bol's rate limiter would clamp that, which means background crawling
     * would be starving live visitors of the same bucket.
     *
     * It costs less than it looks. The bare page's pull has already folded bol's
     * offers for this brand into the index, and a sub-search filters that index
     * — so the narrowed page shows the live results, it just does not go and ask
     * for them a second time under a longer query.
     *
     * An empty string is "ask nobody", and it is distinct from null: null falls
     * back to `$term`, which would start querying again on exactly the URLs this
     * is protecting.
     */
    private function liveTerm(BrandStat $stat, SearchQuery $query): string
    {
        return $query->page > 1 || $query->hasTerm() ? '' : $stat->brand;
    }

    /**
     * The vocabulary of this brand's products, each word linking to its own search.
     *
     * ## What this replaced
     *
     * `BrandCopy` — four templated paragraphs above the grid stating the product
     * count, the categories, the shop count, the price range and how many items
     * were below their 30-day median. Every clause was a number the catalogue
     * could back up, and that rule still stands where the prose survives: the
     * long copy below the grid, and the brand's Coves.
     *
     * Above the grid it had stopped earning its space. Someone who has typed a
     * brand name wants to see the brand's products, and the statistics were a
     * screen of arithmetic about the grid immediately beneath them.
     *
     * ## Why words instead
     *
     * "pressure washer", "cordless", "window vac" say what a brand actually
     * makes in a way a price range cannot, and each one is a live query rather
     * than a sentence. The words come off the titles on the page — never
     * generated, see ResultTerms — and the brand's own name is passed in as the
     * query so it is excluded: a Kärcher page listing "kärcher" as a related
     * term is a link back to itself.
     *
     * ## They narrow this page rather than leaving it
     *
     * **Changed 2026-08-11.** These used to link to `/search?q=<word>` — the bare
     * word, without the brand, deliberately: "pressure washer" would reach every
     * brand that makes one, which is the comparison the site is for.
     *
     * The trouble is where the reader is standing. Someone on a Kärcher page
     * looking at the word "hogedrukreiniger" is not asking to be shown Bosch;
     * they are asking *which Kärchers are the pressure washers*. The old link
     * answered a question nobody on that page had asked, and it threw away the
     * brand they had already chosen. A word under a brand heading reads as a
     * filter, and it now behaves like one: `/brand/karcher?q=hogedrukreiniger`.
     *
     * The wider search did not disappear — it is what the search box, the
     * related-search chips under the narrative, and every card's own title link
     * are for.
     *
     * ## The word is added, not swapped in
     *
     * Each click **adds** its word to whatever is already being sub-searched, so
     * the terms keep narrowing: `?q=hogedrukreiniger`, then
     * `?q=hogedrukreiniger accu`. The words come off the titles that survived the
     * previous click, so every suggestion is one the current result set can
     * still answer — a narrowing path that cannot dead-end, which is exactly what
     * a term that swapped the previous one out could not offer.
     *
     * The combinatorial URL space that follows is why every `?q=` variant of a
     * brand page is `noindex, follow` and canonicalises to the bare page — see
     * seo(). Those are the pages the crawler must not spend its budget on, and
     * they are precisely the ones a shopper wants.
     *
     * Empty beyond page one. Repeating one block of internal links across every
     * page of a brand's catalogue is the doorway-page pattern with fewer words.
     * A sub-searched page keeps its terms, which is what makes the next
     * narrowing step possible at all.
     *
     * @return list<array{term: string, url: string}>
     */
    private function terms(BrandStat $stat, SearchResult $result, SearchQuery $query, Market $market, CurrentMarket $current): array
    {
        if ($query->page > 1 || $result->isEmpty()) {
            return [];
        }

        $terms = app(ResultTerms::class)->extract(
            $result->groups->items(),
            $market,
            // The brand and whatever is already being sub-searched, so neither
            // comes back as a suggestion: both are the page you are on, and a
            // word already in `q` would add nothing when clicked.
            trim($stat->brand.' '.$query->term),
        );

        $base = $current->url("brand/{$stat->slug}");

        return array_map(fn (string $term) => [
            'term' => $term,
            'url' => $base.'?q='.urlencode(trim($query->term.' '.$term)),
        ], $terms);
    }

    /**
     * @return array{sections: list<array{heading: string, body: list<string>}>, faq: list<array{q: string, a: string}>, related: list<array{term: string, url: string}>}|null
     */
    private function narrative(BrandStat $stat, SearchResult $result, SearchQuery $query, Market $market): ?array
    {
        // Only on the canonical page. A sorted, paginated or sub-searched variant
        // is noindex, and repeating several hundred words across them is the
        // doorway-page pattern.
        if ($query->page > 1 || $query->sort !== 'relevance' || $query->hasTerm() || $result->isEmpty()) {
            return null;
        }

        $narrative = app(PageNarrative::class)->forBrand(
            $stat->brand,
            $result->groups->items(),
            $market,
            $result->groups->total(),
            $stat->topMerchant?->name,
            $stat->top_category,
            array_values(array_filter(array_map(
                fn ($row) => is_array($row) ? ($row['category'] ?? null) : null,
                (array) $stat->categories,
            ))),
        );

        if ($narrative['faq'] !== []) {
            app(PageMeta::class)->addJsonLd(StructuredData::faq($narrative['faq']));
        }

        return $narrative;
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
            title: __('site.brand.index_seo_title'),
            description: __('site.brand.index_seo_description'),
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
        $spellings = $stat->brandSpellings();

        $featured = Guide::query()
            ->forMarket($current->get())
            ->published()
            ->whereHas('items.group', fn ($q) => $q->whereIn('brand', $spellings))
            ->pluck('id');

        return Guide::query()
            ->forMarket($current->get())
            ->published()
            ->where(function ($q) use ($featured, $spellings) {
                $q->whereIn('id', $featured);

                // Every spelling, because a Cove's allowlist was built from
                // whichever spelling the feed behind that product used.
                foreach ($spellings as $spelling) {
                    $q->orWhere('body_md', 'like', '%[[brand:'.$spelling.']]%')
                        ->orWhere('body_md', 'like', '%[[brand:'.$spelling.'|%');
                }
            })
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
            || $query->sort !== 'relevance'
            /*
             * A sub-search. The term links narrow this page rather than leaving
             * it, and each click adds a word, so `?q=` is now the widest source
             * of URL variants a brand page has — the exact crawl-budget trap
             * `/search?brand[]=` is noindex for. `follow`, because the results
             * under it are real product pages worth reaching.
             */
            || $query->hasTerm();

        app(PageMeta::class)
            ->set(
                title: __('site.brand.title', ['brand' => $stat->brand]),
                description: __('site.brand.seo_description', [
                    'brand' => $stat->brand,
                    'count' => $total,
                ]),
                image: url($current->url("og/brand/{$stat->slug}.png")),
                canonical: url($current->url("brand/{$stat->slug}")),
                robots: $thin ? 'noindex, follow' : null,
            )
            ->addJsonLd(StructuredData::brand(
                $stat->brand,
                url($current->url("brand/{$stat->slug}")),
            ));
    }

    /**
     * An offer we may show but not store.
     *
     * Deliberately not a `GroupCard`: it has no group, no id, no offer count and
     * no discount, because all four of those are things the catalogue computes
     * for rows it holds. Rendering it through the same component would mean
     * inventing them.
     *
     * `needsPriceTimestamp` and `directLink` carry the programme's own
     * conditions to the page — an Amazon price must say when it was read, and an
     * Associates link must be an unobscured anchor rather than a trip through
     * `/go/`. Read off `Source` rather than hard-coded, so a second such source
     * inherits its own answer.
     *
     * @return array<string, mixed>
     */
    private function liveCard(Offer $offer): array
    {
        return [
            'title' => $offer->title,
            'url' => $offer->affiliateUrl,
            'image' => $offer->imageUrl,
            'price' => $offer->price,
            'merchant' => $offer->merchantName ?? $offer->source->label(),
            'inStock' => $offer->availability === Availability::InStock,
            'needsPriceTimestamp' => $offer->source->requiresPriceTimestamp(),
            'directLink' => $offer->source->requiresDirectLink(),
        ];
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

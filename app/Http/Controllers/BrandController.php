<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Availability;
use App\Enums\Market;
use App\Models\BrandStat;
use App\Models\DailyPickSet;
use App\Models\ProductGroup;
use App\Services\Connectors\Offer;
use App\Services\Search\SearchQuery;
use App\Services\Search\SearchResult;
use App\Services\Search\SearchService;
use App\Services\Seo\PageMeta;
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
 * **indexability and editorial**. `?brand[]=Sony` is `noindex` because facet
 * URLs are a crawl-budget trap; `/brand/sony` is one canonical URL per brand
 * per market, with the brand's own vocabulary above the results and links out
 * to the articles that mention it below them. The generated paragraphs that
 * used to fill that space went on 2026-08-16 — see coves().
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

        $this->seo($stat, $current, $query);

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
            'facets' => $result->facetsWithoutCounts(),
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

            /*
             * Editorial that mentions this brand — now the whole of what sits
             * below the grid. See coves() for what replaced the narrative and
             * why articles are a better answer than generated paragraphs.
             */
            'coves' => $this->coves($stat, $current),
            'related' => $this->related($stat, $current),
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

        /*
                 * `product_count` orders these and is not sent.
                 *
                 * The number decides *which* brands appear — most-stocked
                 * first — and says nothing worth printing beside one. A count
                 * next to a brand name describes our catalogue rather than the
                 * brand, and it is the number most likely to be stale: it is
                 * refreshed nightly while the grid under it is live.
                 */

        app(PageMeta::class)->set(
            title: __('site.brand.index_seo_title'),
            description: __('site.brand.index_seo_description'),
            canonical: url($current->url('brands')),
        );

        return Inertia::render('Brands', [
            'brands' => $brands->map(fn (BrandStat $stat) => [
                'name' => $stat->brand,
                'url' => $current->url("brand/{$stat->slug}"),
            ])->all(),
        ]);
    }

    /**
     * The articles that mention this brand.
     *
     * ## This is what the page carries below the grid now
     *
     * **Changed 2026-08-16.** What used to sit here was `PageNarrative` — three
     * columns of paragraphs and an FAQ, assembled from the same numbers the grid
     * above was already showing. Every clause was checkable, and that was the
     * whole problem: it was arithmetic about the products immediately above it,
     * written in sentences, on every one of a thousand brand pages. Nobody reads
     * the second brand page's version of it, and a crawler comparing two of them
     * sees one template with the nouns swapped.
     *
     * An article that mentions the brand is the opposite trade. It is a link to
     * something a reader might actually want, it was written once about a real
     * question, and it is an internal link into editorial rather than a
     * restatement of the page it sits on.
     *
     * The FAQ's `FAQPage` structured data went with it. Nothing is lost that was
     * true: the markup was only ever a description of the paragraphs that are
     * now gone, and structured data whose answer is not on the page is a
     * misrepresentation.
     *
     * ## Three ways an article counts as mentioning a brand
     *
     *  - It contains a `[[brand:X]]` token — the writer named it deliberately.
     *  - It features one of the brand's products — structural, and true even
     *    where the prose never spells the brand out.
     *  - Its title, intro or body says the name in plain text. **Added
     *    2026-08-16.** This is the one that makes the section non-empty in
     *    practice: an advice article has no shortlist to match structurally, and
     *    prose written about "the Sony over-ears" carries no token.
     *
     * The plain-text match is a word-boundary regex (`~*`, Postgres' `\y`), not
     * a LIKE. `%sony%` matches "Sonya" and "masonry"; a brand page linking to an
     * article about masonry is worse than a brand page linking to nothing. The
     * name is regex-escaped because brands contain metacharacters — "Fisher-
     * Price" is harmless, "M&M's" and "Dr. Oetker" are not, and an unescaped `.`
     * would match any character.
     *
     * Two-letter spellings are excluded from the plain-text match alone. "LG" is
     * a brand and also two letters that occur inside nothing, but the class of
     * short spellings is where a boundary match stops being evidence of anything
     * — an article containing the word "OK" is not about the brand OK. The token
     * and product matches carry no such limit, because both are exact.
     *
     * Unindexed, and deliberately: a market holds hundreds of published
     * articles, not millions, so the sequential scan is cheaper than the index
     * that would have to be maintained on every publish.
     *
     * @return list<array<string, mixed>>
     */
    private function coves(BrandStat $stat, CurrentMarket $current): array
    {
        $spellings = $stat->brandSpellings();

        $featured = DailyPickSet::query()
            ->forMarket($current->get())
            ->articles()
            ->published()
            ->whereHas('picks.group', fn ($q) => $q->whereIn('brand', $spellings))
            ->pluck('id');

        return DailyPickSet::query()
            ->forMarket($current->get())
            ->articles()
            ->published()
            ->where(function ($q) use ($featured, $spellings) {
                $q->whereIn('id', $featured);

                // Every spelling, because an article's allowlist was built from
                // whichever spelling the feed behind that product used.
                foreach ($spellings as $spelling) {
                    $q->orWhere('body', 'like', '%[[brand:'.$spelling.']]%')
                        ->orWhere('body', 'like', '%[[brand:'.$spelling.'|%');

                    if (mb_strlen($spelling) < 3) {
                        continue;
                    }

                    $pattern = $this->mentionPattern($spelling);

                    $q->orWhereRaw('theme_title ~* ?', [$pattern])
                        ->orWhereRaw('coalesce(theme_blurb, \'\') ~* ?', [$pattern])
                        ->orWhereRaw("coalesce(body, '') ~* ?", [$pattern]);
                }
            })
            ->orderByDesc('published_at')
            ->limit(6)
            ->get(['id', 'kind', 'slug', 'theme_title', 'theme_blurb'])
            ->map(fn (DailyPickSet $guide) => [
                'title' => $guide->theme_title,
                'intro' => $guide->theme_blurb,
                'url' => $current->url($guide->kind->path((string) $guide->slug)),
            ])
            ->all();
    }

    /**
     * "This article says this brand's name", as a Postgres regular expression.
     *
     * Two things it has to get right, and the second is the one that bites.
     *
     * **Escaping.** `preg_quote()` is the wrong tool: it escapes for PCRE and
     * emits `\#`, which POSIX ARE rejects as an undefined escape rather than
     * reading as a literal `#`. Escaping exactly the ARE metacharacters is the
     * whole job — and it is not optional, because an unescaped `(` in "Dr.
     * Oetker (NL)" is a syntax error Postgres raises at query time, i.e. a 500
     * on that brand's page.
     *
     * **Where the boundaries go.** `\y` matches *between* a word character and
     * a non-word one, so `\yDr\. Oetker \(NL\)\y` never matches anything: the
     * pattern already ends on `)`, and there is no boundary between `)` and the
     * space after it. Anchoring unconditionally silently empties the section
     * for every brand whose name is punctuated at either end. So each boundary
     * is added only on the side where the name actually starts or ends on a
     * word character.
     */
    private function mentionPattern(string $spelling): string
    {
        $escaped = preg_replace('/[.^$*+?()\[\]{}|\\\\]/', '\\\\$0', $spelling) ?? $spelling;

        $opens = preg_match('/^[\p{L}\p{N}_]/u', $spelling) === 1 ? '\y' : '';
        $closes = preg_match('/[\p{L}\p{N}_]$/u', $spelling) === 1 ? '\y' : '';

        return $opens.$escaped.$closes;
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
    private function seo(BrandStat $stat, CurrentMarket $current, SearchQuery $query): void
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
                // No count. `$total` was passed in for this one line and is
                // gone with it — see the note in `SearchController::seo()`.
                description: __('site.brand.seo_description', ['brand' => $stat->brand]),
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

            /*
             * What it takes to keep one of these.
             *
             * `WishlistItemController::store()` has accepted `source` +
             * `external_id` since live results became reachable, and
             * `ItemSaver::saveExternal()` decides per source what may be
             * stored — but this card emitted neither field, so the entire
             * external-save path was unreachable from the one page that renders
             * live offers. Passing the snapshot fields is safe because the
             * server discards them for a source that may not be mirrored
             * (invariant #6); they are hints, not instructions.
             */
            'source' => $offer->source->value,
            'externalId' => $offer->externalId,
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

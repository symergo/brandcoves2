<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AmazonProduct;
use App\Models\Event;
use App\Models\ProductGroup;
use App\Services\Search\AmazonLink;
use App\Services\Search\SearchQuery;
use App\Services\Search\SearchResult;
use App\Services\Search\SearchService;
use App\Services\Seo\BrandLinker;
use App\Services\Seo\PageMeta;
use App\Services\Seo\PageNarrative;
use App\Services\Seo\ResultTerms;
use App\Services\Seo\StructuredData;
use App\Support\CurrentMarket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SearchController extends Controller
{
    public function __invoke(Request $request, CurrentMarket $current, SearchService $search): Response|RedirectResponse
    {
        $query = SearchQuery::fromRequest($request, $current->get());

        /*
         * A pasted Amazon URL is a search term too.
         *
         * Handled here rather than in a second input, because the box someone
         * pastes into is whichever one is nearest, and that is the search field.
         */
        $link = AmazonLink::parse($query->term);

        if ($link !== null) {
            $known = $this->knownAsin($link);

            if (($landing = $this->productFor($known, $current)) !== null) {
                return redirect()->to($landing);
            }

            /*
             * The classified title beats the URL slug when we have it: it is the
             * product's real name rather than a marketing string with the colour
             * and the pack size welded on.
             */
            $query = $query->withTerm($known?->classified_title ?: $link->terms);
        }

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
            /*
             * The words that come up across these results, each a search of its
             * own. What used to sit here was four paragraphs of statistics.
             */
            'terms' => $this->terms($query, $result, $current),
            'emptyBecauseOfFilters' => $result->emptyBecauseOfFilters(),

            /*
             * What we made of a pasted link, if the box held one.
             *
             * Shown to the visitor because the query they typed is not the query
             * that ran, and a page of results under a URL they pasted is
             * otherwise unreadable — they cannot tell whether we found *that*
             * product or something that happens to share a word with it.
             */
            'pastedLink' => $this->pastedLink($link, $query),

            /*
             * Brand pages for the brands on this page.
             *
             * Resolved server-side rather than slugified in the browser: not
             * every brand has a page (three products minimum), and a client-side
             * slug both links to 404s and disagrees with the stored slug the
             * moment transliteration is involved — "Kärcher" folds to "karcher"
             * in PHP and to something else in whatever the browser does.
             */
            'brandLinks' => $this->brandLinks($result, $current),

            /*
             * The long copy, below the grid.
             *
             * A grid of cards is almost pure markup; strip the prices and titles
             * and there is nothing for a search engine to decide the page is
             * *about*. This is what makes the page legible as a document — and
             * it goes below the products, because three hundred words between a
             * shopper and the first card is a worse page for them and for Google.
             *
             * Null on the same pages the intro is null on: a filtered variant is
             * noindex anyway, and repeating several hundred words across dozens
             * of near-identical URLs is the doorway-page pattern at scale.
             */
            'narrative' => $this->narrative($query, $result),
        ]);
    }

    /**
     * What we already know about a pasted ASIN.
     *
     * `amazon_products` is the decision store the compliance rule requires: the
     * ASIN, what we classified it as, and the identity it resolved to. One hit on
     * a unique index, and only when the URL actually carried an ASIN.
     *
     * Empty until the connector runs, which is why the slug path is the one that
     * works today and not a fallback.
     */
    private function knownAsin(AmazonLink $link): ?AmazonProduct
    {
        return $link->asin === null
            ? null
            : AmazonProduct::query()->where('asin', $link->asin)->first();
    }

    /**
     * The product page for a pasted ASIN, when we hold the same physical product.
     *
     * `amazon_products.identity_key` is the bridge: it is the same key
     * `product_groups` is unique on per market, so an Amazon product we have
     * classified points straight at the group the other shops' offers hang off.
     * That page is the answer to the question the paste was asking.
     *
     * Market-scoped, per invariant 2. A group in another market is a different
     * product with different tax and shipping, and landing someone on it would
     * be showing them a price they cannot pay.
     */
    private function productFor(?AmazonProduct $known, CurrentMarket $current): ?string
    {
        if ($known?->identity_key === null) {
            return null;
        }

        $group = ProductGroup::query()
            ->forMarket($current->get())
            ->where('identity_key', $known->identity_key)
            ->first();

        return $group === null
            ? null
            : $current->url("p/{$group->id}/{$group->slug}");
    }

    /**
     * @return array{asin: string|null, terms: string, shortlink: bool, usable: bool}|null
     */
    private function pastedLink(?AmazonLink $link, SearchQuery $query): ?array
    {
        if ($link === null) {
            return null;
        }

        /*
         * Recorded like a barcode miss, and for the same reason: someone has
         * told us a product exists and that we could not identify it, which is a
         * supply gap worth counting.
         *
         * The ASIN and the outcome, never the URL. A pasted Amazon link carries
         * `ref=` breadcrumbs and occasionally a session identifier, and none of
         * that belongs in an analytics table with a 90-day life.
         */
        Event::record('amazon_paste', [
            'market' => $query->market->value,
            'asin' => $link->asin,
            'shortlink' => $link->shortlink,
            'searched' => $link->isUsable(),
        ]);

        return [
            'asin' => $link->asin,
            'terms' => $link->terms,
            'shortlink' => $link->shortlink,
            'usable' => $link->isUsable(),
        ];
    }

    /**
     * @return array{sections: list<array{heading: string, body: list<string>}>, faq: list<array{q: string, a: string}>, related: list<array{term: string, url: string}>}|null
     */
    private function narrative(SearchQuery $query, SearchResult $result): ?array
    {
        if (! $query->hasTerm() || $result->isEmpty() || $query->page > 1 || $query->hasFilters()) {
            return null;
        }

        $narrative = app(PageNarrative::class)->forSearch(
            $query->term,
            $result->groups->items(),
            $query->market,
            $result->groups->total(),
        );

        // Rendered as FAQPage as well as visible text. Both halves are required:
        // structured data whose answer is not on the page is a misrepresentation,
        // and search engines have started treating it as one.
        if ($narrative['faq'] !== []) {
            app(PageMeta::class)->addJsonLd(StructuredData::faq($narrative['faq']));
        }

        return $narrative;
    }

    /**
     * Brand name → brand page URL, for the brands present in these results.
     *
     * One query for the whole page. Keyed by the lowercase name so the client can
     * look up a card's brand without caring about the feed's capitalisation.
     *
     * @return array<string, string>
     */
    private function brandLinks(SearchResult $result, CurrentMarket $current): array
    {
        $brands = array_map(fn (ProductGroup $group) => $group->brand, $result->groups->items());

        foreach ($result->facets['brands'] ?? [] as $facet) {
            $brands[] = $facet['value'] ?? null;
        }

        return app(BrandLinker::class)->urls($brands, $current->get());
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
     * The words that come up across these results, each linking to its own search.
     *
     * ## What this replaced
     *
     * Four paragraphs of statistics: how many products, how many shop listings,
     * the price range, how many were below their 30-day median, how many were
     * comparable. Every clause was true and checkable — and all of it counted
     * things the reader could already see. A block of numbers describing the
     * grid directly beneath it is not a reason to stop scrolling, and it pushed
     * the first product row down by most of a screen.
     *
     * ## Why the vocabulary survived the cut
     *
     * It was the one part that was not a restatement of the grid. "noise
     * cancelling", "over-ear", "bluetooth" tell a reader what *kind* of thing
     * this page holds, which the titles only imply. As links they do a second
     * job the prose never could: each one is a real query into the same index,
     * so the long-tail vocabulary of a category becomes navigation instead of a
     * comma-separated sentence. Extracted from the titles on the page, never
     * generated — see ResultTerms.
     *
     * ## They narrow the search rather than replacing it
     *
     * **Changed 2026-08-11.** Each link used to carry the bare word — "over-ear"
     * on its own rather than "koptelefoon over-ear" — on the reasoning that the
     * bare word is the whole category and the pair is the page already open.
     *
     * That was wrong about what the click means. Somebody reading "over-ear"
     * under a page of headphones is refining, not restarting: they want the
     * over-ear ones *of these*. Sending them to a fresh search for "over-ear"
     * discards the word they typed and answers a question they did not ask.
     *
     * So the word is **added**: `?q=koptelefoon over-ear`. The suggestions on the
     * next page are extracted from the titles that survived, so every one of them
     * is a word the current result set can still answer and the path cannot
     * dead-end in zero results. Widening is what the search box is for.
     *
     * ## Why the same guard as the narrative
     *
     * Empty on thin pages. A filtered or paginated variant is `noindex` anyway,
     * and repeating one block of internal links across dozens of near-identical
     * URLs is the doorway-page pattern with fewer words.
     *
     * @return list<array{term: string, url: string}>
     */
    private function terms(SearchQuery $query, SearchResult $result, CurrentMarket $current): array
    {
        if (! $query->hasTerm() || $result->isEmpty() || $query->page > 1 || $query->hasFilters()) {
            return [];
        }

        $terms = app(ResultTerms::class)->extract(
            $result->groups->items(),
            $query->market,
            $query->term,
        );

        $base = $current->url('search');

        return array_map(fn (string $term) => [
            'term' => $term,
            'url' => $base.'?q='.urlencode(trim($query->term.' '.$term)),
        ], $terms);
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

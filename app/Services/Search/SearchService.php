<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Jobs\PullLiveSearch;
use App\Models\Merchant;
use App\Models\ProductGroup;
use App\Models\SearchLog;
use App\Services\Connectors\ConnectorRegistry;
use App\Services\Connectors\LiveConnector;
use App\Services\Connectors\LiveRequest;
use App\Services\Connectors\Offer;
use App\Services\Connectors\PooledSearch;
use App\Services\Identity\Gtin;
use App\Services\Ingestion\IncomingGrouper;
use App\Services\Ingestion\OfferUpserter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Search over physical products, not offers.
 *
 * The whole point is that a shopper sees one card per product with every shop's
 * price beneath it — not eleven near-identical cards. So this queries
 * `product_groups` and joins offers for display, never the reverse.
 */
class SearchService
{
    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly OfferUpserter $upserter,
        private readonly BrandAttribution $attribution,
        private readonly IncomingGrouper $grouper,
    ) {}

    /**
     * @param  bool  $waitForLive  ask the live shops inside this call and fold
     *                             their offers before the query runs, instead of queueing
     *                             it. For the two callers where a person waits on exactly
     *                             this answer and the volume is tiny: an editor curating
     *                             in the admin and an author on the editorial API. Every
     *                             visitor-facing page leaves it false. See pullLiveResults().
     */
    public function search(SearchQuery $query, bool $waitForLive = false): SearchResult
    {
        // The live half is driven by `liveTerm()`, not by the term. A brand page
        // has no search term and still has something to ask bol: the brand's
        // name, because it takes no brand filter.
        $live = $query->hasLiveTerm()
            ? $this->pullLiveResults($query, $waitForLive)
            : ['written' => 0, 'unstored' => []];

        $ids = $this->resultIds($query);

        if ($query->logged && $query->hasTerm() && $query->page === 1) {
            // Logged with the size of the list, because zero-result queries are
            // the most valuable rows in the table — they are content gaps. The
            // list is capped per shop (resultIds()), so past zero this is a
            // floor, not a count; nothing public reads it as one.
            SearchLog::record($query->term, $query->market, count($ids));
        }

        return new SearchResult(
            groups: $this->page($query, $ids),
            query: $query,
            liveOffersAdded: $live['written'],
            // Deferred: only the pages with a filter rail read these. See
            // SearchResult::facets().
            facets: fn (): array => $this->facets($query),
            liveOffers: $live['unstored'],
            empty: $ids === [],
        );
    }

    /**
     * One page of results, served from the cached id list.
     *
     * ## Why ids are cached and not pages (designed 2026-09-01, built 2026-09-27)
     *
     * Every variant of a search re-ran the four-branch text union twice, once
     * for the count and once for the page, and the next page ran both again.
     * The ordered ids for one (market, term, filters, sort) are the same across
     * pages, the grid and the store view, and an Inertia visit and a full page
     * load, so they are cached once (`resultsCacheKey()`, `results_cache_ttl`)
     * and each page is a slice of them plus one primary-key lookup.
     *
     * Only ids, never the rendered groups: price, stock and the offer count on
     * each card are read fresh at every view, so the cheapest-offer and discount
     * badges (invariant 7) are exactly as right as they were.
     *
     * Kept 12 hours and retired the moment what they were computed from
     * changes (SearchGenerations): grouping for the market, the twice-daily
     * catalogue update, or a finished live fetch for the term. Between those
     * moments the stored query would return the same ids.
     *
     * ## No total (owner's decision, 2026-09-27)
     *
     * A simple paginator: this page and whether another follows, read off the
     * list. No `count(*)` runs and no number of results reaches the page; the
     * list is the whole answer, so there is no page past its end to ask the
     * database for.
     *
     * @param  list<int>  $ids
     * @return Paginator<int, ProductGroup>
     */
    private function page(SearchQuery $query, array $ids): Paginator
    {
        $perPage = (int) config('giftcoves.search.per_page');
        $offset = ($query->page - 1) * $perPage;

        // One more than a page: the paginator reads "is there a next page"
        // off whether it got it.
        return new Paginator(
            $this->hydrate(array_slice($ids, $offset, $perPage + 1)),
            $perPage,
            $query->page,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page'],
        );
    }

    /**
     * The ordered ids this search shows, at most `results_per_shop` per shop.
     *
     * ## Twenty-five per shop (owner's decision, 2026-09-27)
     *
     * A product counts toward the shop behind its best offer
     * (`product_groups.best_offer_id`), the one whose price the card leads
     * with and the one a click goes to. A product several shops sell counts
     * once, for that shop: the shop that makes it the best deal is the one
     * the list is about, and counting it against every seller would crowd
     * out the small shops the cap exists to make room for. Past 25 a shop's
     * further products are left out, and the ranking order is kept.
     *
     * Read from the first `results_scan_limit` ranked matches, as id and
     * shop, in one query; the capping is PHP. That bound is far above what
     * the caps let through for any realistic number of shops, and keeps a
     * one-letter search from reading the whole catalogue.
     *
     * Written even while this term's live fetch is queued. Until 2026-09-27
     * a two-minute marker kept it from being written then, or a new term
     * would have been cached without the shop's offers for the whole TTL. The
     * term generation made that marker redundant: the job bumps it when it
     * finishes, so whatever was cached while it was queued is retired at the
     * moment the offers exist, and not two minutes later.
     *
     * A plain list of ints, because the cache store rebuilds no objects.
     *
     * @return list<int>
     */
    private function resultIds(SearchQuery $query): array
    {
        $key = $query->resultsCacheKey();
        $hit = Cache::get($key);

        if (is_array($hit) && array_is_list($hit)) {
            return $hit;
        }

        $perShop = max(1, (int) config('giftcoves.search.results_per_shop', 25));

        $rows = $this->storedQuery($query)
            ->select('product_groups.id')
            ->selectSub(
                DB::table('products')
                    ->select('products.merchant_id')
                    ->whereColumn('products.id', 'product_groups.best_offer_id')
                    ->limit(1),
                'shop',
            )
            ->limit(max(1, (int) config('giftcoves.search.results_scan_limit', 3000)))
            ->toBase()
            ->get();

        $ids = [];
        $taken = [];

        foreach ($rows as $row) {
            // No best offer recorded (not expected for a priced product): no
            // shop to count it against, so it is not capped.
            if ($row->shop !== null) {
                $shop = (int) $row->shop;

                if (($taken[$shop] ?? 0) >= $perShop) {
                    continue;
                }

                $taken[$shop] = ($taken[$shop] ?? 0) + 1;
            }

            $ids[] = (int) $row->id;
        }

        Cache::put($key, $ids, (int) config('giftcoves.search.results_cache_ttl'));

        return $ids;
    }

    /**
     * The groups for these ids, in this order. A group merged away or deleted
     * since the ids were cached is simply absent.
     *
     * @param  list<int>  $ids
     * @return list<ProductGroup>
     */
    private function hydrate(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = ProductGroup::query()->whereIn('id', $ids)->get()->keyBy('id');

        return array_values(array_filter(array_map(fn (int $id) => $rows->get($id), $ids)));
    }

    /**
     * The ids the stored catalogue would return for this query, and nothing else.
     *
     * For a watched search (App\Jobs\CheckSearchAlerts): no live connectors, no
     * pagination, no facets, no logging — the question is only "which groups
     * match right now", asked once a day per watch.
     *
     * @return list<int>
     */
    public function matchingGroupIds(SearchQuery $query, int $limit): array
    {
        return $this->storedQuery($query)
            ->limit($limit)
            ->pluck('product_groups.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The ids of every product in this market whose words match the term,
     * whatever the filters: the same full-text and trigram union a search
     * runs, as a subquery.
     *
     * For the line above the results that counts what people keep
     * (SearchSignals): that line is about the words, not about this
     * particular filtered page.
     *
     * @return Builder<ProductGroup>
     */
    public function termMatches(SearchQuery $query): Builder
    {
        $groups = ProductGroup::query()->forMarket($query->market)->select('product_groups.id');

        $this->applyTextMatch($groups, $query);

        return $groups;
    }

    /** @return Builder<ProductGroup> */
    private function storedQuery(SearchQuery $query): Builder
    {
        $groups = ProductGroup::query()
            ->forMarket($query->market)
            ->whereNotNull('min_price')
            ->whereNotNull('image_url');

        if ($query->hasTerm()) {
            $this->applyTextMatch($groups, $query);
        }

        if ($query->inStockOnly) {
            $groups->where('in_stock', true);
        }

        if ($query->comparableOnly) {
            $groups->where('merchant_count', '>', 1);
        }

        if ($query->minPrice !== null) {
            $groups->where('min_price', '>=', $query->minPrice);
        }

        if ($query->maxPrice !== null) {
            $groups->where('min_price', '<=', $query->maxPrice);
        }

        if ($query->brands !== []) {
            $groups->whereIn('brand', $query->brands);
        }

        if ($query->merchantIds !== []) {
            // A group qualifies if ANY of its offers is from a selected shop —
            // the shopper is asking "who has this at Coolblue", not "which
            // products are Coolblue-exclusive".
            $groups->whereExists(fn ($q) => $q
                ->select(DB::raw(1))
                ->from('products')
                ->whereColumn('products.group_id', 'product_groups.id')
                ->whereIn('products.merchant_id', $query->merchantIds)
                ->where('products.status', 'active'));
        }

        if ($query->discountedOnly) {
            // Measured against our own previous price, never a merchant's "was"
            // price, which is frequently fiction.
            $groups->whereNotNull('previous_price')
                ->whereColumn('min_price', '<', 'previous_price');
        }

        /*
         * Who it is for, what they love, what it is for (roadmap step 4).
         *
         * An editor's tag or the crowd's: either says the product suits it,
         * the crowd's only once enough different people agreed
         * (docs/features/list-signals.md). Any value within one kind, every
         * kind asked.
         *
         * The operator `?|`, written `??|` because a bare `?` is a placeholder
         * to PDO (it hands Postgres a single `?`). Until 2026-09-27 this was
         * `jsonb_exists_any()`, the function behind the operator, which reads
         * the same and cannot use an index: a GIN index serves operators, not
         * functions, and the two tag indexes had never been scanned on
         * production. With the operator both branches of the OR are indexed,
         * so Postgres can combine them in a bitmap instead of reading every
         * row.
         */
        foreach ($query->tagGroups() as $tags) {
            $array = '{'.implode(',', array_map(fn (string $t) => '"'.$t.'"', $tags)).'}';

            $groups->where(fn (Builder $either) => $either
                ->whereRaw('product_groups.gift_tags ??| ?::text[]', [$array])
                ->orWhereRaw('product_groups.crowd_tags ??| ?::text[]', [$array]));
        }

        return $this->applySort($groups, $query);
    }

    /**
     * Full text first, trigram as a safety net.
     *
     * The two fail differently and that is precisely why both are here: FTS
     * finds nothing for a misspelling, and trigram is noisy for a well-spelled
     * multi-word query.
     *
     * ## Why this is a UNION of ids and not three ORed clauses
     *
     * **Rewritten 2026-08-16, after search on staging was measured at 13-21s.**
     * It used to read as `EXISTS(full text) OR title <% term OR
     * word_similarity(term, title) >= 0.45`, which is the same question and
     * could not be answered with an index.
     *
     * Three things had to be true at once, and each defeated the next:
     *
     * 1. The full-text side asked `websearch_to_tsquery(bc_text_config(
     *    products.market), ?)`. The config came from the scanned row's own
     *    column, so it was not constant across the scan and could not be an
     *    index condition — Postgres has to hold the row before it can build the
     *    tsquery it would have used to find the row. Measured on the same rows,
     *    row-derived 820ms against 9ms for a bound one. Passing the market as a
     *    parameter fixes it, and `bc_text_config` stays in SQL rather than being
     *    mirrored into PHP, because a second copy of the language map is a thing
     *    that can disagree with the generated column.
     * 2. `word_similarity(...) >= ?` is a function call. No index answers it,
     *    ever. It existed only because `<%` compares against a session setting
     *    whose default is 0.6 and we want 0.45 — so the threshold moved to the
     *    session, where the operator can use the index. See AppServiceProvider.
     * 3. An OR is only indexable when *every* branch is. One unindexable branch
     *    means every row must be visited anyway, at which point the indexes the
     *    other branches could have used are worthless. So the whole predicate
     *    collapsed to a sequential scan of product_groups with the EXISTS
     *    re-executed per surviving row, at an estimated cost of 526157 against
     *    3208 for the indexed form.
     *
     * Fixing 1 and 2 is not enough on its own: a correlated EXISTS cannot be a
     * bitmap branch, so the OR would still have forced the scan. Collecting ids
     * from two independent, uncorrelated SELECTs is what lets each side use its
     * own index — `products_search_vector_idx` and
     * `product_groups_title_trgm_idx` — and the union is hashed once instead of
     * probed per row.
     *
     * The signature is unchanged so `facets()` can still apply this to a
     * different base query; the branches are a subquery precisely so this stays
     * a predicate rather than becoming a pipeline every caller has to thread.
     *
     * @param  Builder<ProductGroup>  $groups
     */
    private function applyTextMatch(Builder $groups, SearchQuery $query): void
    {
        $term = $query->term;

        // A scanned or pasted barcode is an exact identity, not a text query.
        $gtin = Gtin::normalise($term);
        if ($gtin !== null) {
            $groups->where('identity_key', $gtin);

            return;
        }

        $market = $query->market->value;

        // websearch_to_tsquery handles quoted phrases and OR from user input
        // without throwing on syntax a person would reasonably type — plainto_
        // and to_tsquery both blow up on a stray colon or bracket.
        //
        // The market is bound rather than read from products.market so the
        // tsquery is constant for the scan. It is also an explicit filter, which
        // is what makes the group correlation safe to drop: offers only ever
        // join a group in their own market (invariant 2), so this selects the
        // same rows the correlated form did.
        $byText = DB::table('products')
            ->select('group_id')
            ->where('market', $market)
            ->where('status', 'active')
            ->whereNotNull('group_id')
            ->whereRaw(
                'search_vector @@ websearch_to_tsquery(bc_text_config(?), ?)',
                [$market, $term],
            );

        // `<%` is word_similarity, NOT `%`. `%` compares whole strings, so a
        // typo against a long product title scores below the 0.3 default and
        // finds nothing at all. See docs/features/search.md.
        //
        // Market-filtered even though the outer query filters it too: measured,
        // narrowing here is faster than letting the trigram index return every
        // market's matches for the semi-join to discard (115ms against 159ms).
        $byTitle = DB::table('product_groups')
            ->select('id')
            ->where('market', $market)
            ->whereRaw('? <% title', [$term]);

        /*
         * The written title, both ways (2026-09-14).
         *
         * A visitor who reads "Hario handmolen" on a card and types it in
         * must find the product, and the feed title says "KOFFIEMOLEN
         * HANDMATIG". The group carries a generated vector of the written
         * title and a trigram index on it, so these are two more indexed
         * branches of the same union, not a scan. Nearly every row has an
         * empty vector and a null title here, which the indexes skip.
         */
        $byDisplayText = DB::table('product_groups')
            ->select('id')
            ->where('market', $market)
            ->whereRaw(
                'display_vector @@ websearch_to_tsquery(bc_text_config(?), ?)',
                [$market, $term],
            );

        $byDisplayTitle = DB::table('product_groups')
            ->select('id')
            ->where('market', $market)
            ->whereNotNull('display_title')
            ->whereRaw('? <% display_title', [$term]);

        $groups->whereIn('product_groups.id', $byText->union($byTitle)->union($byDisplayText)->union($byDisplayTitle));
    }

    /** @param Builder<ProductGroup> $groups */
    private function applySort(Builder $groups, SearchQuery $query): Builder
    {
        return match ($query->sort) {
            'price_asc' => $groups->orderBy('min_price')->orderBy('id'),
            'price_desc' => $groups->orderByDesc('min_price')->orderBy('id'),
            'newest' => $groups->orderByDesc('first_seen_at')->orderBy('id'),
            'discount' => $groups
                ->whereNotNull('previous_price')
                ->orderByRaw('(previous_price - min_price)::float / NULLIF(previous_price, 0) DESC NULLS LAST')
                ->orderBy('id'),
            default => $this->orderByRelevance($groups, $query),
        };
    }

    /** @param Builder<ProductGroup> $groups */
    private function orderByRelevance(Builder $groups, SearchQuery $query): Builder
    {
        if (! $query->hasTerm()) {
            // Browsing rather than searching: lead with things worth seeing.
            return $groups
                ->orderByDesc('merchant_count')
                ->orderByDesc('first_seen_at')
                ->orderBy('id');
        }

        // Rank on title similarity, then break ties toward products a shopper
        // can actually compare — a card showing three shops is more useful than
        // one showing a single price, at equal relevance.
        // The better of the two titles: a product found on its written
        // title should rank as if that were its title, which for the visitor
        // who typed it, it is.
        return $groups
            ->orderByRaw(
                "GREATEST(word_similarity(?, product_groups.title), word_similarity(?, coalesce(product_groups.display_title, ''))) DESC",
                [$query->term, $query->term],
            )
            ->orderByDesc('merchant_count')
            ->orderBy('id');
    }

    /**
     * Query the live sources and fold what may be stored into the graph.
     *
     * This is what makes a bol result comparable rather than a separate list: an
     * incoming offer whose identity matches an existing group becomes another
     * shop on the same card, immediately.
     *
     * ## Two kinds of live source
     *
     * Most may be mirrored into `products` and are, which is what puts them in
     * the same grid as everything else. Amazon may not — the Associates terms
     * permit storing the *decision* and require title, price, image and
     * availability to be re-fetched at render — so its offers are handed back
     * untouched for the page to render live, and never written.
     * `Source::allowsCatalogueStorage()` is the gate, and this is the call site
     * docs/features/amazon-compliance.md names for search.
     *
     * ## Why the fold is throttled and the live-only half is not
     *
     * Folding is a write. Brand pages number in the thousands, are the crawl
     * target for the whole site, and would otherwise each run an upsert and three
     * grouping statements on every hit, for a payload the connector is already
     * serving from its own cache. `liveCacheKey()` is the marker: one fold per
     * (market, live term) per cache window, and the offers stay folded in the
     * database long after it expires, so a throttled request renders exactly the
     * same page a folded one would.
     *
     * A source that may not be stored gets no such marker, because for it there
     * is nothing durable to show — freshness at render is the condition of being
     * allowed to display it at all.
     *
     * ## Why the storable half is queued (2026-09-27)
     *
     * The fetch and the fold ran inside the page request: bol, eBay and
     * Tradedoubler one after the other, each `timeout(8)->retry(2)`, then the
     * upsert and the regroup, before a single card rendered. On a fresh term
     * that was seconds of waiting, and on a brand page it was paid by crawlers
     * across thousands of brands. Now the page renders from the stored
     * catalogue at once and {@see PullLiveSearch} does the fetch and fold in
     * the background; what it stores shows on the next view. The marker is
     * still taken here, with the same `Cache::add`, so one window still means
     * one fetch — the job is only dispatched by the request that won it.
     *
     * Except when the stored results are thin (owner's decision, same day):
     * fewer than `inline_live_below` (one page) and the request asks the shops
     * itself, all at once, `inline_live_timeout` seconds, no retry
     * (askNow()), folds what they say and renders with it. A term only bol
     * knows shows bol's products on its first view. Only a search that
     * already fills a page leaves the shops to the job. A shop that times out
     * or fails on the inline path still gets the job, for the next view.
     *
     * A source that may not be stored (Amazon, when it has a connector) is
     * still asked here, in the request, because rendering it fresh is the
     * condition of showing it at all and a job would leave nothing behind.
     *
     * @return array{written: int, unstored: list<Offer>}
     */
    private function pullLiveResults(SearchQuery $query, bool $waitForLive): array
    {
        $connectors = $this->registry->liveFor($query->market);
        if ($connectors === []) {
            return ['written' => 0, 'unstored' => []];
        }

        $mirrorable = array_filter($connectors, fn (LiveConnector $c) => $c->source()->allowsCatalogueStorage());
        $renderOnly = array_filter($connectors, fn (LiveConnector $c) => ! $c->source()->allowsCatalogueStorage());

        $written = 0;

        if ($mirrorable !== [] && Cache::add(
            $query->liveCacheKey(),
            true,
            (int) config('giftcoves.search.live_cache_ttl'),
        )) {
            if ($waitForLive) {
                $written = $this->foldFrom($query, array_values($mirrorable));
                $this->retire($query);
            } elseif (count($this->resultIds($query)) < (int) config('giftcoves.search.inline_live_below', 24)) {
                /*
                 * Thin: fewer stored results than one page, the case where
                 * the shops are most of the answer (owner's decision,
                 * 2026-09-27: a term only bol knows must show bol's products
                 * on the first view). Asked now, in parallel, bounded. The
                 * count is the id list page() reads next anyway, cached, so
                 * deciding costs no extra query.
                 */
                $asked = $this->askNow($query, array_values($mirrorable));
                $written = $this->fold($query, $asked['offers']);
                $this->retire($query);

                // A shop that timed out or failed gets its second chance in
                // the background, so the next view has what it would have said.
                if (! $asked['complete']) {
                    PullLiveSearch::dispatch($query->market, $query->liveTerm(), $query->brands);
                }
            } else {
                // A full page already: the shops can only add to it, and the
                // visitor does not wait for that.
                PullLiveSearch::dispatch($query->market, $query->liveTerm(), $query->brands);
            }
        }

        return [
            'written' => $written,
            'unstored' => $this->liveOnly($query, $this->ask($query, array_values($renderOnly))),
        ];
    }

    /**
     * What the queued job does when it finishes: retire this term's (and this
     * brand's) cached results, so the ids read next include what was folded.
     */
    private function retire(SearchQuery $query): void
    {
        SearchGenerations::bumpTerm($query->market, $query->liveTerm());
        SearchGenerations::bumpBrands($query->market, $query->brands);
    }

    /**
     * Ask the live shops now, for a visitor who is waiting: all at once, each
     * with `inline_live_timeout` seconds and no retry.
     *
     * Connectors that can describe their request (PooledSearch) are sent
     * together through `Http::pool`, so the wait is the slowest shop's, not
     * the sum. Their cache, rate limiter and status handling are their own, as
     * in an ordinary search. One that cannot (a test stand-in, Amazon later) is
     * asked the ordinary way, after the pool.
     *
     * `complete` is false when any request timed out, never connected or
     * came back an error: the caller then queues the ordinary fetch as well.
     *
     * @param  list<LiveConnector>  $connectors
     * @return array{offers: list<Offer>, complete: bool}
     */
    private function askNow(SearchQuery $query, array $connectors): array
    {
        $timeout = max(1, (int) config('giftcoves.search.inline_live_timeout', 3));
        $offers = [];
        $complete = true;

        /** @var array<string, LiveRequest> $pending */
        $pending = [];
        $plain = [];

        foreach ($connectors as $connector) {
            if (! $connector instanceof PooledSearch) {
                $plain[] = $connector;

                continue;
            }

            try {
                $request = $connector->searchRequest($query->liveTerm(), $query->market, 24, $timeout);
            } catch (Throwable $e) {
                report($e);
                $complete = false;

                continue;
            }

            if ($request instanceof LiveRequest) {
                $pending[$connector->source()->value] = $request;
            } else {
                $offers = [...$offers, ...$request];
            }
        }

        if ($pending !== []) {
            $responses = Http::pool(function (Pool $pool) use ($pending, $timeout): void {
                foreach ($pending as $key => $request) {
                    $http = $pool->as($key)
                        ->timeout($timeout)
                        ->connectTimeout($timeout)
                        ->withHeaders($request->headers);

                    if ($request->token !== null) {
                        $http->withToken($request->token);
                    }

                    $http->get($request->url, $request->query);
                }
            });

            foreach ($pending as $key => $request) {
                $response = $responses[$key] ?? null;

                if (! $response instanceof Response || $response->failed()) {
                    $complete = false;
                }

                try {
                    $offers = [...$offers, ...($request->finish)($response instanceof Response ? $response : null)];
                } catch (Throwable $e) {
                    report($e);
                    $complete = false;
                }
            }
        }

        return ['offers' => [...$offers, ...$this->ask($query, $plain)], 'complete' => $complete];
    }

    /**
     * Ask the live shops whose offers may be stored, and store them.
     *
     * {@see PullLiveSearch}'s work, and the `waitForLive` path's. Takes no
     * marker: whoever calls it already won the one in pullLiveResults().
     */
    public function foldLive(SearchQuery $query): int
    {
        $mirrorable = array_filter(
            $this->registry->liveFor($query->market),
            fn (LiveConnector $c) => $c->source()->allowsCatalogueStorage(),
        );

        return $this->foldFrom($query, array_values($mirrorable));
    }

    /** @param  list<LiveConnector>  $connectors */
    private function foldFrom(SearchQuery $query, array $connectors): int
    {
        return $connectors === [] ? 0 : $this->fold($query, $this->ask($query, $connectors));
    }

    /**
     * @param  list<LiveConnector>  $connectors
     * @return list<Offer>
     */
    private function ask(SearchQuery $query, array $connectors): array
    {
        $offers = [];

        foreach ($connectors as $connector) {
            try {
                // Connectors degrade rather than throw, but a bug in one must
                // not take down search for the others either.
                $offers = [...$offers, ...$connector->search($query->liveTerm(), $query->market)];
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $offers;
    }

    /**
     * Write the storable half and attach it to its groups.
     *
     * @param  list<Offer>  $offers
     */
    private function fold(SearchQuery $query, array $offers): int
    {
        $offers = $this->attributeBrands($query, $offers);

        if ($offers === []) {
            return 0;
        }

        $written = $this->upserter->upsert($offers)['written'];

        // Group only what just arrived. A full market regroup on every search
        // would be absurd; this touches the handful of affected rows.
        $this->groupIncoming($query, $offers);

        return $written;
    }

    /**
     * The half that is rendered live and never stored.
     *
     * On a brand-scoped query these are narrowed to the brand. A stored offer
     * meets the brand filter in SQL; this one never passes through it, so
     * without the narrowing an Amazon lane on a Sony page would show whatever a
     * keyword search for "Sony" returned — third-party cases included — under a
     * heading promising Sony products.
     *
     * @param  list<Offer>  $offers
     * @return list<Offer>
     */
    private function liveOnly(SearchQuery $query, array $offers): array
    {
        $offers = $this->attributeBrands($query, $offers);

        return $query->brands === []
            ? $offers
            : $this->attribution->matching($offers, $query->brands);
    }

    /**
     * Give the offers a brand, where their source did not.
     *
     * bol returns none at all, and a brand page filters on exactly that column —
     * see BrandAttribution for why this is a lookup rather than a guess.
     *
     * It buys a second thing on every search, not only on a brand page: an offer
     * with no usable barcode has no identity at all without a brand, because
     * `IdentityResolver`'s fallback key is brand + normalised title. Filling the
     * brand in before the upsert is what lets those rows group with the same
     * product from another shop instead of sitting alone.
     *
     * @param  list<Offer>  $offers
     * @return list<Offer>
     */
    private function attributeBrands(SearchQuery $query, array $offers): array
    {
        if ($offers === []) {
            return [];
        }

        $offers = $this->attribution->fromCatalogue($offers);

        if ($query->brands === []) {
            return $offers;
        }

        /*
         * The first spelling is the display name — `BrandStats` sorts the
         * variants by product count before writing `aliases`, so `aliases[0]` is
         * the same string `brand_stats.brand` holds and the same one the page's
         * heading uses. Stamping any other spelling would still satisfy the
         * brand filter, which matches on all of them, and would print a
         * punctuation the rest of the page does not use.
         */
        return $this->attribution->attribute($offers, $query->brands, $query->brands[0]);
    }

    /**
     * Attach freshly-arrived offers to their groups.
     *
     * The work itself is {@see IncomingGrouper}, shared with the bol page
     * import — both write offers outside a feed run and both need the new rows
     * to be countable before the page that triggered them renders. Keeping one
     * implementation matters more than the handful of lines it saves: two
     * copies would be two answers to "when may an offer join a group", and a
     * wrong merge lets a foreign price masquerade as the cheapest.
     *
     * @param  list<Offer>  $offers
     */
    private function groupIncoming(SearchQuery $query, array $offers): void
    {
        // The offers, not their ids: the grouper needs each one's source to use
        // the index at all. See IncomingGrouper::attachFrom().
        $this->grouper->attach($query->market, $offers);
    }

    /**
     * Facet counts for the filter sidebar.
     *
     * Computed from the term and market only, ignoring the active filters, so
     * selecting a brand does not make every other brand vanish from the list —
     * a filter UI that erases its own options is unusable.
     *
     * Cached, because that independence from the filters is exactly what makes
     * the three aggregates below repeat work: every filter, sort and page
     * variant of one term produces the same sidebar. See `facetCacheKey()` for
     * the key and `facet_cache_ttl` for what the staleness costs.
     *
     * @return array{brands: list<array{value: string, count: int}>, merchants: list<array{id: int, name: string, logo: string|null, count: int}>, price: array{min: int|null, max: int|null}}
     */
    private function facets(SearchQuery $query): array
    {
        return Cache::remember(
            $query->facetCacheKey(),
            (int) config('giftcoves.search.facet_cache_ttl'),
            fn (): array => $this->countFacets($query),
        );
    }

    /**
     * The three aggregates behind `facets()`, uncached.
     *
     * @return array{brands: list<array{value: string, count: int}>, merchants: list<array{id: int, name: string, count: int}>, price: array{min: int|null, max: int|null}}
     */
    private function countFacets(SearchQuery $query): array
    {
        $base = ProductGroup::query()
            ->forMarket($query->market)
            ->whereNotNull('min_price')
            ->whereNotNull('image_url');

        if ($query->hasTerm()) {
            $this->applyTextMatch($base, $query);
        }
        if ($query->inStockOnly) {
            $base->where('in_stock', true);
        }

        $brands = (clone $base)
            ->select('brand', DB::raw('count(*) as total'))
            ->whereNotNull('brand')
            ->groupBy('brand')
            ->orderByDesc('total')
            ->limit(15)
            ->get()
            ->map(fn ($r) => ['value' => (string) $r->brand, 'count' => (int) $r->total])
            ->all();

        $counted = DB::table('products')
            ->join('merchants', 'merchants.id', '=', 'products.merchant_id')
            ->joinSub((clone $base)->select('product_groups.id'), 'g', 'g.id', '=', 'products.group_id')
            ->where('products.status', 'active')
            ->select('merchants.id', 'merchants.name', DB::raw('count(DISTINCT products.group_id) as total'))
            ->groupBy('merchants.id', 'merchants.name')
            ->orderByDesc('total')
            ->limit(15)
            ->get();

        /*
         * The shop's mark travels with the facet, for the chip row above the
         * by-store lanes.
         *
         * Hydrated rather than derived from the joined columns: the fallback
         * from `logo_url` to a favicon guessed from the domain lives in
         * `Merchant::faviconUrl()`, and the country suffix is trimmed by
         * `Merchant::displayName()`. A second copy of either rule here is a
         * thing that can disagree with the lane headers reading the first one.
         * One extra query, on a path that is cached for facet_cache_ttl.
         */
        $shops = Merchant::query()
            ->whereIn('id', $counted->pluck('id'))
            ->get()
            ->keyBy('id');

        $merchants = $counted
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                // The chips and the lane headers name the same shops, so both
                // read displayName() — the country suffix the feed attaches is
                // not part of the name. See Merchant::displayName().
                'name' => $shops->get((int) $r->id)?->displayName() ?? (string) $r->name,
                'logo' => $shops->get((int) $r->id)?->faviconUrl(),
                'count' => (int) $r->total,
            ])
            ->all();

        $price = (clone $base)->selectRaw('min(min_price) as lo, max(min_price) as hi')->first();

        return [
            'brands' => $brands,
            'merchants' => $merchants,
            'price' => ['min' => $price?->lo === null ? null : (int) $price->lo, 'max' => $price?->hi === null ? null : (int) $price->hi],
        ];
    }

    /**
     * Per-merchant lanes for the "by store" view.
     *
     * Capped per merchant, because one recently-ingested advertiser with a huge
     * feed would otherwise fill every lane and the view would show a single shop.
     *
     * Keyed by nothing: a list, each entry carrying the Merchant itself rather
     * than just its name, so the caller can put the shop's mark on the column
     * header. A name-keyed map could not — and two merchants are allowed to
     * share a display name across sources, which a map would silently merge.
     *
     * @return list<array{merchant: Merchant, groups: list<ProductGroup>}>
     */
    public function storeLanes(SearchQuery $query): array
    {
        $cap = (int) config('giftcoves.search.store_lane_cap');

        // The same cached id list the grid reads (see page()), when its head
        // reaches the 300 the lanes are drawn from.
        $ids = collect(array_slice($this->resultIds($query), 0, 300));

        if ($ids->isEmpty()) {
            return [];
        }

        // A window function does the capping in SQL; pulling everything back
        // and slicing in PHP would mean fetching a whole feed to show 8 rows.
        $offers = DB::table('products as p')
            ->join('merchants as m', 'm.id', '=', 'p.merchant_id')
            ->whereIn('p.group_id', $ids)
            ->where('p.status', 'active');

        /*
         * The merchant filter has to be applied AGAIN here, and it means
         * something different from the one in storedQuery().
         *
         * There it selects GROUPS — a group qualifies if any of its offers is
         * from a selected shop, because the shopper is asking "who has this at
         * Coolblue". Those groups then carry all of their other offers with
         * them, and this view turns every offer into a lane, so unselected
         * shops came back as lanes of their own: pick one store and the page
         * still showed five. In the grid that extra offer is a comparison
         * price on a card the visitor asked for; here it is a whole shop they
         * deselected.
         */
        if ($query->merchantIds !== []) {
            $offers->whereIn('p.merchant_id', $query->merchantIds);
        }

        $ranked = $offers
            ->select('p.group_id', 'm.id as merchant_id', 'p.price')
            ->selectRaw('row_number() OVER (PARTITION BY m.id ORDER BY p.price ASC NULLS LAST, p.id) as rn')
            ->get()
            ->filter(fn ($r) => $r->rn <= $cap)
            ->groupBy('merchant_id');

        $groups = ProductGroup::query()->whereIn('id', $ids)->get()->keyBy('id');
        $merchants = Merchant::query()->whereIn('id', $ranked->keys())->get()->keyBy('id');

        $lanes = [];

        foreach ($ranked as $merchantId => $rows) {
            $merchant = $merchants->get((int) $merchantId);

            if ($merchant === null) {
                continue;
            }

            $laneGroups = $rows
                ->map(fn ($r) => $groups->get($r->group_id))
                ->filter()
                ->values()
                ->all();

            if ($laneGroups === []) {
                continue;
            }

            $lanes[] = ['merchant' => $merchant, 'groups' => $laneGroups];
        }

        return $lanes;
    }
}

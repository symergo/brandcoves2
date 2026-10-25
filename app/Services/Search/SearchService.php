<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Jobs\PullLiveSearch;
use App\Jobs\RefreshSearchCache;
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
use Illuminate\Database\Query\Builder as QueryBuilder;
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

    /** @var array<string, bool> needsFuzzy() per market and term, for this request */
    private array $fuzzy = [];

    /** @var array<string, int> wordMatchCount() per market, term and cap, for this request */
    private array $wordCounts = [];

    /**
     * @param  bool  $waitForLive  ask the live shops inside this call and fold
     *                             their offers before the query runs, instead of queueing
     *                             it. For the two callers where a person waits on exactly
     *                             this answer and the volume is tiny: an editor curating
     *                             in the admin and an author on the editorial API. Every
     *                             visitor-facing page leaves it false. See pullLiveResults().
     * @param  bool  $askShops  whether the live shops may be asked at all. The
     *                          search and brand pages pass false for a request
     *                          that names itself a crawler (owner, 2026-10-25):
     *                          a crawler walking new terms all day cost a live
     *                          API call and a queued job per page, for nobody.
     *                          It sees what is stored.
     * @param  bool  $deferThin  when the search is thin and the shops would be
     *                           asked while the visitor waits, do not ask them:
     *                           return the stored results now, with
     *                           `deferredShops` set, and let the page ask in a
     *                           follow-up request (2026-10-25, see
     *                           SearchController). The search page passes it for
     *                           a person; a search engine gets the whole page.
     */
    public function search(SearchQuery $query, bool $waitForLive = false, bool $askShops = true, bool $deferThin = false): SearchResult
    {
        // The live half is driven by `liveTerm()`, not by the term. A brand page
        // has no search term and still has something to ask bol: the brand's
        // name, because it takes no brand filter.
        $deferred = $askShops && $deferThin && ! $waitForLive && $query->hasLiveTerm()
            ? $this->shopsToDefer($query)
            : [];

        $live = $askShops && $deferred === [] && $query->hasLiveTerm()
            ? $this->pullLiveResults($query, $waitForLive)
            : ['written' => 0, 'unstored' => [], 'folded' => false];

        /*
         * Stale while revalidate (2026-10-25): an outdated list is shown and
         * recomputed in the background, never while somebody waits. Two
         * exceptions: a list that does not exist yet (nothing to show), and
         * this request having just stored the shops' products, which is the
         * reason it exists (`folded`: the thin follow-up, the admin). Only a
         * request that may ask the shops (a person, a search engine) starts a
         * refresh; another crawler is shown the old list for nothing.
         */
        $fresh = $live['folded'];
        $ids = $this->resultIds($query, fresh: $fresh, refresh: $askShops);

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
            facets: fn (): array => $this->facets($query, refresh: $askShops),
            liveOffers: $live['unstored'],
            empty: $ids === [],
            deferredShops: $deferred,
        );
    }

    /**
     * The shops a visitor would wait for on this search, by name, or none.
     *
     * Only the case pullLiveResults() would answer inline: shops whose offers
     * may be stored, nobody asked them about this term within the window
     * (the marker is not set), and the search is thin. The marker is not
     * taken here: the follow-up request takes it, through the ordinary path.
     *
     * @return list<string>
     */
    private function shopsToDefer(SearchQuery $query): array
    {
        $mirrorable = array_values(array_filter(
            $this->registry->liveFor($query->market),
            fn (LiveConnector $c) => $c->source()->allowsCatalogueStorage(),
        ));

        if ($mirrorable === [] || Cache::has($query->liveCacheKey()) || ! $this->isThin($query)) {
            return [];
        }

        return array_values(array_unique(array_map(fn (LiveConnector $c) => $c->source()->label(), $mirrorable)));
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
     * Cached through staleWhileRevalidate(): an outdated list is shown and
     * recomputed in the background.
     *
     * @return list<int>
     */
    private function resultIds(SearchQuery $query, bool $fresh = false, bool $refresh = true): array
    {
        $ids = $this->staleWhileRevalidate(
            $query->resultsCacheSlot(),
            $query->resultsGeneration(),
            (int) config('giftcoves.search.results_cache_ttl'),
            fn (): array => $this->computeResultIds($query),
            $refresh ? fn () => RefreshSearchCache::dispatch($query) : null,
            $fresh,
        );

        return is_array($ids) && array_is_list($ids) ? $ids : $this->computeResultIds($query);
    }

    /**
     * The result ids, computed now. See resultIds().
     *
     * @return list<int>
     */
    private function computeResultIds(SearchQuery $query): array
    {
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

        return $ids;
    }

    /**
     * How long an outdated list or count is kept to be shown while its
     * replacement is computed: long past `results_cache_ttl`, so a term
     * searched once a week still opens at once.
     */
    private const KEEP_STALE = 14 * 86400;

    /**
     * Stale while revalidate (owner, 2026-10-25: "never computed while
     * somebody waits, always after showing the outdated one").
     *
     * The entry lives at a fixed slot with the generation it was computed
     * at and when. Current (same generation, younger than `$freshFor`): it
     * is returned. Outdated (the catalogue moved on: the twice-daily update,
     * a merge, a shop's answer; or simply older): it is returned all the same
     * and `$onStale` queues its recomputation (RefreshSearchCache, unique per
     * slot). Missing: computed now, because there is nothing to show; the
     * one case a visitor waits for. `$fresh` computes now regardless, for the
     * request that has just stored the shops' products and exists to show
     * them.
     *
     * Why a queue and not after the response in the same PHP worker (what
     * `Cache::flexible()` does): after the catalogue update every visited term
     * is outdated at once, and a queue works them off one by one rather than
     * in as many parallel searches as there are visitors, which is what took
     * the site down on the morning of 2026-10-25.
     *
     * Safe to show old: an entry is ids or counts. The cards are read fresh
     * on every view, so prices, stock and discounts are always current; a
     * product merged away or removed since is simply left out.
     */
    private function staleWhileRevalidate(string $slot, string $generation, int $freshFor, \Closure $compute, ?\Closure $onStale, bool $fresh): mixed
    {
        $hit = $fresh ? null : Cache::get($slot);

        if (is_array($hit) && array_key_exists('value', $hit)) {
            $outdated = ($hit['gen'] ?? null) !== $generation || (int) ($hit['at'] ?? 0) < time() - $freshFor;

            if ($outdated && $onStale !== null) {
                $onStale();
            }

            return $hit['value'];
        }

        $value = $compute();
        Cache::put($slot, ['value' => $value, 'gen' => $generation, 'at' => time()], self::KEEP_STALE);

        return $value;
    }

    /**
     * Recompute this search's result ids and filter counts and store them as
     * current. RefreshSearchCache's work.
     */
    public function refreshCache(SearchQuery $query): void
    {
        $this->resultIds($query, fresh: true, refresh: false);
        $this->facets($query, fresh: true, refresh: false);
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

        $match = $this->wordMatch($query);

        if ($this->needsFuzzy($query)) {
            $match->union($byTitle);
        }

        $groups->whereIn('product_groups.id', $match);
    }

    /**
     * The ids the term matches by its words: full text on the offers, and the
     * written title both ways. Everything `applyTextMatch()` unions except the
     * fuzzy title match. A fresh builder on every call.
     */
    private function wordMatch(SearchQuery $query): QueryBuilder
    {
        $term = $query->term;
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

        return $byText->union($byDisplayText)->union($byDisplayTitle);
    }

    /**
     * How many products the term finds by its words, counted up to `$cap`.
     *
     * Asked with a LIMIT, so it costs no more than the word match itself, and
     * remembered for the request: the fuzzy decision and the "is this term
     * thin?" decision before asking the live shops both read it.
     */
    private function wordMatchCount(SearchQuery $query, int $cap): int
    {
        $key = $query->market->value."\0".$query->term."\0".$cap;

        return $this->wordCounts[$key] ??= DB::query()
            ->fromSub($this->wordMatch($query)->limit($cap), 'm')
            ->count();
    }

    /**
     * Whether the fuzzy title match joins the union.
     *
     * The fuzzy match is the typo net, and the most expensive thing a search
     * does: 1.7-3.3s on production against 8-110ms for the word match
     * (2026-10-25). Two rules decide it:
     *
     * 1. Once the live shops answered for this term (owner, 2026-10-25, for
     *    any live source, not only bol), only when the words find nothing at
     *    all. What the fuzzy match adds are near misses, and the shops have
     *    just supplied real products. But a shop's own search returns
     *    products whose titles need not contain the word, so its answer can
     *    leave the word match empty: on production "kabouterlamp" went from
     *    24 fuzzy results to none, cached for 12 hours, until this "at all"
     *    was added. Counted after the shops' products were stored
     *    (rememberShopsAnswered() forgets the earlier count).
     * 2. Otherwise only when the words find less than a page
     *    (`search.fuzzy_below`). A typo finds next to nothing by words and
     *    gets the net; a term spelled right already fills a page.
     *
     * Remembered per term for the request, so the results and the filter
     * counts agree on which rows they counted.
     */
    private function needsFuzzy(SearchQuery $query): bool
    {
        $key = $query->market->value."\0".$query->term;

        $below = (int) config('giftcoves.search.fuzzy_below', 24);

        return $this->fuzzy[$key] ??= Cache::has(self::shopsAnsweredKey($query))
            ? $this->wordMatchCount($query, 1) === 0
            : $this->wordMatchCount($query, $below) < $below;
    }

    /**
     * Set while the live shops' answer for a term is fresh: as long as the
     * one-fetch-per-window marker (`live_cache_ttl`), and only when they
     * answered with something. Read by needsFuzzy().
     */
    private static function shopsAnsweredKey(SearchQuery $query): string
    {
        return 'bc:search:shops-answered:'.$query->market->value.':'.sha1(mb_strtolower($query->liveTerm()));
    }

    /** @param  list<Offer>  $offers */
    private function rememberShopsAnswered(SearchQuery $query, array $offers): void
    {
        if ($offers !== []) {
            Cache::put(self::shopsAnsweredKey($query), true, (int) config('giftcoves.search.live_cache_ttl'));
            // The decision and the counts from before the shops' products
            // were stored no longer hold.
            $term = $query->market->value."\0".$query->term;
            unset($this->fuzzy[$term]);
            $this->wordCounts = array_filter(
                $this->wordCounts,
                fn (string $key): bool => ! str_starts_with($key, $term."\0"),
                ARRAY_FILTER_USE_KEY,
            );
        }
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
     * `folded` is true when the shops' products were stored inside this
     * call (the admin's wait, a thin search's follow-up): the request then
     * computes its list fresh instead of showing the outdated one, because
     * showing those products is what it is for.
     *
     * @return array{written: int, unstored: list<Offer>, folded: bool}
     */
    private function pullLiveResults(SearchQuery $query, bool $waitForLive): array
    {
        $connectors = $this->registry->liveFor($query->market);
        if ($connectors === []) {
            return ['written' => 0, 'unstored' => [], 'folded' => false];
        }

        $mirrorable = array_filter($connectors, fn (LiveConnector $c) => $c->source()->allowsCatalogueStorage());
        $renderOnly = array_filter($connectors, fn (LiveConnector $c) => ! $c->source()->allowsCatalogueStorage());

        $written = 0;
        $folded = false;

        if ($mirrorable !== [] && Cache::add(
            $query->liveCacheKey(),
            true,
            (int) config('giftcoves.search.live_cache_ttl'),
        )) {
            if ($waitForLive) {
                $offers = $this->ask($query, array_values($mirrorable));
                $written = $this->fold($query, $offers);
                $this->rememberShopsAnswered($query, $offers);
                $this->retire($query);
                $folded = true;
            } elseif ($this->isThin($query)) {
                /*
                 * Thin: fewer stored results than one page, the case where
                 * the shops are most of the answer (owner's decision,
                 * 2026-09-27: a term only bol knows must show bol's products
                 * on the first view). Asked now, in parallel, bounded.
                 *
                 * When they answer, the fuzzy match is skipped for the term
                 * (needsFuzzy()), so a thin search no longer pays for both.
                 * The id list is computed once, after this: isThin() reads the
                 * word count, not the ids, so nothing cached has to be retired
                 * and recomputed within the same request.
                 */
                $asked = $this->askNow($query, array_values($mirrorable));
                $written = $this->fold($query, $asked['offers']);
                $this->rememberShopsAnswered($query, $asked['offers']);
                $this->retire($query);
                $folded = true;

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

        $unstored = $this->liveOnly($query, $this->ask($query, array_values($renderOnly)));
        // A shop shown live counts as an answer too: any live source, stored or not.
        $this->rememberShopsAnswered($query, $unstored);

        return [
            'written' => $written,
            'unstored' => $unstored,
            'folded' => $folded,
        ];
    }

    /**
     * Fewer than a page (`inline_live_below`) for this search, so the shops
     * are asked while the visitor waits.
     *
     * For a term, the word-match count (the same capped count the fuzzy
     * decision reads, so one query answers both). It used to be the id list
     * itself, which ran the full search, fuzzy match included, before the
     * shops were asked, and then again after their answer retired it.
     * Without a term (a brand page), for a barcode, or with filters on (which
     * can thin a page the words alone would fill), the id list as before.
     */
    private function isThin(SearchQuery $query): bool
    {
        $below = (int) config('giftcoves.search.inline_live_below', 24);

        if ($query->hasTerm() && ! $query->hasFilters() && Gtin::normalise($query->term) === null) {
            return $this->wordMatchCount($query, $below) < $below;
        }

        return count($this->resultIds($query)) < $below;
    }

    /**
     * Retire this term's (and this brand's) cached results, so the ids read
     * next include what was folded. The request's own path (a thin term,
     * `waitForLive`) and a brand page's job; a term's job merges instead,
     * see foldLiveIntoResults().
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
     * Ask the live shops whose offers may be stored, store them, and put the
     * products they brought into the term's cached results.
     *
     * {@see PullLiveSearch}'s work. Takes no marker: whoever dispatched it
     * already won the one in pullLiveResults().
     *
     * ## Merged, not retired (owner, 2026-10-25)
     *
     * This used to retire the term's cached results whenever it finished, so
     * the next visitor paid for the whole search again (a fresh term took
     * 0.5 s on its first view and 4.4 s on its second), also when the shops
     * added nothing. Now:
     *
     * - The products the answer brought that the cached plain list (no
     *   filters, relevance: what a visitor lands on) lacks are found through
     *   the same stored query a fresh computation would apply, so a product
     *   the page would not show is not added either.
     * - None: nothing is retired at all. That is the usual case, because the
     *   shops' answer is mostly products already stored.
     * - Some: the term is retired, so its sorted and filtered variants pick
     *   them up on their next view, and the plain list is written straight
     *   under the new generation with them appended (ranked last, outside the
     *   `results_per_shop` cap), so the plain page is not recomputed.
     * - No list cached yet: nothing to do; the next view computes it fresh.
     *
     * A brand page's job still retires, as before: its query is the brand
     * filter, not words.
     */
    public function foldLiveIntoResults(SearchQuery $query): void
    {
        $mirrorable = array_values(array_filter(
            $this->registry->liveFor($query->market),
            fn (LiveConnector $c) => $c->source()->allowsCatalogueStorage(),
        ));

        if ($mirrorable === []) {
            return;
        }

        $offers = $this->ask($query, $mirrorable);
        $this->fold($query, $offers);
        $this->rememberShopsAnswered($query, $offers);

        if ($query->brands !== []) {
            $this->retire($query);

            return;
        }

        $this->mergeIntoCachedResults(new SearchQuery(market: $query->market, term: $query->liveTerm()), $offers);
    }

    /** @param  list<Offer>  $offers */
    private function mergeIntoCachedResults(SearchQuery $plain, array $offers): void
    {
        $entry = Cache::get($plain->resultsCacheSlot());
        $cached = is_array($entry) ? ($entry['value'] ?? null) : null;

        if ($offers === [] || ! is_array($cached) || ! array_is_list($cached)) {
            return;
        }

        // The groups these offers joined, by source and the source's own id:
        // the same pair the upsert keys on.
        $groups = DB::table('products')
            ->where('market', $plain->market->value)
            ->whereNotNull('group_id')
            ->where(function ($q) use ($offers): void {
                foreach (collect($offers)->groupBy(fn (Offer $o) => $o->source->value) as $source => $bySource) {
                    $q->orWhere(fn ($s) => $s
                        ->where('source', $source)
                        ->whereIn('external_id', $bySource->map(fn (Offer $o) => $o->externalId)->all()));
                }
            })
            ->distinct()
            ->pluck('group_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $new = array_values(array_diff($groups, $cached));

        if ($new === []) {
            return;
        }

        $shown = $this->storedQuery($plain)
            ->whereIn('product_groups.id', $new)
            ->pluck('product_groups.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($shown === []) {
            return;
        }

        /*
         * Something new: retire the term, so its sorted and filtered variants
         * are recomputed in the background on their next view (they show
         * their old list meanwhile), and mark the plain list, merged, and the
         * filter counts it had as current at the new generation, so the page
         * nearly every visitor lands on is not recomputed at all. The counts
         * lack the new products' brands and shops until they expire
         * (`facet_cache_ttl`); a filter list a few entries short is the price
         * of not counting the term again.
         */
        SearchGenerations::bumpTerm($plain->market, $plain->term);

        Cache::put($plain->resultsCacheSlot(), [
            'value' => [...$cached, ...$shown],
            'gen' => $plain->resultsGeneration(),
            'at' => time(),
        ], self::KEEP_STALE);

        $facets = Cache::get($plain->facetCacheSlot());
        if (is_array($facets) && array_key_exists('value', $facets)) {
            Cache::put($plain->facetCacheSlot(), [...$facets, 'gen' => $plain->facetGeneration()], self::KEEP_STALE);
        }
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
     * the aggregates below repeat work: every filter, sort and page variant of
     * one term produces the same sidebar. See `facetCacheKey()` for the key and
     * `facet_cache_ttl` for what the staleness costs.
     *
     * @return array{brands: list<array{value: string, count: int}>, merchants: list<array{id: int, name: string, logo: string|null, count: int}>}
     */
    private function facets(SearchQuery $query, bool $fresh = false, bool $refresh = true): array
    {
        $facets = $this->staleWhileRevalidate(
            $query->facetCacheSlot(),
            $query->facetGeneration(),
            (int) config('giftcoves.search.facet_cache_ttl'),
            fn (): array => $this->countFacets($query),
            $refresh ? fn () => RefreshSearchCache::dispatch($query) : null,
            $fresh,
        );

        return is_array($facets) ? $facets : $this->countFacets($query);
    }

    /**
     * The brand and shop counts behind `facets()`, uncached.
     *
     * ## One statement, the match found once (2026-10-25)
     *
     * Brands and shops were two queries, and each ran the full-text and
     * trigram match over the catalogue again: about 0.55 s and 0.6 s on
     * production, on top of the result list's own match. Crawlers walking
     * `/{market}/zoek/{term}` ask for a new term every two seconds, so neither
     * cache ever helped them, and the repeated matching kept Postgres busy
     * enough that the app missed its health check and dropped out. Now the
     * match is a `MATERIALIZED` CTE that both counts read: evaluated once, held
     * for the statement, and the two aggregates come back as one UNION ALL.
     *
     * The price range went the same day: no page drew it.
     *
     * @return array{brands: list<array{value: string, count: int}>, merchants: list<array{id: int, name: string, logo: string|null, count: int}>}
     */
    private function countFacets(SearchQuery $query): array
    {
        /*
         * Never over a whole market (2026-10-25). Without a term the counts
         * used to run over every product in the market, 1.6 million on be-nl:
         * 38-55 s for a small brand's page on production, each time a live
         * fetch had retired its cache. A brand page (no term, the brand as its
         * scope) now counts that brand's products; a search with neither a
         * term nor a brand page's scope gets no counts (a sorted catalogue, a
         * brand chosen in the Filters menu without a term): a filter list
         * drawn from the whole catalogue says nothing, and costs that minute.
         */
        if (! $query->hasTerm() && ! $query->isBrandScope()) {
            return ['brands' => [], 'merchants' => []];
        }

        $base = ProductGroup::query()
            ->forMarket($query->market)
            ->whereNotNull('min_price')
            ->whereNotNull('image_url')
            ->select('product_groups.id', 'product_groups.brand');

        if ($query->hasTerm()) {
            $this->applyTextMatch($base, $query);
        } else {
            $base->whereIn('brand', $query->brands);
        }
        if ($query->inStockOnly) {
            $base->where('in_stock', true);
        }

        $matches = $base->toBase();

        $rows = collect(DB::select(
            "WITH m AS MATERIALIZED ({$matches->toSql()})
            (SELECT 'brand' AS facet, NULL::bigint AS id, m.brand AS name, count(*) AS total
                FROM m WHERE m.brand IS NOT NULL
                GROUP BY m.brand ORDER BY total DESC LIMIT 15)
            UNION ALL
            (SELECT 'shop', merchants.id, merchants.name, count(DISTINCT p.group_id)
                FROM products p
                JOIN m ON m.id = p.group_id
                JOIN merchants ON merchants.id = p.merchant_id
                WHERE p.status = 'active'
                GROUP BY merchants.id, merchants.name ORDER BY 4 DESC LIMIT 15)",
            $matches->getBindings(),
        ));

        $brands = $rows->where('facet', 'brand')
            ->map(fn ($r) => ['value' => (string) $r->name, 'count' => (int) $r->total])
            ->values()
            ->all();

        $counted = $rows->where('facet', 'shop')->values();

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

        return [
            'brands' => $brands,
            'merchants' => $merchants,
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

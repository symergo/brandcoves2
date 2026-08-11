<?php

declare(strict_types=1);

namespace App\Services\Connectors\Bol;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\Source;
use App\Services\Connectors\ChartCategory;
use App\Services\Connectors\ChartEntry;
use App\Services\Connectors\LiveConnector;
use App\Services\Connectors\Offer;
use App\Services\Connectors\PopularChart;
use App\Services\Connectors\PopularityConnector;
use App\Services\Connectors\RateLimiter;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * bol.com, queried live rather than ingested.
 *
 * Every public method degrades instead of throwing. A dead or rate-limited bol
 * must mean a smaller result set, never a broken search page — the stored Awin
 * index still has plenty to show.
 */
class BolConnector implements LiveConnector, PopularityConnector
{
    private const TOKEN_URL = 'https://login.bol.com/token';

    private const API_BASE = 'https://api.bol.com/marketing/catalog/v1';

    public function source(): Source
    {
        return Source::Bol;
    }

    public function supports(Market $market): bool
    {
        return (bool) config('brandcoves.connectors.bol.enabled')
            && filled(config('brandcoves.connectors.bol.client_id'))
            && filled(config('brandcoves.connectors.bol.client_secret'))
            // bol does not operate in Spain, so that market is Awin-only. A null
            // country means "skip", never "use the default".
            && $market->bolCountry() !== null;
    }

    public function isCoolingDown(): bool
    {
        return $this->limiter('search')->isCoolingDown();
    }

    /** @return list<Offer> */
    public function search(string $query, Market $market, int $limit = 24): array
    {
        $query = trim($query);
        if ($query === '' || ! $this->supports($market)) {
            return [];
        }

        $cacheKey = sprintf('bc:bol:search:%s:%s:%d', $market->value, sha1(mb_strtolower($query)), $limit);

        /*
         * The cache holds bol's RAW PAYLOAD, never our Offer objects.
         *
         * Caching the objects put a serialised `App\Services\Connectors\Offer`
         * into Redis, and reading it back produced
         * "tried to call a method on an incomplete object" — a 500 on every
         * search that hit a warm cache. Storing domain objects in a shared
         * cache makes every cached entry depend on the class being loadable at
         * unserialize time and on its shape never changing, and a redeploy
         * breaks both at once.
         *
         * Arrays have neither problem. Re-normalising on a cache hit costs
         * microseconds and cannot go stale against a class definition.
         */
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $this->offersFrom($cached, $market);
        }

        $products = $this->fetchSearch($query, $market, $limit);

        /*
         * An empty result is NOT cached.
         *
         * An empty array is what every degraded path returns — an expired
         * token, a rate limit, a timeout — so caching it blanked bol for this
         * query for the full fifteen minutes, long after the cause had cleared.
         * Found while debugging exactly that: a failed run poisoned the cache
         * and the next working run still returned nothing.
         *
         * A real zero-result query is cheap to repeat and rare; a cached
         * failure is expensive and invisible.
         */
        if ($products !== []) {
            Cache::put($cacheKey, $products, (int) config('brandcoves.search.live_cache_ttl'));
        }

        return $this->offersFrom($products, $market);
    }

    /**
     * @param  list<array<string, mixed>>  $products
     * @return list<Offer>
     */
    private function offersFrom(array $products, Market $market): array
    {
        $offers = [];

        foreach ($products as $product) {
            $offer = is_array($product) ? $this->normalise($product, $market) : null;

            if ($offer?->isValid()) {
                $offers[] = $offer;
            }
        }

        return $offers;
    }

    public function fetchById(string $externalId, Market $market): ?Offer
    {
        if (! $this->supports($market)) {
            return null;
        }

        // A separate bucket: bol's limits are per endpoint and do not share a
        // budget, so a drained search bucket must not block a wishlist refresh.
        if (! $this->limiter('product')->attempt()) {
            return null;
        }

        $response = $this->request("/products/{$externalId}", $market, 'product');
        if ($response === null) {
            return null;
        }

        $product = $response->json('product') ?? $response->json();

        return is_array($product) ? $this->normalise($product, $market) : null;
    }

    public function isChartCoolingDown(): bool
    {
        return $this->limiter('popular')->isCoolingDown();
    }

    /**
     * bol's popular-products chart, optionally for one category.
     *
     * A browse endpoint: no search term, which is exactly what makes it a demand
     * signal rather than a relevance one. Nobody typed anything — these are the
     * things bol actually sells the most of.
     *
     * Not cached. Unlike search, this is called from a scheduled job a couple of
     * times a day per chart, so a cache would only ever be written and never
     * read; the freshness of the snapshot is the whole point, and `popular_ranks`
     * is the durable copy.
     */
    public function popular(Market $market, ?string $categoryId, int $limit): PopularChart
    {
        if (! $this->supportsCharts($market)) {
            return PopularChart::empty();
        }

        $pageSize = min(50, max(1, (int) config('brandcoves.connectors.bol.popular.page_size', 50)));
        $maxPages = max(1, (int) config('brandcoves.connectors.bol.popular.pages', 2));

        $entries = [];
        $categories = [];
        $rank = 0;
        /** @var array<string, true> $seen */
        $seen = [];

        for ($page = 1; $page <= $maxPages && count($entries) < $limit; $page++) {
            if (! $this->limiter('popular')->attempt()) {
                // Degrade to a partial chart rather than waiting on a limit that
                // is already refusing. A short snapshot is still a usable one:
                // rank order is preserved, and tomorrow's run fills the tail.
                Log::info('bol popular list truncated: rate limited', [
                    'market' => $market->value,
                    'category' => $categoryId,
                    'collected' => count($entries),
                ]);

                break;
            }

            $response = $this->request('/products/lists/popular', $market, 'popular', array_filter([
                'country-code' => $market->bolCountry(),
                'category-id' => $categoryId,
                'page' => $page,
                'page-size' => $pageSize,
                // Without these bol returns the catalogue entry alone — no offer
                // and no image — so every entry would arrive unpriced and
                // unrenderable, and OfferUpserter would reject the lot.
                'include-offer' => 'true',
                'include-image' => 'true',
                // The only way to discover a category id. See ChartCategory.
                'include-relevant-categories' => 'true',
            ], fn ($value) => $value !== null));

            if ($response === null) {
                break;
            }

            $products = $this->chartProducts($response, $market, $categoryId, $page);

            // Categories ride back on every page; the first page is enough, but
            // merging is cheap and a later page occasionally carries more.
            $categories = [...$categories, ...$this->chartCategories($response)];

            if ($products === []) {
                // A genuinely short chart, not an error. Stop paging rather than
                // asking for page 3 of a two-page list.
                break;
            }

            foreach ($products as $product) {
                $offer = $this->normalise($product, $market);
                $rank++;

                // Rank counts every row bol returned, including ones we cannot
                // store. Skipping the increment would silently promote the next
                // product into a position it never held, and rank movement is
                // the signal this whole table exists to measure.
                if (! $offer?->isValid() || count($entries) >= $limit) {
                    continue;
                }

                /*
                 * bol repeats products across pages.
                 *
                 * The "popular" list is the whole catalogue in popularity order
                 * — 300,000 results — and that ordering is not stable between
                 * requests, so paging through it returns some products twice.
                 * Found on the first live run: the daily rank upsert died with
                 * "ON CONFLICT DO UPDATE command cannot affect row a second
                 * time", because one statement carried the same product twice.
                 *
                 * The FIRST sighting wins. A product listed at #5 and again at
                 * #37 is at #5; keeping the later one would report a fall that
                 * never happened.
                 */
                if (isset($seen[$offer->externalId])) {
                    continue;
                }

                $seen[$offer->externalId] = true;
                $entries[] = new ChartEntry($offer, $rank);
            }

            if (count($products) < $pageSize) {
                break;
            }
        }

        return new PopularChart(
            entries: $entries,
            categories: array_values($this->uniqueCategories($categories)),
        );
    }

    /** Charts are separately switchable: they cost requests on a schedule, search does not. */
    private function supportsCharts(Market $market): bool
    {
        return $this->supports($market)
            && (bool) config('brandcoves.connectors.bol.popular.enabled');
    }

    /**
     * The product rows out of a chart response.
     *
     * The envelope key is NOT documented for this endpoint. `/products/search`
     * uses `results`, so that is tried first — but a wrong key here fails
     * silently, and an empty array is indistinguishable from "bol charts nothing
     * in this category". Hence the warning: this is precisely the shape of bug
     * that survives a green test suite for months.
     *
     * @return list<array<string, mixed>>
     */
    private function chartProducts(Response $response, Market $market, ?string $categoryId, int $page): array
    {
        foreach (['results', 'products'] as $key) {
            $rows = $response->json($key);

            if (is_array($rows)) {
                return array_values(array_filter($rows, 'is_array'));
            }
        }

        Log::warning('bol popular list returned an unrecognised envelope', [
            'market' => $market->value,
            'category' => $categoryId,
            'page' => $page,
            // Keys only. The payload is large and contains nothing secret, but
            // logging it wholesale turns one bad response into a megabyte of log.
            'keys' => array_keys((array) $response->json()),
        ]);

        return [];
    }

    /**
     * Categories bol says are relevant to this chart.
     *
     * Field names read off a live response, not from the docs — the same rule
     * the search normaliser follows, and for the same reason. The block is
     * `allRelevantCategories`, and its rows carry `categoryId`, `categoryName`,
     * `productCount` and a nested `subcategories` array. An earlier version
     * guessed `categories` / `id` / `name` / `count`, matched nothing, and
     * returned an empty list — which looks exactly like "bol named no
     * categories" and would have quietly pinned the crawl to the market-wide
     * chart forever.
     *
     * @return list<ChartCategory>
     */
    private function chartCategories(Response $response): array
    {
        $rows = $response->json('allRelevantCategories')
            ?? $response->json('categories')
            ?? [];

        return is_array($rows) ? $this->flattenCategories($rows, null) : [];
    }

    /**
     * One level of nesting is worth taking: `subcategories` hands us children
     * and their parent for free, on a request we have already paid for.
     *
     * @param  array<int|string, mixed>  $rows
     * @return list<ChartCategory>
     */
    private function flattenCategories(array $rows, ?string $parentId): array
    {
        $categories = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = (string) ($row['categoryId'] ?? $row['id'] ?? '');

            $category = new ChartCategory(
                externalId: $id,
                name: trim((string) ($row['categoryName'] ?? $row['name'] ?? '')),
                parentExternalId: $parentId,
                // Zero here means "bol did not count", not "empty category" —
                // the field comes back 0 on every row of the market-wide chart.
                productCount: ((int) ($row['productCount'] ?? 0)) ?: null,
            );

            if (! $category->isValid()) {
                continue;
            }

            $categories[] = $category;

            $children = $row['subcategories'] ?? [];

            if (is_array($children) && $children !== []) {
                $categories = [...$categories, ...$this->flattenCategories($children, $id)];
            }
        }

        return $categories;
    }

    /**
     * @param  list<ChartCategory>  $categories
     * @return array<string, ChartCategory>
     */
    private function uniqueCategories(array $categories): array
    {
        $unique = [];

        foreach ($categories as $category) {
            $unique[$category->externalId] ??= $category;
        }

        return $unique;
    }

    /**
     * bol's raw product rows, straight from the API.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchSearch(string $query, Market $market, int $limit): array
    {
        if (! $this->limiter('search')->attempt()) {
            // Degrade rather than queue behind a limit that is already
            // refusing. The caller shows whatever the other sources returned.
            Log::info('bol search skipped: rate limited', ['market' => $market->value]);

            return [];
        }

        $response = $this->request('/products/search', $market, 'search', [
            // `search-term`, not `q`. The parameter names below are the ones v1
            // has been running against this API in production for months —
            // taken from its connector rather than guessed.
            'search-term' => $query,
            'country-code' => $market->bolCountry(),
            'page-size' => min($limit, 50),
            // Without these bol returns the catalogue entry and omits the offer
            // and the image entirely, so every result would arrive with no
            // price and nothing to render.
            'include-offer' => 'true',
            'include-image' => 'true',
        ]);

        if ($response === null) {
            return [];
        }

        // The envelope is `results`. A wrong key here fails silently — an empty
        // array is indistinguishable from "bol found nothing", which is how a
        // broken connector survives a green test suite.
        $products = $response->json('results') ?? [];

        // Raw payload, not Offers: the caller caches this, and only plain
        // arrays are safe to put in a shared cache.
        return is_array($products) ? array_values(array_filter($products, 'is_array')) : [];
    }

    /** @param array<string, mixed> $params */
    private function request(string $path, Market $market, string $bucket, array $params = []): ?Response
    {
        $token = $this->accessToken();
        if ($token === null) {
            return null;
        }

        try {
            $response = Http::timeout(8)
                ->retry(2, 200, throw: false)
                ->withToken($token)
                ->withHeaders([
                    'Accept' => 'application/json',
                    // bol has no English catalogue, so the English market gets
                    // Dutch product names rather than no results at all.
                    'Accept-Language' => $market->bolAcceptLanguage(),
                ])
                ->get(self::API_BASE.$path, $params);
        } catch (Throwable $e) {
            Log::warning('bol request failed', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }

        if ($response->status() === 429) {
            // The upstream has told us our own accounting is wrong. Back off
            // wholesale rather than retrying into the wall.
            $this->limiter($bucket)->penalise(
                (int) config('brandcoves.connectors.bol.cooldown_seconds')
            );
            Log::warning('bol rate limited us; backing off', ['bucket' => $bucket]);

            return null;
        }

        if ($response->status() === 401) {
            // Token expired early. Drop it so the next call re-authenticates.
            Cache::forget($this->tokenCacheKey());

            return null;
        }

        if ($response->failed()) {
            Log::warning('bol returned an error', ['path' => $path, 'status' => $response->status()]);

            return null;
        }

        return $response;
    }

    /**
     * OAuth2 client-credentials token, cached slightly short of its real
     * lifetime so a request never races the expiry.
     */
    private function accessToken(): ?string
    {
        return Cache::remember($this->tokenCacheKey(), 240, function (): ?string {
            try {
                $response = Http::asForm()
                    ->timeout(8)
                    ->withBasicAuth(
                        (string) config('brandcoves.connectors.bol.client_id'),
                        (string) config('brandcoves.connectors.bol.client_secret'),
                    )
                    ->post(self::TOKEN_URL, ['grant_type' => 'client_credentials']);

                if ($response->failed()) {
                    Log::warning('bol token request failed', ['status' => $response->status()]);

                    return null;
                }

                return $response->json('access_token');
            } catch (Throwable $e) {
                Log::warning('bol token request threw', ['error' => $e->getMessage()]);

                return null;
            }
        });
    }

    /**
     * bol's payload → our canonical Offer.
     *
     * Every field name below was read off a live response, not from the docs.
     * The catalogue API returns `bolProductId`, `description` and a `gpc`
     * taxonomy array — not `id`, `summary` and `attributes`.
     *
     * @param  array<string, mixed>  $product
     */
    private function normalise(array $product, Market $market): ?Offer
    {
        // bolProductId first: the EAN identifies the *product*, and two
        // merchants' listings of it would collide on one external id.
        $id = (string) ($product['bolProductId'] ?? $product['id'] ?? $product['ean'] ?? '');
        $title = trim((string) ($product['title'] ?? ''));

        if ($id === '' || $title === '') {
            return null;
        }

        $offerData = $product['offer'] ?? [];
        $price = isset($offerData['price']) ? (int) round(((float) $offerData['price']) * 100) : null;

        return new Offer(
            source: Source::Bol,
            externalId: $id,
            market: $market,
            title: $title,
            affiliateUrl: $this->affiliateUrl($product, $market),
            price: $price,
            // HTML, and often long: bol embeds <br> and <ul> in the description.
            description: $this->description($product),
            // Not returned by this endpoint at all. Left null rather than
            // guessed from the title — a wrong brand is worse than none, since
            // grouping and the brand facet both key on it.
            brand: null,
            merchantName: 'bol.com',
            merchantExternalId: 'bol',
            merchantDeepLink: $product['url'] ?? null,
            merchantCategory: $this->category($product),
            imageUrl: $this->image($product),
            ean: (string) ($product['ean'] ?? '') ?: null,
            // The "was" price. Recorded, but never used for a discount badge —
            // those measure against our own 30-day median, because a merchant's
            // reference price is frequently fiction.
            referencePrice: isset($offerData['strikethroughPrice'])
                ? (int) round(((float) $offerData['strikethroughPrice']) * 100)
                : null,
            /*
             * There is no `available` flag in the response.
             *
             * bol only returns an `offer` block for something it can sell, so a
             * price IS the availability signal — the same inference v1 makes.
             * Treating a missing flag as "out of stock" would mark every single
             * result unbuyable and quietly remove bol from the site.
             */
            availability: $price !== null
                ? Availability::InStock
                : Availability::OutOfStock,
        );
    }

    /**
     * The product's own category, from bol's GPC taxonomy.
     *
     * The array runs coarse to fine — SEGMENT, FAMILY, CLASS, CHUNK. The last
     * entry is the most specific, and specificity is what makes a category
     * useful for grouping and for the serendipity engine's rarity signal.
     *
     * @param  array<string, mixed>  $product
     */
    private function category(array $product): ?string
    {
        $levels = $product['gpc'] ?? [];

        if (! is_array($levels) || $levels === []) {
            return null;
        }

        $last = end($levels);
        $name = is_array($last) ? trim((string) ($last['name'] ?? '')) : '';

        return $name !== '' ? $name : null;
    }

    /** @param array<string, mixed> $product */
    private function description(array $product): ?string
    {
        $raw = trim((string) ($product['description'] ?? $product['summary'] ?? ''));

        if ($raw === '') {
            return null;
        }

        // bol embeds markup. Strip it here, at the boundary, so nothing
        // downstream has to decide whether this field is safe to render.
        $text = trim(html_entity_decode(strip_tags(str_replace(['<br />', '<br>', '</li>'], ' ', $raw))));

        return $text !== '' ? mb_substr($text, 0, 2000) : null;
    }

    /**
     * A tracked link, via bol's own click redirector.
     *
     * bol attributes a sale through `partner.bol.com/click/click` carrying a
     * site id — NOT through a parameter appended to the product URL. This
     * matters more than it looks: an untracked link works perfectly. The
     * visitor clicks, buys, and the commission goes to nobody, and nothing in
     * the site reports a problem. It is the one bug here that is invisible from
     * the outside and only shows up as an empty statement.
     *
     * @param  array<string, mixed>  $product
     */
    private function affiliateUrl(array $product, Market $market): string
    {
        $url = (string) ($product['url'] ?? '');
        if ($url === '') {
            return '';
        }

        // bol sometimes returns a path rather than an absolute URL.
        if (! str_starts_with($url, 'http')) {
            $url = 'https://www.bol.com'.$url;
        }

        $siteId = $market->bolPartnerSiteId();

        // No site id for this market: send the plain product URL rather than a
        // tracker with an empty id, which bol rejects.
        if ($siteId === null) {
            return $url;
        }

        return 'https://partner.bol.com/click/click?'.http_build_query([
            'p' => '2',
            't' => 'url',
            's' => $siteId,
            'f' => 'TXL',
            'name' => 'brandcoves-'.$market->value,
            'url' => $url,
        ]);
    }

    /**
     * The product image.
     *
     * bol returns a single `image` object on search results. The plural
     * `images` array is checked as a fallback because the product endpoint
     * shapes it that way.
     *
     * @param  array<string, mixed>  $product
     */
    private function image(array $product): ?string
    {
        $single = $product['image']['url'] ?? null;
        if (is_string($single) && $single !== '') {
            return $single;
        }

        foreach ($product['images'] ?? [] as $image) {
            $url = $image['url'] ?? null;
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return null;
    }

    /**
     * One bucket per endpoint. bol's limits are documented per endpoint and the
     * endpoints do not share a budget, so a single global bucket would either
     * over-restrict search or under-restrict everything else.
     *
     * A bucket may override the rate, and `popular` does. Because the buckets
     * share no budget, every additional bucket at the default 8/s raises the
     * ceiling on what this connector can emit in a second — and the chart puller
     * runs on a schedule, in a worker, while visitors are searching. Background
     * work loses that race by construction rather than by luck.
     */
    private function limiter(string $endpoint): RateLimiter
    {
        return new RateLimiter(
            bucket: "bol:{$endpoint}",
            rate: (float) config(
                "brandcoves.connectors.bol.{$endpoint}.rate",
                config('brandcoves.connectors.bol.rate'),
            ),
            capacity: (int) config(
                "brandcoves.connectors.bol.{$endpoint}.burst",
                config('brandcoves.connectors.bol.burst'),
            ),
        );
    }

    private function tokenCacheKey(): string
    {
        return 'bc:bol:token';
    }
}

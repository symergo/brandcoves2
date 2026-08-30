<?php

declare(strict_types=1);

namespace App\Services\Connectors\Ebay;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\Source;
use App\Services\Connectors\LiveConnector;
use App\Services\Connectors\Offer;
use App\Services\Connectors\RateLimiter;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * eBay's Browse API, queried live.
 *
 * Live rather than ingested for a different reason than bol. bol has no feed we
 * can take; eBay has feeds, and they are the wrong shape — its inventory is
 * *listings*, which end, sell out and reprice on a timescale a nightly download
 * cannot follow. A comparison page quoting yesterday's price for a listing that
 * closed is worse than one that omits eBay.
 *
 * Every public method degrades instead of throwing, by the LiveConnector
 * contract: a dead, unconfigured or rate-limited eBay means a smaller result
 * set, never a broken search page.
 *
 * ## Not a PopularityConnector
 *
 * bol registers under both capabilities because it publishes a browse-ordered
 * bestseller list — a demand signal nobody typed a query to produce. eBay has
 * no equivalent: its "trending" surfaces are web pages, not API endpoints, and
 * sorting a search term by best match is relevance, which is the one thing a
 * demand signal must not be. Charting eBay would mean scraping, so eBay does
 * not chart.
 */
class EbayConnector implements LiveConnector
{
    private const TOKEN_URL = 'https://api.ebay.com/identity/v1/oauth2/token';

    private const API_BASE = 'https://api.ebay.com/buy/browse/v1';

    /**
     * The only scope an application token can hold, and all Browse needs here.
     *
     * Browse's *other* methods — the ones that read a real buyer's cart or
     * their saved searches — need a user token from an OAuth consent flow.
     * Search and item lookup do not, which is what lets this run from a queue
     * worker with no user in sight.
     */
    private const SCOPE = 'https://api.ebay.com/oauth/api_scope';

    public function source(): Source
    {
        return Source::Ebay;
    }

    public function supports(Market $market): bool
    {
        return (bool) config('giftcoves.connectors.ebay.enabled')
            && filled(config('giftcoves.connectors.ebay.client_id'))
            && filled(config('giftcoves.connectors.ebay.client_secret'))
            // A blank marketplace means "do not ask eBay about this market",
            // never "use the default". A request to the wrong marketplace
            // succeeds and returns priced, buyable, irrelevant results.
            && $market->ebayMarketplace() !== null;
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

        $cacheKey = sprintf('bc:ebay:search:%s:%s:%d', $market->value, sha1(mb_strtolower($query)), $limit);

        /*
         * The cache holds eBay's RAW PAYLOAD, never our Offer objects.
         *
         * The same rule BolConnector documents at length, and it is not a
         * stylistic one: a serialised `App\Services\Connectors\Offer` in Redis
         * makes every warm cache hit depend on that class still being loadable
         * and still having the same shape, and a redeploy breaks both at once.
         * Re-normalising an array costs microseconds and cannot go stale
         * against a class definition.
         */
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $this->offersFrom($cached, $market);
        }

        $items = $this->fetchSearch($query, $market, $limit);

        // An empty result is not cached: every degraded path here returns an
        // empty array, so caching one would blank eBay for this query for the
        // whole window, long after the cause had cleared. Same trap as bol's.
        if ($items !== []) {
            Cache::put($cacheKey, $items, (int) config('giftcoves.search.live_cache_ttl'));
        }

        return $this->offersFrom($items, $market);
    }

    public function fetchById(string $externalId, Market $market): ?Offer
    {
        if (! $this->supports($market) || trim($externalId) === '') {
            return null;
        }

        // A separate bucket: eBay meters each Browse call against its own daily
        // quota, so a drained search budget must not block a wishlist re-check.
        if (! $this->limiter('item')->attempt()) {
            return null;
        }

        // The RESTful item id carries pipes ("v1|123456789|0"), which have to
        // survive into the path rather than being read as a delimiter.
        $response = $this->request('/item/'.rawurlencode($externalId), $market, 'item');

        if ($response === null) {
            return null;
        }

        $item = $response->json();

        return is_array($item) ? $this->normalise($item, $market) : null;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<Offer>
     */
    private function offersFrom(array $items, Market $market): array
    {
        $offers = [];

        foreach ($items as $item) {
            $offer = is_array($item) ? $this->normalise($item, $market) : null;

            if ($offer?->isValid()) {
                $offers[] = $offer;
            }
        }

        return $offers;
    }

    /**
     * eBay's raw item summaries, straight from the API.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchSearch(string $query, Market $market, int $limit): array
    {
        if (! $this->limiter('search')->attempt()) {
            // Degrade rather than queue behind a limit that is already
            // refusing. The caller shows whatever the other sources returned.
            Log::info('ebay search skipped: rate limited', ['market' => $market->value]);

            return [];
        }

        $response = $this->request('/item_summary/search', $market, 'search', array_filter([
            'q' => $query,
            // eBay caps this at 200; the caller asks for a page's worth.
            'limit' => (string) min(max($limit, 1), 200),
            'filter' => (string) config('giftcoves.connectors.ebay.filter'),
        ], fn (string $value): bool => $value !== ''));

        if ($response === null) {
            return [];
        }

        /*
         * The envelope is `itemSummaries`, and it is ABSENT — not empty — when
         * eBay matches nothing.
         *
         * Worth stating because the two cases are the same to us and are not
         * the same to eBay: a zero-result search returns a body carrying only
         * `total` and `href`, with no `itemSummaries` key at all. A wrong key
         * here fails silently, which is how a broken connector survives a green
         * test suite.
         */
        $items = $response->json('itemSummaries') ?? [];

        // Raw payload, not Offers: the caller caches this, and only plain
        // arrays are safe to put in a shared cache.
        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
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
                ->withHeaders(array_filter([
                    'Accept' => 'application/json',
                    // Which catalogue, which currency, which shipping. Every
                    // Browse call is meaningless without it.
                    'X-EBAY-C-MARKETPLACE-ID' => $market->ebayMarketplace(),
                    'X-EBAY-C-ENDUSERCTX' => $this->endUserContext($market),
                ], fn (?string $value): bool => $value !== null && $value !== ''))
                ->get(self::API_BASE.$path, $params);
        } catch (Throwable $e) {
            Log::warning('ebay request failed', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }

        if ($response->status() === 429) {
            // eBay's Browse limit is a daily quota rather than a per-second
            // window, so a 429 usually means the day is spent. Back off long
            // rather than retrying into the wall.
            $this->limiter($bucket)->penalise(
                (int) config('giftcoves.connectors.ebay.cooldown_seconds')
            );
            Log::warning('ebay rate limited us; backing off', ['bucket' => $bucket]);

            return null;
        }

        if ($response->status() === 401) {
            // Token expired early, or was revoked. Drop it so the next call
            // re-authenticates instead of replaying a dead one for an hour.
            Cache::forget($this->tokenCacheKey());

            return null;
        }

        if ($response->status() === 404) {
            // A listing that has ended. Expected, frequent, and not a fault —
            // it is the case fetchById exists to catch — so it is not logged.
            return null;
        }

        if ($response->failed()) {
            Log::warning('ebay returned an error', ['path' => $path, 'status' => $response->status()]);

            return null;
        }

        return $response;
    }

    /**
     * The header that makes a link earn.
     *
     * `affiliateCampaignId` is what tells eBay to include `itemAffiliateWebUrl`
     * in the response. Without it every field still arrives, the search still
     * works, the links still resolve — and no click is ever attributed. It is
     * the same invisible failure as bol's site id, which is why `bc:check-ebay`
     * reports a missing campaign id in red rather than as a note.
     *
     * `affiliateReferenceId` is our own label on the click, echoed back in EPN
     * reporting; the market is the only dimension worth splitting on here.
     */
    private function endUserContext(Market $market): ?string
    {
        $campaignId = $market->ebayCampaignId();

        if ($campaignId === null) {
            return null;
        }

        return sprintf(
            'affiliateCampaignId=%s,affiliateReferenceId=%s',
            $campaignId,
            'giftcoves-'.$market->value,
        );
    }

    /**
     * OAuth2 client-credentials token, cached short of its real lifetime.
     *
     * eBay issues application tokens with a two-hour life. Cached for one hour
     * rather than the full two so a request never races the expiry — the 401
     * branch above is the safety net, not the plan.
     */
    private function accessToken(): ?string
    {
        return Cache::remember($this->tokenCacheKey(), 3600, function (): ?string {
            try {
                $response = Http::asForm()
                    ->timeout(8)
                    ->withBasicAuth(
                        (string) config('giftcoves.connectors.ebay.client_id'),
                        (string) config('giftcoves.connectors.ebay.client_secret'),
                    )
                    ->post(self::TOKEN_URL, [
                        'grant_type' => 'client_credentials',
                        'scope' => self::SCOPE,
                    ]);

                if ($response->failed()) {
                    Log::warning('ebay token request failed', ['status' => $response->status()]);

                    return null;
                }

                return $response->json('access_token');
            } catch (Throwable $e) {
                Log::warning('ebay token request threw', ['error' => $e->getMessage()]);

                return null;
            }
        });
    }

    /**
     * eBay's payload to our canonical Offer.
     *
     * The item summary returned by search and the item returned by the detail
     * endpoint share most of their field names, which is what lets one
     * normaliser serve both. Where they differ the detail endpoint is the
     * richer one, and its extra fields are read defensively rather than assumed.
     *
     * @param  array<string, mixed>  $item
     */
    private function normalise(array $item, Market $market): ?Offer
    {
        $id = (string) ($item['itemId'] ?? '');
        $title = trim((string) ($item['title'] ?? ''));

        if ($id === '' || $title === '') {
            return null;
        }

        $price = $this->price($item['price'] ?? null, $market, $id);

        /*
         * A listing we could not price is dropped, not stored unpriced.
         *
         * Unlike a feed row — where a missing price is a formatting problem and
         * the product behind it is still real — an eBay item summary without a
         * usable price is either an auction that slipped the filter or a
         * currency this market cannot compare. Both are things a comparison
         * page must not carry, and a null price downstream reads as "unknown",
         * not as "unsafe to show".
         */
        if ($price === null) {
            return null;
        }

        return new Offer(
            source: Source::Ebay,
            externalId: $id,
            market: $market,
            title: $title,
            affiliateUrl: $this->affiliateUrl($item),
            price: $price,
            // Search returns no description at all; the detail endpoint returns
            // `shortDescription`. Never `description`, which is seller-authored
            // HTML and frequently carries their whole shop layout.
            description: $this->description($item),
            /*
             * Absent from an item summary entirely, and only sometimes present
             * on the detail. Left null rather than parsed out of the title,
             * which on eBay is written for eBay's own search engine and reads
             * "NEW Sony WH-1000XM5 Wireless Headphones Black *FREE SHIPPING*".
             * BrandAttribution fills it in where the query itself was a brand.
             */
            brand: $this->brand($item),
            merchantName: 'eBay',
            /*
             * One merchant row for eBay, not one per seller.
             *
             * The seller is in the payload and is deliberately not used here.
             * `merchants` is the shop directory — the list a visitor reads as
             * "who you compare" — and eBay has millions of sellers, so keying
             * on them would turn a page of six shops into an unbounded one and
             * make every eBay offer look like a shop nobody has heard of. eBay
             * is the shop; the seller is a detail of the listing.
             */
            merchantExternalId: 'ebay',
            // The plain listing URL, which is the only place the domain can
            // come from — the affiliate URL points at eBay's click tracker.
            merchantDeepLink: $this->itemUrl($item),
            merchantCategory: $this->category($item),
            imageUrl: $this->image($item),
            // Search does not return one; the detail endpoint returns `gtin`.
            // See docs/features/ebay-connector.md for what that costs grouping.
            ean: $this->gtin($item),
            referencePrice: $this->price($item['marketingPrice']['originalPrice'] ?? null, $market, $id),
            currency: $market->currency(),
            /*
             * A returned listing is a live listing.
             *
             * Browse only returns items that are currently buyable, so
             * availability is not a field to read but a property of having been
             * returned at all. `estimatedAvailabilities` exists on the detail
             * endpoint and is deliberately not consulted: it reports quantity,
             * and a listing with one left is in stock.
             */
            availability: Availability::InStock,
        );
    }

    /**
     * eBay's value-and-currency price to integer cents, or null.
     *
     * ## A foreign currency is dropped, not converted
     *
     * eBay returns the listing's own currency, and a marketplace does carry
     * listings priced in another one. Every market here is euro
     * ({@see Market::currency()}) and `products.price` has no per-row currency,
     * so a converted number would enter the min and median aggregates that
     * decide "cheapest offer" — at a rate nobody recorded, on a day nobody
     * remembers. Dropping the listing costs one result. Keeping it makes the
     * cheapest-offer badge wrong, which is the one claim on the page that has
     * to be exactly right.
     */
    private function price(mixed $price, Market $market, string $itemId): ?int
    {
        if (! is_array($price)) {
            return null;
        }

        $value = $price['value'] ?? null;
        $currency = strtoupper(trim((string) ($price['currency'] ?? '')));

        if (! is_numeric($value)) {
            return null;
        }

        if ($currency !== '' && $currency !== $market->currency()) {
            Log::info('ebay listing dropped: foreign currency', [
                'market' => $market->value,
                'item' => $itemId,
                'currency' => $currency,
            ]);

            return null;
        }

        return (int) round(((float) $value) * 100);
    }

    /**
     * The tracked link, or the plain one.
     *
     * `itemAffiliateWebUrl` is present only when the request carried a campaign
     * id — see endUserContext(). Falling back to `itemWebUrl` keeps the site
     * working without EPN credentials; it just earns nothing, which is why the
     * fallback is worth noticing rather than being quietly fine.
     *
     * @param  array<string, mixed>  $item
     */
    private function affiliateUrl(array $item): string
    {
        $affiliate = trim((string) ($item['itemAffiliateWebUrl'] ?? ''));

        return $affiliate !== '' ? $affiliate : $this->itemUrl($item);
    }

    /** @param array<string, mixed> $item */
    private function itemUrl(array $item): string
    {
        return trim((string) ($item['itemWebUrl'] ?? ''));
    }

    /**
     * The most specific category eBay names.
     *
     * `categories` runs coarse to fine, so the last entry is the useful one —
     * specificity is what the serendipity engine's rarity signal reads. The
     * detail endpoint says `categoryPath` instead, a pipe-separated string.
     *
     * @param  array<string, mixed>  $item
     */
    private function category(array $item): ?string
    {
        $categories = $item['categories'] ?? null;

        if (is_array($categories) && $categories !== []) {
            $last = end($categories);
            $name = is_array($last) ? trim((string) ($last['categoryName'] ?? '')) : '';

            if ($name !== '') {
                return $name;
            }
        }

        $path = trim((string) ($item['categoryPath'] ?? ''));

        if ($path === '') {
            return null;
        }

        $parts = explode('|', $path);
        $leaf = trim((string) end($parts));

        return $leaf !== '' ? $leaf : null;
    }

    /**
     * The barcode, which only the detail endpoint carries.
     *
     * `gtin` is a plain string there; `ean` and `upc` are arrays on some
     * responses. All three are read because which one a listing carries depends
     * on how the seller filled the form, and an offer with a barcode groups
     * with the same product at Coolblue while one without sits alone.
     *
     * @param  array<string, mixed>  $item
     */
    private function gtin(array $item): ?string
    {
        foreach (['gtin', 'ean', 'upc'] as $field) {
            $value = $item[$field] ?? null;

            if (is_array($value)) {
                $value = $value[0] ?? null;
            }

            $barcode = trim((string) ($value ?? ''));

            if ($barcode !== '') {
                return $barcode;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $item */
    private function brand(array $item): ?string
    {
        $brand = trim((string) ($item['brand'] ?? ''));

        return $brand !== '' ? $brand : null;
    }

    /** @param array<string, mixed> $item */
    private function description(array $item): ?string
    {
        $raw = trim((string) ($item['shortDescription'] ?? ''));

        if ($raw === '') {
            return null;
        }

        // Sellers put markup in here too. Stripped at the boundary, so nothing
        // downstream has to decide whether this field is safe to render.
        $text = trim(html_entity_decode(strip_tags(str_replace(['<br />', '<br>', '</li>'], ' ', $raw))));

        return $text !== '' ? mb_substr($text, 0, 2000) : null;
    }

    /**
     * The listing image.
     *
     * `image.imageUrl` on both endpoints, with `thumbnailImages` as the
     * fallback — a listing occasionally has thumbnails and no primary image,
     * and a card with no picture is a card nobody clicks.
     *
     * @param  array<string, mixed>  $item
     */
    private function image(array $item): ?string
    {
        $primary = $item['image']['imageUrl'] ?? null;

        if (is_string($primary) && $primary !== '') {
            return $primary;
        }

        foreach ($item['thumbnailImages'] ?? [] as $image) {
            $url = $image['imageUrl'] ?? null;

            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return null;
    }

    /**
     * One bucket per endpoint, as bol does — but for a different reason.
     *
     * bol's limits are documented per endpoint. eBay's are a daily call quota
     * per method, which is the same structural fact: search and item lookup
     * spend separate budgets, so a search-heavy hour must not be able to stop a
     * wishlist re-check.
     */
    private function limiter(string $endpoint): RateLimiter
    {
        return new RateLimiter(
            bucket: "ebay:{$endpoint}",
            rate: (float) config(
                "giftcoves.connectors.ebay.{$endpoint}.rate",
                config('giftcoves.connectors.ebay.rate'),
            ),
            capacity: (int) config(
                "giftcoves.connectors.ebay.{$endpoint}.burst",
                config('giftcoves.connectors.ebay.burst'),
            ),
        );
    }

    private function tokenCacheKey(): string
    {
        return 'bc:ebay:token';
    }
}

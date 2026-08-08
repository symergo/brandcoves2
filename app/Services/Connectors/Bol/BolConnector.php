<?php

declare(strict_types=1);

namespace App\Services\Connectors\Bol;

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
 * bol.com, queried live rather than ingested.
 *
 * Every public method degrades instead of throwing. A dead or rate-limited bol
 * must mean a smaller result set, never a broken search page — the stored Awin
 * index still has plenty to show.
 */
class BolConnector implements LiveConnector
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

        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $offers = $this->fetchSearch($query, $market, $limit);

        /*
         * An empty result is NOT cached.
         *
         * `Cache::remember` would store it, and an empty array is what every
         * degraded path returns — an expired token, a rate limit, a timeout. So
         * one transient failure used to blank bol for this query for the full
         * fifteen minutes, long after the cause had cleared. Found while
         * debugging exactly that: a failed run poisoned the cache and the next
         * (working) run still returned nothing.
         *
         * A real zero-result query is cheap to repeat and rare; a cached
         * failure is expensive and invisible.
         */
        if ($offers !== []) {
            Cache::put($cacheKey, $offers, (int) config('brandcoves.search.live_cache_ttl'));
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

    /** @return list<Offer> */
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
        if (! is_array($products)) {
            return [];
        }

        $offers = [];
        foreach ($products as $product) {
            $offer = is_array($product) ? $this->normalise($product, $market) : null;
            if ($offer?->isValid()) {
                $offers[] = $offer;
            }
        }

        return $offers;
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
     */
    private function limiter(string $endpoint): RateLimiter
    {
        return new RateLimiter(
            bucket: "bol:{$endpoint}",
            rate: (float) config('brandcoves.connectors.bol.rate'),
            capacity: (int) config('brandcoves.connectors.bol.burst'),
        );
    }

    private function tokenCacheKey(): string
    {
        return 'bc:bol:token';
    }
}

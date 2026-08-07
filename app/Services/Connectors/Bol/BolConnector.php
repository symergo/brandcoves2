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

        // Absorbs a burst of identical searches without spending rate budget,
        // and is short enough that a price on a results page is not
        // embarrassingly stale.
        return Cache::remember(
            $cacheKey,
            (int) config('brandcoves.search.live_cache_ttl'),
            fn () => $this->fetchSearch($query, $market, $limit),
        );
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
            'q' => $query,
            'country-code' => $market->bolCountry(),
            'page-size' => min($limit, 50),
        ]);

        if ($response === null) {
            return [];
        }

        $products = $response->json('products') ?? [];
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

    /** @param array<string, mixed> $product */
    private function normalise(array $product, Market $market): ?Offer
    {
        $id = (string) ($product['id'] ?? $product['ean'] ?? '');
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
            description: trim((string) ($product['summary'] ?? '')) ?: null,
            brand: $this->attribute($product, 'brand'),
            merchantName: 'bol.com',
            merchantExternalId: 'bol',
            merchantDeepLink: $product['url'] ?? null,
            merchantCategory: $this->attribute($product, 'category'),
            imageUrl: $this->image($product),
            ean: (string) ($product['ean'] ?? '') ?: null,
            availability: ($offerData['available'] ?? false) === true
                ? Availability::InStock
                : Availability::OutOfStock,
        );
    }

    /** @param array<string, mixed> $product */
    private function affiliateUrl(array $product, Market $market): string
    {
        $url = (string) ($product['url'] ?? '');
        if ($url === '') {
            return '';
        }

        // The partner id turns a plain product URL into a tracked one. Without
        // it the click is real but earns nothing.
        $partnerId = config('brandcoves.connectors.bol.partner_id');
        if (blank($partnerId)) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.http_build_query([
            'bltgh' => $partnerId,
            'Referrer' => 'brandcoves-'.$market->value,
        ]);
    }

    /** @param array<string, mixed> $product */
    private function image(array $product): ?string
    {
        foreach ($product['images'] ?? [] as $image) {
            $url = $image['url'] ?? null;
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $product */
    private function attribute(array $product, string $key): ?string
    {
        foreach ($product['attributes'] ?? [] as $attribute) {
            if (($attribute['key'] ?? null) === $key) {
                $value = trim((string) ($attribute['value'] ?? ''));

                return $value !== '' ? $value : null;
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

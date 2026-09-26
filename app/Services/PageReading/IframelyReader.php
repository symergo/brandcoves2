<?php

declare(strict_types=1);

namespace App\Services\PageReading;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ask Iframely what a page is, when the shop will not show it to us.
 *
 * Owner's call, 2026-09-26. De Bijenkorf's bot protection refuses our reader
 * (and its picture server refuses us too), while the link previews WhatsApp
 * shows come through, because shops let the big preview fetchers in. Iframely
 * is such a fetcher, sold as a service: we send it the link and get back the
 * same Open Graph and product data a chat app shows. Pretending to be WhatsApp
 * ourselves was ruled out: it is a lie, and shops that check the caller's
 * network would refuse it anyway.
 *
 * Only the pasted link goes to Iframely, never who pasted it or onto which
 * list. It is asked only after our own read failed or found no picture, from a
 * queued job, never inside a visitor's request.
 *
 * Costs are bounded twice: one answer per link is cached for everybody (as our
 * own reads are), and at most `iframely_per_day` calls are made per day. With
 * no key configured this does nothing at all.
 */
class IframelyReader
{
    private const API = 'https://iframe.ly/api/';

    public function enabled(): bool
    {
        return $this->key() !== '';
    }

    /** What the page says, or null when Iframely could not tell either. */
    public function read(string $url): ?PageProduct
    {
        if (! $this->enabled()) {
            return null;
        }

        $cacheKey = 'iframely:'.sha1($url);
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached === [] ? null : PageProduct::fromArray($cached);
        }

        if (! $this->spend()) {
            return null;
        }

        try {
            $response = Http::timeout(20)->connectTimeout(5)->acceptJson()
                ->get(self::API.'iframely', ['url' => $url, 'key' => $this->key()]);
        } catch (Throwable $e) {
            Log::info('iframely unreachable', ['reason' => $e->getMessage()]);

            return null;
        }

        $product = $response->successful() ? self::parse((array) $response->json()) : null;

        // A miss is remembered for a day, as our own reads do; a page that
        // Iframely cannot read either will not become readable in a minute.
        Cache::put($cacheKey, $product?->toArray() ?? [], $product === null
            ? now()->addDay()
            : now()->addDays((int) config('giftcoves.page_reading.cache_days', 7)));

        return $product;
    }

    /**
     * The page's picture, as bytes, through Iframely.
     *
     * Iframely's thumbnail endpoint sends the image itself rather than a link
     * to it, which matters: the shop's picture server may refuse us exactly as
     * its pages do (de Bijenkorf's does). The bytes go through `ImageStore`
     * like any other picture: decoded, re-encoded, stored as ours.
     */
    public function thumbnail(string $url): ?string
    {
        if (! $this->enabled() || ! $this->spend()) {
            return null;
        }

        try {
            $response = Http::timeout(20)->connectTimeout(5)
                ->get(self::API.'thumbnail', ['url' => $url, 'key' => $this->key()]);
        } catch (Throwable) {
            return null;
        }

        $max = (int) config('giftcoves.page_reading.max_image_bytes', 8 * 1024 * 1024);

        if (! $response->successful()
            || ! str_starts_with(strtolower((string) $response->header('Content-Type')), 'image/')
            || strlen($response->body()) > $max) {
            return null;
        }

        return $response->body();
    }

    /**
     * Iframely's answer as a PageProduct. Pure, so the mapping is tested on its own.
     *
     * @param  array<string, mixed>  $data
     */
    public static function parse(array $data): ?PageProduct
    {
        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        $title = trim(html_entity_decode((string) ($meta['title'] ?? ''), ENT_QUOTES | ENT_HTML5));

        if ($title === '') {
            return null;
        }

        $thumbnails = is_array($data['links']['thumbnail'] ?? null) ? $data['links']['thumbnail'] : [];
        $image = null;

        foreach ($thumbnails as $thumbnail) {
            $href = is_array($thumbnail) ? (string) ($thumbnail['href'] ?? '') : '';

            if (str_starts_with($href, 'https://')) {
                $image = $href;
                break;
            }
        }

        $currency = isset($meta['currency']) ? strtoupper((string) $meta['currency']) : null;

        return new PageProduct(
            title: mb_substr($title, 0, 255),
            brand: isset($meta['brand']) && is_string($meta['brand']) ? $meta['brand'] : null,
            description: isset($meta['description']) && is_string($meta['description']) ? $meta['description'] : null,
            imageUrl: $image,
            // Prices arrive as JSON numbers; ProductPageParser::cents rounds once
            // at the boundary (invariant 7).
            price: isset($meta['price']) ? ProductPageParser::cents($meta['price']) : null,
            currency: $currency !== null && preg_match('/^[A-Z]{3}$/', $currency) ? $currency : null,
            availability: ProductPageParser::availability($meta['availability'] ?? null),
        );
    }

    /** Count one call against today's cap; false once it is used up. */
    private function spend(): bool
    {
        $key = 'iframely-calls:'.now()->toDateString();
        Cache::add($key, 0, now()->addDays(2));

        return Cache::increment($key) <= (int) config('giftcoves.page_reading.iframely_per_day', 200);
    }

    private function key(): string
    {
        return trim((string) config('giftcoves.page_reading.iframely_key'));
    }
}

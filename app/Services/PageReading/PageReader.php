<?php

declare(strict_types=1);

namespace App\Services\PageReading;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Read one shop page, once, for everybody.
 *
 * A popular product gets pasted by many people, and each of them should not
 * cost the shop a request. The read is cached per URL for
 * `giftcoves.page_reading.cache_days`; a failure is cached for a day, so a page
 * that refuses us is not asked again on every paste.
 *
 * Only ever called from a queued job, never inside a visitor's request: a slow
 * shop must not become a slow GiftCoves page.
 */
class PageReader
{
    public function __construct(
        private readonly SafeFetch $fetch,
        private readonly ProductPageParser $parser,
    ) {}

    /**
     * @throws FetchRefused with reason `busy` when this shop has had its share
     *                      of requests this minute; the caller retries later
     */
    public function read(string $url): ?PageProduct
    {
        $key = 'page-read:'.sha1($url);
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached === [] ? null : PageProduct::fromArray($cached);
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (! RateLimiter::attempt('page-read-host:'.$host, (int) config('giftcoves.page_reading.per_host_per_minute', 20), fn () => true, 60)) {
            throw new FetchRefused('busy', $host);
        }

        try {
            $page = $this->fetch->get(
                $url,
                ['text/html', 'application/xhtml+xml'],
                (int) config('giftcoves.page_reading.max_html_bytes', 2 * 1024 * 1024),
            );
        } catch (FetchRefused $e) {
            /*
             * Remember a refusal only when it will not change: a private
             * address, a file that is not a page, a 404. A 403, a 429, a 5xx
             * or a timeout is a shop's bot protection or a bad minute, and
             * de Bijenkorf lets about half of the same requests through
             * (2026-09-26), so the job tries again rather than remembering
             * "no" for a day.
             */
            if (! self::isPassing($e)) {
                Cache::put($key, [], now()->addDay());
            }

            throw $e;
        }

        $product = $this->parser->parse($page->body, $page->url);

        Cache::put($key, $product?->toArray() ?? [], now()->addDays((int) config('giftcoves.page_reading.cache_days', 7)));

        return $product;
    }

    /** Is this refusal worth asking again about in a minute? */
    public static function isPassing(FetchRefused $e): bool
    {
        if ($e->reason === 'unreachable' || $e->reason === 'busy') {
            return true;
        }

        if ($e->reason !== 'status') {
            return false;
        }

        $status = (int) substr($e->getMessage(), strlen('status: '));

        return in_array($status, [403, 408, 425, 429], true) || $status >= 500;
    }
}

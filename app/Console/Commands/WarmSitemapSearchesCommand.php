<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Market;
use App\Http\Middleware\CacheAnonymousPage;
use App\Services\Search\SitemapSearches;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Fill the page cache with the sitemap's search pages, as a search engine gets them
 * (owner, 2026-11-24: "let's pre-cache pages in the sitemap").
 *
 * Each page is requested over the public address with Googlebot's user agent, so the
 * cached copy is exactly the one CacheAnonymousPage hands Google and Bing: same host,
 * same path, the crawler's version of the page (no market bar), the live shops' answer
 * included. That is a request to ourselves, not a crawl: about twenty pages per
 * market, one at a time.
 *
 * A marker per deployed commit and page says it was done, so the schedule, which runs
 * every quarter of an hour, requests nothing until a deploy empties the cache (the
 * cache key holds the commit) or the day's copy goes stale. The marker expires a
 * little before the cached page does, so the refresh comes before a crawler would
 * find it stale.
 */
class WarmSitemapSearchesCommand extends Command
{
    protected $signature = 'bc:warm-sitemap-searches {--force : Request every page, done or not}';

    protected $description = 'Put the sitemap\'s search pages in the page cache, as search engines get them.';

    /** Googlebot's own user agent: the cached copy must be the crawler's version of the page. */
    private const AGENT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    /** Microseconds between pages; see the note at the request. A test sets it to nothing. */
    public static int $pause = 2_000_000;

    public function handle(): int
    {
        if (! config('giftcoves.robots_allow') || ! config('giftcoves.page_cache.enabled')) {
            $this->components->info('Not the public site, or the page cache is off: nothing to warm.');

            return self::SUCCESS;
        }

        // A day less an hour: refreshed before a search engine meets the copy stale.
        $doneFor = max(60, (int) config('giftcoves.page_cache.crawler_fresh_seconds') - 3600);
        $warmed = $failed = $skipped = 0;

        foreach (Market::cases() as $market) {
            foreach (SitemapSearches::paths($market) as $path) {
                $marker = 'bc:sitemap-search-warm:'.config('giftcoves.commit_sha').':'.sha1($path);

                if (! $this->option('force') && Cache::has($marker)) {
                    $skipped++;

                    continue;
                }

                $response = Http::timeout(60)
                    /*
                     * IPv4. Cloudflare's first rule skips the VPS's own IPv4 address;
                     * over IPv6 the VPS is a data-centre address calling itself
                     * Googlebot, and got the challenge page (403, measured 2026-11-24).
                     */
                    ->withOptions(['force_ip_resolve' => 'v4'])
                    ->withHeaders(['User-Agent' => self::AGENT])
                    ->get(url($path));

                /*
                 * Two seconds between pages. The search route allows 60 requests a
                 * minute per address and Cloudflare 15 in ten seconds; back to back,
                 * the first run on production got 23 of 58 refused (429).
                 */
                usleep(self::$pause);

                $how = (string) $response->header(CacheAnonymousPage::HEADER);

                if ($response->ok() && in_array($how, ['miss', 'hit', 'stale'], true)) {
                    Cache::put($marker, true, $doneFor);
                    $warmed++;
                } else {
                    $failed++;
                    $this->components->warn("{$path}: {$response->status()} ".($how ?: 'no cache header'));
                }
            }
        }

        $this->components->info("Warmed {$warmed}, already warm {$skipped}, failed {$failed}.");

        return self::SUCCESS;
    }
}

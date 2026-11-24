<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\SearchUrl;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Crawlers that cost more than they bring (owner, 2026-11-13; Amzn-SearchBot added 2026-11-14).
 *
 * On 2026-11-13 Amazonbot, SemrushBot and ExaSearchBot made 1,460 of the 2,340
 * search requests in an hour, most of them for terms pasted together from
 * product titles that take seconds of database time each, while gift-idea
 * pages for people timed out. None of the three is a search engine people
 * find the site through.
 *
 * So for these, by name:
 *  - search pages are refused (403). robots.txt says the same, see
 *    SitemapController::robots(); this is for when it is not read.
 *  - every other page is capped per crawler, not per address: they come from
 *    many addresses at once. Over the cap is a 429 with Retry-After.
 *
 * robots.txt itself is always served, or they could never learn the rules.
 * A name match, like App\Support\Crawlers: a scraper hiding its name is
 * Cloudflare's to stop, not this class's.
 */
final class LimitHeavyCrawlers
{
    /** Name in the user agent => the name in robots.txt. */
    public const CRAWLERS = [
        'amazonbot' => 'Amazonbot',
        'semrushbot' => 'SemrushBot',
        'exasearchbot' => 'ExaSearchBot',
        // Amazon's second crawler (owner, 2026-11-14): 299 searches run in an hour.
        'amzn-searchbot' => 'Amzn-SearchBot',
        // Amazon's agent for pages a person asks its assistant about (Alexa+, Rufus).
        // It reached search pages on 2026-11-23 (owner: "stop all bots from amazon").
        'amzn-user' => 'Amzn-User',
        // Moz's link-index crawler for SEO tools (owner, 2026-11-24): 573 requests in a
        // day, 163 of them search pages. Nobody finds the site through it; bstore blocks it too.
        'dotbot' => 'DotBot',
        // Any later Amazon agent named "Amzn-…", matched before it is seen. Last, so the
        // named ones above keep their own rate-limit bucket.
        'amzn-' => 'Amzn',
    ];

    /**
     * Kept off search pages only, not slowed down anywhere else (owner, 2026-11-22).
     *
     * In an hour on 2026-11-22 these made most of the 6,406 search requests (94% of all
     * traffic was crawlers): long terms pasted together from product titles, never cached,
     * seconds of database time each. Two timed out at 30 s and the server sat at a load of
     * 4.5 to 5 on 6 cores. A search page is a list of products that each have their own
     * page, so an AI assistant loses nothing it can cite; product, brand, guide and gift
     * pages stay open to them at full speed. Google and Bing are not on either list.
     *
     * Name in the user agent => the name in robots.txt.
     */
    public const SEARCH_ONLY = [
        'gptbot' => 'GPTBot',
        'claudebot' => 'ClaudeBot',
        // Anthropic's crawler for Claude's web search (2026-11-23): 348 of the 609 search
        // requests in 20 minutes, 1.5 s each at the median. Not matched by "claudebot".
        'claude-searchbot' => 'Claude-SearchBot',
        'meta-externalagent' => 'meta-externalagent',
        // Meta's index for Meta AI search (2026-11-23): 573 search pages in 20 minutes, up to
        // 17 s each. Link previews on Facebook and WhatsApp come from facebookexternalhit instead.
        'meta-webindexer' => 'meta-webindexer',
        'reflectionbot' => 'Reflectionbot',
        'kakaobot' => 'KakaoBot',
    ];

    public const PER_MINUTE = 10;

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('robots.txt') && self::isSearch($request) && self::matches($request->userAgent(), self::SEARCH_ONLY)) {
            return response('Search pages are not open to this crawler. See /robots.txt.', 403, ['Content-Type' => 'text/plain']);
        }

        $crawler = self::which($request->userAgent());

        if ($crawler === null || $request->is('robots.txt')) {
            return $next($request);
        }

        if (self::isSearch($request)) {
            return response('Search pages are not open to this crawler. See /robots.txt.', 403, ['Content-Type' => 'text/plain']);
        }

        /*
         * Product pages too (owner, 2026-11-23: "stop all bots from amazon from
         * searching or opening product pages", then "also block semrush and exa").
         * A product page is a price comparison; none of these four has business
         * reading it.
         */
        if (preg_match('#^[a-z-]+/p/#', $request->path()) === 1) {
            return response('Product pages are not open to this crawler. See /robots.txt.', 403, ['Content-Type' => 'text/plain']);
        }

        $key = 'bc:heavy-crawler:'.$crawler;

        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            return response('Too many requests.', 429, [
                'Content-Type' => 'text/plain',
                'Retry-After' => (string) max(1, RateLimiter::availableIn($key)),
            ]);
        }

        RateLimiter::hit($key, 60);

        return $next($request);
    }

    public static function which(?string $userAgent): ?string
    {
        return self::matches($userAgent, self::CRAWLERS);
    }

    /** @param  array<string, string>  $list */
    private static function matches(?string $userAgent, array $list): ?string
    {
        $ua = strtolower((string) $userAgent);

        foreach (array_keys($list) as $name) {
            if (str_contains($ua, $name)) {
                return $name;
            }
        }

        return null;
    }

    /** /be-nl/search and /be-nl/zoek/…, in every market's word. */
    private static function isSearch(Request $request): bool
    {
        $segments = implode('|', array_map(fn (string $s): string => preg_quote($s, '#'), SearchUrl::segments()));

        return preg_match('#^[a-z-]+/(?:search$|(?:'.$segments.')/)#', $request->path()) === 1;
    }
}

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
    ];

    public const PER_MINUTE = 10;

    public function handle(Request $request, Closure $next): Response
    {
        $crawler = self::which($request->userAgent());

        if ($crawler === null || $request->is('robots.txt')) {
            return $next($request);
        }

        if (self::isSearch($request)) {
            return response('Search pages are not open to this crawler. See /robots.txt.', 403, ['Content-Type' => 'text/plain']);
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
        $ua = strtolower((string) $userAgent);

        foreach (array_keys(self::CRAWLERS) as $name) {
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

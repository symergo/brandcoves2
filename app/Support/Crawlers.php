<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Does this user agent belong to a crawler?
 *
 * Used to keep crawlers out of the search log. A crawler following a link into
 * `/search?q=…` is not a person wanting something, and the log is the site's
 * demand signal: it decides which buying guides get written and what the
 * popular-searches page prints. On 2026-09-08 five in six rows in it had been
 * minted by crawlers walking search links, and the length rule on
 * `SearchLog` catches the long ones only; the short steps on the same walk
 * looked like queries.
 *
 * A name match, not a verification: nobody spoofing Googlebot to keep a
 * search out of a statistics table is a threat worth a DNS lookup. The list is
 * the crawlers seen in this site's logs plus the generic words; a crawler that
 * identifies itself honestly says so in one of these ways.
 */
final class Crawlers
{
    private const PATTERN = '/bot|crawl|spider|slurp|fetch|preview|scan|monitor|archiver|headless|lighthouse|pingdom|curl\/|wget\/|python-requests|go-http-client|okhttp|facebookexternalhit|embedly|quora link|bitlybot|semrush|ahrefs|mj12|dotbot|petalbot|bytespider|gptbot|claudebot|ccbot|applebot|yandex|baidu|duckduck|bingpreview|ia_archiver/i';

    public static function looksLikeOne(?string $userAgent): bool
    {
        $ua = trim((string) $userAgent);

        // No user agent at all is a script, not a browser.
        return $ua === '' || preg_match(self::PATTERN, $ua) === 1;
    }
}

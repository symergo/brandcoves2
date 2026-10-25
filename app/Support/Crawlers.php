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

    /**
     * The search engines whose index the site wants to be in: Google's and
     * Bing's crawlers (Bing's index also feeds DuckDuckGo, Ecosia and others).
     */
    private const SEARCH_ENGINES = '/googlebot|google-inspectiontool|bingbot/i';

    public static function looksLikeOne(?string $userAgent): bool
    {
        $ua = trim((string) $userAgent);

        // No user agent at all is a script, not a browser.
        return $ua === '' || preg_match(self::PATTERN, $ua) === 1;
    }

    /**
     * Whether a page should ask the live shops for this request: a person, or
     * a search engine indexing the page (owner, 2026-10-25).
     *
     * Other crawlers (AI crawlers, SEO tools, scrapers) see what is stored: a
     * crawler walking new search terms cost a live API call and a queued job
     * per page, for nobody. A search engine is the exception because what it
     * sees is what it indexes. On a term little is stored for, it would see an
     * empty page where a person sees the shop's products: an indexable "soft
     * 404", and a page shown differently to the engine than to people. It
     * visits far less often than the scrapers, so most of the saving stays.
     *
     * A name match like `looksLikeOne()`: something calling itself Googlebot
     * gets the shops too. Cloudflare challenges the data-centre networks such
     * fakes come from, and the cost of a fake passing is one live call.
     */
    public static function mayAskShops(?string $userAgent): bool
    {
        return ! self::looksLikeOne($userAgent) || self::isSearchEngine($userAgent);
    }

    /** Google or Bing, by name. */
    public static function isSearchEngine(?string $userAgent): bool
    {
        return preg_match(self::SEARCH_ENGINES, (string) $userAgent) === 1;
    }
}

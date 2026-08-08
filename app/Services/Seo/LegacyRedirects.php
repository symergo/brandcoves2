<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Enums\Market;

/**
 * A short list of v1 entry points that have a real v2 equivalent.
 *
 * **Deliberately not a full URL map.** The obvious thing to build here is a
 * mapping for every indexed v1 path, and most of it would be a lie: v1's
 * article slugs, brand pages, public list URLs and Secret Santa links have no
 * counterpart in v2's schema, so "mapping" them means sending everyone who
 * arrives at `/articles/beste-koptelefoons-2025` to the guides index.
 *
 * That is a soft 404 — a 200 that does not contain what was asked for. Search
 * engines treat a redirect to an irrelevant page as one, and a person who
 * clicked a specific article and landed on a list index has been misled rather
 * than helped. An honest 404 is the better answer.
 *
 * So this covers only the handful of top-level entry points where the
 * destination genuinely *is* the same thing — search is search, the wishlist is
 * the lists page, the gift whisperer is the gift finder. Everything else 404s.
 *
 * ## Rules
 *
 * **301, never 302.** The move is permanent; a 302 tells a crawler to keep the
 * old URL indexed.
 *
 * **One hop, never a chain.** `/nl/search` lands directly on `/nl-nl/search`.
 *
 * **Never redirect to a 404**, and never to a page that does not answer the
 * request.
 *
 * ## v1's language prefixes
 *
 * v1 used WPML with a language directory: `/nl/…`, `/fr/…`, and bare paths for
 * English. v2 uses markets, which are not the same thing — `be-nl` and `nl-nl`
 * share a language and are two catalogues. The mapping picks the larger market
 * for each language; a visitor in the other one gets there via the switcher.
 *
 * `/es/` is skipped on purpose: it is both a v1 language directory and a live
 * v2 market prefix, and guessing which one a request means would break real v2
 * URLs to rescue hypothetical v1 ones.
 */
class LegacyRedirects
{
    /**
     * v1 language directory → the v2 market that inherits its traffic.
     *
     * @var array<string, string>
     */
    private const LANGUAGE_TO_MARKET = [
        'nl' => 'nl-nl',
        'fr' => 'be-fr',
        'en' => 'en',
        // 'es' is absent deliberately — see the class docblock. It is a live v2
        // market prefix, and claiming it here would break real v2 URLs.
    ];

    /**
     * Exact v1 paths, after the language prefix is stripped.
     *
     * Keys have no leading slash. Values are v2 paths relative to the market.
     *
     * @var array<string, string>
     */
    private const EXACT = [
        'search' => 'search',
        'wishlist' => 'lists',
        'login' => 'login',
        'gift-whisperer' => 'gift',
        // Index pages only. `magazine/some-article` is NOT mapped — the article
        // does not exist in v2, and sending someone to the guides index instead
        // is a soft 404.
        'magazine' => 'guides',
        'articles' => 'guides',
        'coves' => 'daily',
    ];

    /**
     * Resolve a v1 path, or null when it is not a v1 URL at all.
     *
     * Returning null matters: this runs as a fallback on 404, and a mapper that
     * answers for everything would swallow genuine v2 typos into a silent
     * redirect instead of a 404 someone can see and fix.
     *
     * @return array{path: string, market: Market}|null
     */
    public function resolve(string $path, Market $fallbackMarket): ?array
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/')), fn ($s) => $s !== ''));

        // Already a v2 URL. Not ours to answer for.
        if (isset($segments[0]) && Market::tryFrom($segments[0]) !== null) {
            return null;
        }

        $market = $fallbackMarket;

        // Strip v1's WPML language directory, if present.
        if (isset($segments[0]) && isset(self::LANGUAGE_TO_MARKET[$segments[0]])) {
            $market = Market::from(self::LANGUAGE_TO_MARKET[$segments[0]]);
            array_shift($segments);
        }

        $key = implode('/', $segments);

        /*
         * Exact matches only.
         *
         * No prefix matching, and no bare-slug fallback. `/articles/foo` is not
         * `/guides` — it is a specific article that no longer exists, and a
         * redirect claiming otherwise is a soft 404 that costs more than an
         * honest one. Anything not on the list falls through to a real 404.
         */
        return array_key_exists($key, self::EXACT)
            ? ['path' => self::EXACT[$key], 'market' => $market]
            : null;
    }

    /** The absolute destination, ready for a 301. */
    public function urlFor(string $path, Market $fallbackMarket): ?string
    {
        $resolved = $this->resolve($path, $fallbackMarket);

        if ($resolved === null) {
            return null;
        }

        $suffix = $resolved['path'] === '' ? '' : '/'.$resolved['path'];

        return url('/'.$resolved['market']->value.$suffix);
    }
}

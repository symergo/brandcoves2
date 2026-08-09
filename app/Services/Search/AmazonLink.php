<?php

declare(strict_types=1);

namespace App\Services\Search;

use Illuminate\Support\Str;

/**
 * A pasted Amazon URL, taken apart.
 *
 * Someone standing on an Amazon product page and wondering who else sells the
 * thing will paste the URL into the nearest box. That box is the search field,
 * so the search field has to understand it. No second input, no "paste a link"
 * mode: a URL is just another kind of query.
 *
 * ## What we get out of it, and what we cannot
 *
 * An Amazon URL carries two useful things. The **ASIN** identifies the product
 * exactly, and the **slug** in front of `/dp/` is the product title with dashes
 * in it — "Sony-WH-1000XM5-Draadloze-Koptelefoon". The slug is the part that
 * actually works today: the catalogue holds no Amazon rows (Phase 8), so an ASIN
 * matches nothing, while the title words search perfectly well against the shops
 * we do carry.
 *
 * So the ASIN is kept for the day it resolves, and the slug is what we search
 * on now.
 *
 * ## Two things this deliberately does not do
 *
 * **It never fetches the URL.** Not to follow a shortlink, not to read a title.
 * A visitor-supplied URL that the server requests is server-side request
 * forgery with a search box in front of it, and even against a host allowlist it
 * would put a third party's latency inside our request handler. `amzn.to` and
 * `amzn.eu` links are therefore recognised and reported as unresolvable rather
 * than expanded.
 *
 * **It does not trust the string to be a URL.** Host matching is done on the
 * parsed host, so `https://evil.test/www.amazon.nl/dp/B0000000` is not an Amazon
 * link, and neither is `https://amazon.nl.evil.test/dp/B0000000`.
 */
final readonly class AmazonLink
{
    /**
     * Guard against a paste of something enormous. A real product URL with every
     * tracking parameter Amazon attaches runs to a few hundred characters.
     */
    private const MAX_INPUT = 2048;

    /**
     * An ASIN is ten characters of A-Z and 0-9. Books use their ISBN-10, which
     * can end in X, and that is already covered by the character class.
     */
    private const ASIN = '[A-Z0-9]{10}';

    /**
     * Path segments that carry no product words. Everything Amazon puts around
     * the title: the marketplace's own routing, the referrer breadcrumb, and the
     * language prefix some domains use.
     */
    private const NOISE_SEGMENTS = [
        'dp', 'gp', 'product', 'aw', 'd', 'ref', 'offer-listing', 'exec', 'obidos',
        'asin', 'sspa', 'stores', 'shops', 'b', 's', 'hz', 'gcx', 'pd', 'pdp',
    ];

    private function __construct(
        public string $host,
        public ?string $asin,
        public string $terms,
        public bool $shortlink,
    ) {}

    /**
     * Parse a pasted string, or null when it is not an Amazon URL.
     *
     * Null means "this is an ordinary search term" — the caller carries on
     * exactly as before, which is what makes this safe to put in front of every
     * search.
     */
    public static function parse(string $input): ?self
    {
        $input = trim($input);

        if ($input === '' || mb_strlen($input) > self::MAX_INPUT) {
            return null;
        }

        // A bare "amazon.nl/dp/..." paste has no scheme, and parse_url reads it
        // as a path. Give it one rather than failing on a link a human would
        // consider perfectly complete.
        if (! preg_match('#^https?://#i', $input)) {
            if (! preg_match('#^(www\.)?(amazon\.[a-z.]{2,6}|amzn\.(to|eu))/#i', $input)) {
                return null;
            }

            $input = 'https://'.$input;
        }

        $parts = parse_url($input);
        $host = mb_strtolower((string) ($parts['host'] ?? ''));

        if (! self::isAmazonHost($host)) {
            return null;
        }

        if (self::isShortener($host)) {
            // Recognised, not resolved. See the class docblock.
            return new self(host: $host, asin: null, terms: '', shortlink: true);
        }

        $path = rawurldecode((string) ($parts['path'] ?? ''));
        parse_str((string) ($parts['query'] ?? ''), $query);

        return new self(
            host: $host,
            asin: self::asin($path, $query),
            terms: self::terms($path, $query),
            shortlink: false,
        );
    }

    /** Something we can actually run a search with. */
    public function isUsable(): bool
    {
        return $this->terms !== '';
    }

    private static function isAmazonHost(string $host): bool
    {
        // The full host, anchored at both ends: a subdomain of amazon.nl is
        // Amazon, a domain that merely contains the string is not.
        return (bool) preg_match('/(^|\.)(amazon\.[a-z]{2,3}(\.[a-z]{2})?|amzn\.(to|eu))$/', $host);
    }

    private static function isShortener(string $host): bool
    {
        return (bool) preg_match('/(^|\.)amzn\.(to|eu)$/', $host);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private static function asin(string $path, array $query): ?string
    {
        $asin = self::ASIN;

        /*
         * Every shape Amazon has shipped that carries an ASIN in the path. They
         * all still resolve and all still get pasted, because a link lives as
         * long as the message it was sent in.
         */
        $patterns = [
            "#/dp/({$asin})#i",
            "#/gp/product/({$asin})#i",
            "#/gp/aw/d/({$asin})#i",
            "#/gp/offer-listing/({$asin})#i",
            "#/product/({$asin})#i",
            "#/exec/obidos/asin/({$asin})#i",
            "#/d/({$asin})(?:/|$)#i",
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $path, $m) === 1) {
                return mb_strtoupper($m[1]);
            }
        }

        foreach (['asin', 'ASIN'] as $key) {
            $value = $query[$key] ?? null;

            if (is_string($value) && preg_match("/^{$asin}$/i", $value) === 1) {
                return mb_strtoupper($value);
            }
        }

        return null;
    }

    /**
     * The words to search for.
     *
     * The title slug first, because it is the product's own name. An explicit
     * `keywords=` parameter second, which is what a link copied from a search
     * results page carries instead.
     *
     * @param  array<string, mixed>  $query
     */
    private static function terms(string $path, array $query): string
    {
        $fromSlug = self::fromSlug($path);

        if ($fromSlug !== '') {
            return $fromSlug;
        }

        $keywords = $query['keywords'] ?? $query['k'] ?? null;

        return is_string($keywords) ? self::clean(str_replace('+', ' ', $keywords)) : '';
    }

    private static function fromSlug(string $path): string
    {
        $segments = array_values(array_filter(explode('/', $path), fn (string $s) => $s !== ''));

        foreach ($segments as $segment) {
            $lower = mb_strtolower($segment);

            if (in_array($lower, self::NOISE_SEGMENTS, true)) {
                continue;
            }

            // A two-letter segment is a language prefix (/nl/, /en/), not a
            // title. A ten-character all-caps token is the ASIN itself.
            if (mb_strlen($segment) <= 2 || preg_match('/^'.self::ASIN.'$/', $segment) === 1) {
                continue;
            }

            // `ref=sr_1_3` and friends survive as their own segment on older
            // link shapes.
            if (str_starts_with($lower, 'ref=')) {
                continue;
            }

            $candidate = self::clean(str_replace(['-', '_', '+'], ' ', $segment));

            // Two words is the floor. One word off an Amazon URL is nearly
            // always a leftover routing token, and searching for it produces a
            // page of unrelated results that looks like we understood the link.
            if (str_word_count($candidate) >= 2) {
                return $candidate;
            }
        }

        return '';
    }

    private static function clean(string $value): string
    {
        // Collapse whitespace and drop the punctuation a slug carries. Length
        // capped because the search index scores on the first handful of words
        // anyway, and a forty-word title is a query nothing matches.
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return Str::limit(trim($value, " \t\n\r\0\x0B-_.,"), 120, '');
    }
}

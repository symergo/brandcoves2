<?php

declare(strict_types=1);

namespace App\Services\Images;

/**
 * Our own address for a merchant's product picture, at the size a page draws it.
 *
 * Product pictures were hot-linked from the shops' own servers, at whatever
 * size the feed carried: the product page drew bol's 250x200 thumbnail 512
 * pixels wide, and a search card on a phone downloaded the same JPEG whatever
 * its width. `/img/{width}/{signature}/{source}` fetches the picture once on
 * the server, shrinks it to the width asked for, encodes it as WebP, keeps the
 * result on disk and serves it with a year's cache. See
 * docs/features/image-proxy.md.
 *
 * ## Never an open proxy
 *
 * A route that fetches whatever URL it is given is a free image host for
 * anyone and a way to make our server request things. So:
 *
 * - **The source URL is signed** with an HMAC under a key derived from
 *   APP_KEY. Only this class signs, and it only signs the pictures our own
 *   pages hand out (`product_groups.image_url` through the controllers), so a
 *   valid signature means "this server put that URL on a page". The width is
 *   NOT signed: one token serves every width in the srcset, and the width is
 *   limited to a short list instead.
 * - **The host must be on the allowlist** (`giftcoves.image_proxy.hosts`), a
 *   suffix match, checked when signing and again when serving: a picture from
 *   a host not on the list keeps its original URL. Defence in depth for the
 *   day APP_KEY leaks.
 * - **`https:` only**, and the fetch goes through {@see SafeFetch}: public
 *   addresses only, pinned DNS, a size cap and a time limit.
 * - **Never Amazon** (invariant 6). No Amazon picture is in the catalogue, and
 *   one that got there by mistake must not be copied, cached or served by us.
 *   Refused here even if a configured host list were to include it.
 */
class ImageProxy
{
    /** Whether the proxy hands out addresses at all. Off: every page uses the original URLs. */
    public function enabled(): bool
    {
        return (bool) config('giftcoves.image_proxy.enabled', false);
    }

    /**
     * The widths a page may ask for. A short list rather than any number, so
     * the disk holds at most five copies of a picture and nobody can fill it
     * by asking for every width from 1 to 5000.
     *
     * @return list<int>
     */
    public function widths(): array
    {
        return array_values(array_map('intval', (array) config('giftcoves.image_proxy.widths', [])));
    }

    public function allowsWidth(int $width): bool
    {
        return in_array($width, $this->widths(), true);
    }

    /**
     * The signed part of the address (`{signature}/{source}`), or null when this
     * picture keeps its original URL: the proxy is off, the URL is not https, its
     * host is not on the list, or it is Amazon's.
     *
     * The page builds the full address per width: `/img/{width}/{token}`.
     */
    public function token(?string $url): ?string
    {
        if ($url === null || ! $this->enabled() || ! $this->proxiable($url)) {
            return null;
        }

        return $this->signature($url).'/'.self::encode($url);
    }

    /** The source URL behind a token, or null when the signature does not match. */
    public function verify(string $signature, string $encoded): ?string
    {
        $url = self::decode($encoded);

        if ($url === null || ! hash_equals($this->signature($url), $signature)) {
            return null;
        }

        return $url;
    }

    /**
     * Whether a URL may go through the proxy at all: https, a host on the list,
     * and not Amazon. Asked when signing and again when serving.
     */
    public function proxiable(string $url): bool
    {
        if (strlen($url) > 2048) {
            return false;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));

        if ($host === '' || self::isAmazon($host)) {
            return false;
        }

        foreach ((array) config('giftcoves.image_proxy.hosts', []) as $allowed) {
            $allowed = strtolower(trim((string) $allowed));

            if ($allowed !== '' && ($host === $allowed || str_ends_with($host, '.'.$allowed))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Any host with "amazon" in one of its labels: amazon.nl, m.media-amazon.com,
     * images-eu.ssl-images-amazon.com. Broad on purpose; a false refusal costs
     * one picture its WebP copy, a false pass breaks invariant 6.
     */
    public static function isAmazon(string $host): bool
    {
        foreach (explode('.', strtolower($host)) as $label) {
            if (str_contains($label, 'amazon')) {
                return true;
            }
        }

        return false;
    }

    /** base64url without padding: safe in a path segment, no escaping needed. */
    public static function encode(string $url): string
    {
        return rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
    }

    public static function decode(string $encoded): ?string
    {
        if ($encoded === '' || preg_match('/^[A-Za-z0-9_-]+$/', $encoded) !== 1) {
            return null;
        }

        $url = base64_decode(strtr($encoded, '-_', '+/'), true);

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * 128 bits of HMAC-SHA256, hex. The key is derived from APP_KEY rather than
     * APP_KEY itself, so this signature can never be mistaken for, or replayed
     * as, anything else the framework signs with the same key.
     */
    private function signature(string $url): string
    {
        $key = hash_hmac('sha256', 'giftcoves-image-proxy-v1', (string) config('app.key'));

        return substr(hash_hmac('sha256', $url, $key), 0, 32);
    }
}

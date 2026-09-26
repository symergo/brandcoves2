<?php

declare(strict_types=1);

namespace App\Services\PageReading;

/**
 * A product name read from the link itself, for when the page cannot be.
 *
 * Big shops put the product's name in the address:
 * `debijenkorf.be/d/bialetti-moka-express-percolator-6-kops-8834090013-…`.
 * When their bot protection refuses us (it refuses about half of all requests
 * at de Bijenkorf, at random; 2026-09-26), that name is still better than the
 * shop's host. Pure and conservative: codes and ids are dropped, and anything
 * that does not read as words gives null.
 */
final class SlugTitle
{
    public static function fromUrl(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', trim($path, '/')), fn (string $s) => $s !== ''));

        // The longest segment is almost always the product's slug; the short
        // ones are "p", "d", "nl", "product".
        usort($segments, fn (string $a, string $b) => strlen($b) <=> strlen($a));
        $slug = urldecode(preg_replace('/\.(html?|php|aspx?)$/i', '', $segments[0] ?? '') ?? '');

        $words = preg_split('/[-_+\s]+/u', $slug) ?: [];

        // Drop ids and codes: long digit runs, and mixed codes with more
        // digits than letters (sku "8834090013", "wh1000xm5" stays).
        $words = array_values(array_filter($words, function (string $w): bool {
            if ($w === '') {
                return false;
            }

            $digits = preg_match_all('/\d/', $w);
            $letters = preg_match_all('/\p{L}/u', $w);

            return ! ($letters === 0 && $digits > 4) && ! ($digits > 6 && $digits > $letters);
        }));

        $title = trim(implode(' ', $words));
        $letters = preg_match_all('/\p{L}/u', $title);

        // Two words and some letters, or it is not a name.
        if (count($words) < 2 || $letters < 6 || mb_strlen($title) > 200) {
            return null;
        }

        return mb_strtoupper(mb_substr($title, 0, 1)).mb_substr($title, 1);
    }
}

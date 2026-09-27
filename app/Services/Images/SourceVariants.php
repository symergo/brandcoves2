<?php

declare(strict_types=1);

namespace App\Services\Images;

/**
 * A larger copy of the same picture, where the shop's server offers one.
 *
 * Feeds carry whatever size the shop chose for them, often a thumbnail. Some
 * image servers take the size as part of the URL, so for a 960-pixel slot the
 * proxy can ask for a 960-pixel source instead of blowing a 225-pixel one up.
 * Each rule below is a URL shape seen in our feeds; anything else is fetched
 * as it is. The original URL is always tried after a variant fails, so a rule
 * that stops matching a shop's server costs a request, never a picture.
 *
 * ## bol has no rule, and that was measured
 *
 * bol's picture URLs look sized (`media.s-bol.com/{id}/{hash}/250x200.jpg`),
 * but the second segment names that one rendition: on 2026-09-27, swapping the
 * size for 550x440, 1200x960, 500x400 or 124x99, with or without the hash,
 * answered 404 every time. A larger bol picture needs a different URL from
 * bol's API (the product's media endpoint), which is a connector change; see
 * docs/features/image-proxy.md, "Decisions for the owner".
 */
final class SourceVariants
{
    /** eBay's fixed renditions (`s-l{N}`); it answers only these sizes. */
    private const EBAY = [64, 140, 225, 300, 400, 500, 960, 1600];

    /**
     * The URLs to try for a slot `width` pixels wide, best first. The last one
     * is always the original.
     *
     * @return list<string>
     */
    public static function for(string $url, int $width): array
    {
        $larger = self::ebay($url, $width) ?? self::bynder($url, $width) ?? self::productserve($url, $width);

        return $larger !== null && $larger !== $url ? [$larger, $url] : [$url];
    }

    /** `i.ebayimg.com/images/g/{id}/s-l225.jpg`: the smallest rendition that covers the slot. */
    private static function ebay(string $url, int $width): ?string
    {
        if (preg_match('#^https://i\.ebayimg\.com/.+/s-l(\d+)\.(jpg|jpeg|png|webp)$#i', $url, $m) !== 1) {
            return null;
        }

        $current = (int) $m[1];
        $wanted = self::EBAY[array_key_last(self::EBAY)];

        foreach (self::EBAY as $size) {
            if ($size >= $width) {
                $wanted = $size;
                break;
            }
        }

        // Never ask for less than the feed already had.
        if ($wanted <= $current) {
            return null;
        }

        return (string) preg_replace('#/s-l\d+\.#', '/s-l'.$wanted.'.', $url);
    }

    /**
     * Bynder's transform URLs (Coolblue's pictures):
     * `...?io=transform:fit,height:800,width:800&format=png`. Only ever raised,
     * and to at most 1600, the proxy's own ceiling.
     */
    private static function bynder(string $url, int $width): ?string
    {
        if (preg_match('#^https://[a-z0-9.-]+\.bynder\.com/transform/#i', $url) !== 1
            || preg_match('/io=transform:fit,height:(\d+),width:(\d+)/', $url, $m) !== 1) {
            return null;
        }

        $target = min(1600, $width);

        if ((int) $m[2] >= $target) {
            return null;
        }

        return (string) preg_replace('/io=transform:fit,height:\d+,width:\d+/', "io=transform:fit,height:{$target},width:{$target}", $url);
    }

    /** Awin's image server: `images2.productserve.com/?w=200&h=200&...&url=...`. */
    private static function productserve(string $url, int $width): ?string
    {
        if (preg_match('#^https://images\d*\.productserve\.com/\?#i', $url) !== 1
            || preg_match('/[?&]w=(\d+)/', $url, $w) !== 1) {
            return null;
        }

        $target = min(1000, $width);

        if ((int) $w[1] >= $target) {
            return null;
        }

        $url = (string) preg_replace('/([?&])w=\d+/', '${1}w='.$target, $url);

        return (string) preg_replace('/([?&])h=\d+/', '${1}h='.$target, $url);
    }
}

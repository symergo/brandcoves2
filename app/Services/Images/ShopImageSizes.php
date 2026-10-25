<?php

declare(strict_types=1);

namespace App\Services\Images;

/**
 * The shop's own picture at each width the page offers, where its image server
 * takes the size in the URL. Since 2026-10-25 this replaces the image proxy
 * (docs/features/image-proxy.md, "Switched off").
 *
 * The proxy fetched, shrank and stored a WebP copy of every picture: a third
 * of the app's requests, 19.4 GB and 2.5 million files after a month, mostly
 * made for crawlers. Its real gain was small pictures on phones (a Coolblue
 * card went from 174 KB to 11 KB), and the three image servers that send big
 * pictures all take a size in the URL. Asking them for the size the slot
 * needs gets most of that gain for nothing: no fetch, no encoding, no disk.
 *
 * The rules are the URL shapes `SourceVariants` already knew, here in both
 * directions (smaller as well as larger). Any other host, bol included (its
 * size is fixed by a hash, and its thumbnails are already small), gets null
 * and the page uses the URL as it is. If a rule stops matching a shop's
 * server, the browser's error falls back to the original URL: a broken rule
 * costs a request, never a picture.
 */
final class ShopImageSizes
{
    /** The widths the page offers; the same as `IMAGE_WIDTHS` in `imageUrl.ts`. */
    public const WIDTHS = [160, 320, 480, 640, 960];

    /** eBay's fixed renditions (`s-l{N}`); it answers only these sizes. */
    private const EBAY = [64, 140, 225, 300, 400, 500, 960, 1600];

    /**
     * Width => URL, or null when this picture's server takes no size.
     *
     * @return array<int, string>|null
     */
    public static function for(?string $url): ?array
    {
        if ($url === null || ! str_starts_with($url, 'https://')) {
            return null;
        }

        $sized = [];

        foreach (self::WIDTHS as $width) {
            $resized = self::ebay($url, $width) ?? self::bynder($url, $width) ?? self::productserve($url, $width);

            if ($resized === null) {
                return null;
            }

            $sized[$width] = $resized;
        }

        return $sized;
    }

    /** `i.ebayimg.com/images/g/{id}/s-l225.jpg`: the smallest rendition that covers the slot. */
    private static function ebay(string $url, int $width): ?string
    {
        if (preg_match('#^https://i\.ebayimg\.com/.+/s-l\d+\.(jpg|jpeg|png|webp)$#i', $url) !== 1) {
            return null;
        }

        $wanted = self::EBAY[array_key_last(self::EBAY)];

        foreach (self::EBAY as $size) {
            if ($size >= $width) {
                $wanted = $size;
                break;
            }
        }

        return (string) preg_replace('#/s-l\d+\.#', '/s-l'.$wanted.'.', $url);
    }

    /**
     * Bynder's transform URLs (Coolblue's pictures):
     * `...?io=transform:fit,height:800,width:800&format=png`. A square box of
     * the slot width; `fit` keeps the proportions inside it.
     */
    private static function bynder(string $url, int $width): ?string
    {
        if (preg_match('#^https://[a-z0-9.-]+\.bynder\.com/transform/#i', $url) !== 1
            || preg_match('/io=transform:fit,height:\d+,width:\d+/', $url) !== 1) {
            return null;
        }

        return (string) preg_replace('/io=transform:fit,height:\d+,width:\d+/', "io=transform:fit,height:{$width},width:{$width}", $url);
    }

    /** Awin's image server: `images2.productserve.com/?w=200&h=200&...&url=...`, at most 1000. */
    private static function productserve(string $url, int $width): ?string
    {
        if (preg_match('#^https://images\d*\.productserve\.com/\?#i', $url) !== 1
            || preg_match('/[?&]w=\d+/', $url) !== 1) {
            return null;
        }

        $target = min(1000, $width);
        $url = (string) preg_replace('/([?&])w=\d+/', '${1}w='.$target, $url);

        return (string) preg_replace('/([?&])h=\d+/', '${1}h='.$target, $url);
    }
}

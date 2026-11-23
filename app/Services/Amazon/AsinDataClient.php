<?php

declare(strict_types=1);

namespace App\Services\Amazon;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * ASIN Data API: the ASIN of a barcode on one Amazon storefront.
 *
 * `GET https://api.asindataapi.com/request?type=product&amazon_domain=…&gtin=…`,
 * one credit. Behaviour measured on 2026-11-23 (the docs do not say all of it):
 *
 * - found: 200, `request_info.success` true, `product.asin` set;
 * - not on Amazon: 200, success true, no `product.asin`, and a message saying the
 *   GTIN "could not be found";
 * - a hiccup on their side: 500 "please retry. You have not been charged";
 * - a bad key 401, out of credits 402, too fast 429.
 *
 * Only the ASIN is read. The answer also carries the title, pictures, price and
 * availability, and none of it is kept or passed on (invariant 6).
 */
class AsinDataClient
{
    private const URL = 'https://api.asindataapi.com/request';

    public function configured(): bool
    {
        return trim((string) config('giftcoves.connectors.asindata.key')) !== '';
    }

    /**
     * @throws AsinDataUnavailable when the answer says nothing about the barcode:
     *                             a hiccup, a bad key, no credits. `stopForToday` is
     *                             true for the last three kinds, which will not get
     *                             better by asking the next barcode.
     */
    public function lookup(string $storefront, string $gtin): AsinLookup
    {
        try {
            $response = Http::timeout((int) config('giftcoves.connectors.asindata.timeout', 30))
                ->acceptJson()
                ->get(self::URL, [
                    'api_key' => (string) config('giftcoves.connectors.asindata.key'),
                    'type' => 'product',
                    'amazon_domain' => $storefront,
                    'gtin' => $gtin,
                ]);
        } catch (ConnectionException $e) {
            throw new AsinDataUnavailable('connection: '.$e->getMessage(), false);
        }

        if (in_array($response->status(), [401, 402, 429], true)) {
            throw new AsinDataUnavailable('HTTP '.$response->status(), true);
        }

        if (! $response->successful() || $response->json('request_info.success') !== true) {
            throw new AsinDataUnavailable('HTTP '.$response->status(), false);
        }

        $asin = $response->json('product.asin');

        return new AsinLookup(is_string($asin) && preg_match('/^[A-Z0-9]{10}$/', $asin) === 1 ? $asin : null);
    }

    /**
     * Amazon's own search for a term on one storefront: `type=search`, one credit.
     *
     * Measured on 2026-11-23 ("lego technic", amazon.com.be): 60 results, each with
     * `asin`, `title`, `image`, `link`, `sponsored`, and a price. The first ones are
     * sponsored placements, often not what was searched (robot kits for "lego
     * technic"), so those are left out. Only the ASIN, title and picture address are
     * returned; the price is never read (no price on our pages, owner 2026-11-23).
     *
     * @return list<array{asin: string, title: string, image: string|null}>
     *
     * @throws AsinDataUnavailable as lookup() does
     */
    public function search(string $storefront, string $term): array
    {
        try {
            $response = Http::timeout((int) config('giftcoves.connectors.asindata.timeout', 30))
                ->acceptJson()
                ->get(self::URL, [
                    'api_key' => (string) config('giftcoves.connectors.asindata.key'),
                    'type' => 'search',
                    'amazon_domain' => $storefront,
                    'search_term' => $term,
                ]);
        } catch (ConnectionException $e) {
            throw new AsinDataUnavailable('connection: '.$e->getMessage(), false);
        }

        if (in_array($response->status(), [401, 402, 429], true)) {
            throw new AsinDataUnavailable('HTTP '.$response->status(), true);
        }

        if (! $response->successful() || $response->json('request_info.success') !== true) {
            throw new AsinDataUnavailable('HTTP '.$response->status(), false);
        }

        $out = [];

        foreach ((array) $response->json('search_results', []) as $row) {
            $asin = $row['asin'] ?? null;
            $title = trim((string) ($row['title'] ?? ''));
            $image = $row['image'] ?? null;

            if (($row['sponsored'] ?? false) === true || ! is_string($asin) || preg_match('/^[A-Z0-9]{10}$/', $asin) !== 1 || $title === '') {
                continue;
            }

            $out[] = [
                'asin' => $asin,
                'title' => mb_substr($title, 0, 200),
                'image' => is_string($image) && str_starts_with($image, 'https://') ? $image : null,
            ];
        }

        return $out;
    }
}

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
}

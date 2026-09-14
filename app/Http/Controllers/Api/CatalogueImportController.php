<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\AmazonLocale;
use App\Enums\Market;
use App\Http\Controllers\Controller;
use App\Services\Ingestion\AmazonPageImport;
use App\Services\Ingestion\BolPageImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Put a shelf into the catalogue.
 *
 * The other write endpoints in this API arrange products that are already here.
 * This one is how they get here in the first place, from the place a person
 * actually finds them: a merchant's own site, in a browser, with the Chrome
 * extension in `extension/` reading the page.
 *
 * ## Why an import endpoint rather than a wider feed
 *
 * bol is a *live* source — queried per search, never crawled — so the stored
 * catalogue only ever contains the bol products somebody already searched for.
 * That is fine for shoppers and useless for an editor, who wants to choose from
 * a category page and needs those products to exist as rows before they can be
 * written about. Until now the only way to pull one in was to guess a search
 * term that would surface it.
 *
 * ## What the request may and may not assert
 *
 * Ids and titles, and nothing else. No price, no image, no link. Everything
 * stored is re-fetched from bol's API by {@see BolPageImport}, which means a
 * hostile or merely stale client can influence *which* products are imported
 * and can say nothing whatsoever about what they cost or where they link — and
 * the affiliate URL, the one field where a forged value would quietly redirect
 * a visitor and their money somewhere else, is built by the connector from
 * bol's own product URL.
 */
class CatalogueImportController extends Controller
{
    public function __construct(
        private readonly BolPageImport $import,
        private readonly AmazonPageImport $amazon,
    ) {}

    public function bol(Request $request): JsonResponse
    {
        $data = $request->validate([
            'market' => ['required', Rule::in(Market::values())],
            'products' => ['required', 'array', 'min:1', 'max:'.BolPageImport::MAX_PRODUCTS],
            // The number in a bol product URL. Long, numeric, and the only
            // thing every bol page carries for every product on it.
            'products.*.productId' => ['required', 'string', 'max:40', 'regex:/^\d+$/'],
            // Optional, and only ever a hint: a barcode saves a search but is
            // never trusted as a price, a title or a link.
            'products.*.ean' => ['nullable', 'string', 'max:20'],
            'products.*.title' => ['nullable', 'string', 'max:500'],
        ], [
            'products.*.productId.regex' => 'A bol product id is the number in the product URL.',
        ]);

        $market = Market::from($data['market']);

        /*
         * A market bol does not serve is a 422, not an empty result.
         *
         * `en` and `es` have no bol country — bol does not operate in Spain,
         * and the English market was deliberately cut off from it because bol
         * has no English catalogue and every title came back Dutch. An
         * extension pointed at the wrong market would otherwise export a full
         * page and be told, without explanation, that nothing was found.
         */
        if ($market->bolCountry() === null) {
            return response()->json([
                'message' => "bol does not serve the {$market->value} market.",
                'errors' => ['market' => ['bol is available for be-nl, be-fr and nl-nl.']],
            ], 422);
        }

        /** @var list<array{productId: string, ean?: string|null, title?: string|null}> $products */
        $products = $data['products'];

        return response()->json($this->import->import($market, $products));
    }

    /**
     * The same gesture on an Amazon page, landing somewhere else entirely.
     *
     * ## Why this endpoint accepts facts where the bol one refuses them
     *
     * The bol import takes ids and re-fetches everything, so nothing a page
     * claims can reach the database. Here there is no configured Amazon API to
     * re-fetch from, so the page is the source: the title, description, image
     * and barcode below are stored as the page stated them, and
     * `imported_from` records which page that was.
     *
     * That is a weaker guarantee, and it is bounded by where the rows go. They
     * go to `amazon_products`, not to `products` —
     * `Source::allowsCatalogueStorage()` is still false for Amazon — so none of
     * this reaches search, offer comparison, a wishlist, a chart or an email.
     *
     * **No price.** There is no field for one here and no column for one there.
     * Price and availability are what the Associates agreement binds to a
     * 24-hour refresh, and a page scraped today and read next month is exactly
     * the failure that rule exists to prevent. Requested that way by the owner
     * on 2026-09-14; see docs/features/amazon-compliance.md before widening it.
     */
    public function amazon(Request $request): JsonResponse
    {
        $data = $request->validate([
            // The storefront, by host. Amazon's catalogue, prices and even the
            // product's title differ per storefront, so which one this came
            // from is a fact about the data rather than a preference.
            'locale' => ['required', Rule::in(AmazonLocale::values())],
            'products' => ['required', 'array', 'min:1', 'max:'.AmazonPageImport::MAX_PRODUCTS],
            // Amazon's own identifier: ten characters, letters and digits.
            'products.*.asin' => ['required', 'string', 'regex:/^[A-Za-z0-9]{10}$/'],
            'products.*.title' => ['nullable', 'string', 'max:2000'],
            'products.*.description' => ['nullable', 'string', 'max:20000'],
            'products.*.imageUrl' => ['nullable', 'string', 'max:2000'],
            'products.*.category' => ['nullable', 'string', 'max:500'],
            'products.*.brand' => ['nullable', 'string', 'max:500'],
            // EAN, UPC or GTIN as printed in the product details table.
            'products.*.ean' => ['nullable', 'string', 'max:32'],
            'products.*.url' => ['nullable', 'string', 'max:2000'],
        ], [
            'products.*.asin.regex' => 'An ASIN is ten letters or digits, from the /dp/ part of the URL.',
        ]);

        /** @var list<array<string, mixed>> $products */
        $products = $data['products'];

        return response()->json(
            $this->amazon->import(AmazonLocale::from($data['locale']), $products)
        );
    }
}

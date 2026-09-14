<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Enums\AmazonLocale;
use App\Models\AmazonProduct;
use App\Models\ProductGroup;
use App\Services\Connectors\Offer;
use App\Services\Identity\IdentityResolver;
use Illuminate\Support\Carbon;

/**
 * A page of Amazon products, kept as what we know rather than as catalogue.
 *
 * ## Why this is not simply the bol importer with a different host
 *
 * Two differences, and both are structural rather than cosmetic.
 *
 * **There is nothing to re-fetch from.** The bol import treats the page as a
 * way of *choosing* and asks bol's API for every fact, which is what makes a
 * scraped price harmless — it is thrown away. No Amazon API is configured here,
 * so the page is the only source there is. Every field below is therefore
 * stated by a web page and stored as stated, and `imported_from` records which
 * page said it.
 *
 * **Amazon does not go in the catalogue.** `Source::allowsCatalogueStorage()`
 * is false and stays false, so nothing written here reaches `products`, search,
 * offer comparison, a wishlist, a chart, or an email. It lands in
 * `amazon_products`, the decision store that exists for exactly this — off to
 * one side of the catalogue, addressable by ASIN.
 *
 * **No price is stored, by instruction and by design.** There is no column for
 * one. Price and availability are the fields the Associates agreement binds to
 * a 24-hour refresh, and they are the ones a person would actually be misled
 * by. See `docs/features/amazon-compliance.md`.
 *
 * ## What this buys
 *
 * `amazon_products.identity_key` has been the documented bridge from an ASIN to
 * the product group other shops' offers hang off since the table was created,
 * and nothing has ever been able to fill it, because Amazon publishes no
 * barcodes through any interface this site had. A product page prints one. Give
 * this a page with an EAN on it and a pasted Amazon link starts resolving to a
 * real GiftCoves product page.
 */
class AmazonPageImport
{
    /** A search results page shows about sixty. The rest is a guard, not a target. */
    public const MAX_PRODUCTS = 60;

    /**
     * @param  list<array{asin: string, title?: string|null, description?: string|null, imageUrl?: string|null, category?: string|null, brand?: string|null, ean?: string|null, url?: string|null}>  $candidates
     * @return array{locale: string, requested: int, imported: int, results: list<array<string, mixed>>}
     */
    public function import(AmazonLocale $locale, array $candidates): array
    {
        $candidates = $this->deduplicate($candidates);
        $results = [];

        foreach ($candidates as $candidate) {
            $results[] = $this->store($locale, $candidate);
        }

        return [
            'locale' => $locale->value,
            'requested' => count($candidates),
            'imported' => count(array_filter($results, fn (array $r) => $r['status'] !== 'skipped')),
            'results' => $results,
        ];
    }

    /** @param array<string, mixed> $candidate */
    private function store(AmazonLocale $locale, array $candidate): array
    {
        $asin = strtoupper(trim((string) $candidate['asin']));
        $title = $this->text($candidate['title'] ?? null, 500);

        if ($title === null) {
            /*
             * A title is the one field that cannot be filled in later.
             *
             * It is the input to the giftability rules this table exists to
             * record, and with no API to ask, an ASIN with no name is a row
             * nothing can ever be decided about. Reported rather than stored.
             */
            return ['asin' => $asin, 'status' => 'skipped', 'reason' => 'no title on the page'];
        }

        $ean = $this->normaliseEan($candidate['ean'] ?? null);
        $brand = $this->text($candidate['brand'] ?? null, 160);

        /*
         * The identity, resolved exactly as an ingested offer's would be.
         *
         * Same service, same rules — a second implementation here would be a
         * second opinion about when two rows are the same physical product,
         * and this one feeds a bridge that sends visitors to a product page.
         * Sending them to the wrong one is worse than sending them nowhere.
         */
        $identity = IdentityResolver::resolve($ean, $brand, $title);

        $existing = AmazonProduct::query()->where('asin', $asin)->first();

        // Every storefront this ASIN has been seen on, as a set. A hint for the
        // locale selector, never a fact — see the column's own comment.
        $seen = array_values(array_unique([
            ...(array) ($existing?->seen_in_locales ?? []),
            $locale->value,
        ]));

        $attributes = [
            'classified_title' => $title,
            'classified_locale' => $locale->value,
            'brand' => $brand,
            'category' => $this->text($candidate['category'] ?? null, 160),
            'description' => $this->text($candidate['description'] ?? null, 4000),
            'image_url' => $this->url($candidate['imageUrl'] ?? null),
            'ean' => $ean,
            'identity_key' => $identity?->key,
            'seen_in_locales' => $seen,
            'imported_from' => $this->url($candidate['url'] ?? null),
            'imported_at' => Carbon::now(),
        ];

        /*
         * A re-import must not blank what an earlier, richer page said.
         *
         * A search results page carries a title and an image and no barcode; the
         * product page for the same ASIN carries all three. Importing the shelf
         * after the product would otherwise erase the barcode — the most
         * valuable field on the row and the hardest to get back.
         */
        if ($existing !== null) {
            $attributes = array_filter(
                $attributes,
                fn ($value, $key) => $value !== null || $existing->{$key} === null,
                ARRAY_FILTER_USE_BOTH,
            );
        }

        $product = AmazonProduct::query()->updateOrCreate(['asin' => $asin], $attributes);

        return [
            'asin' => $asin,
            'title' => $product->classified_title,
            'status' => $existing === null ? 'imported' : 'updated',
            'ean' => $product->ean,
            // Whether this ASIN now points at a product GiftCoves already sells.
            // The whole reason the barcode is worth scraping.
            'matchedGroupId' => $this->matchedGroupId($product),
        ];
    }

    /**
     * The product group this ASIN turns out to be, if we already stock it.
     *
     * Unscoped by market on purpose, unlike every render-time lookup: this is
     * telling a curator "we know this product", not sending a shopper to a
     * price. `SearchController::productFor()` does the market-scoped version
     * when it matters.
     */
    private function matchedGroupId(AmazonProduct $product): ?int
    {
        if ($product->identity_key === null) {
            return null;
        }

        return ProductGroup::query()
            ->where('identity_key', $product->identity_key)
            ->value('id');
    }

    private function text(?string $raw, int $limit): ?string
    {
        // Collapse whitespace: a scraped description arrives full of the
        // newlines and indentation of the markup it was pulled out of.
        $value = trim(preg_replace('/\s+/u', ' ', (string) $raw) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    /**
     * Only an https URL, and only for the image.
     *
     * The same rule the affiliate URL gets in {@see Offer}
     * and for the same reason: this string came off somebody else's page, and
     * escaping at render does not stop a `javascript:` or `data:` URL being
     * exactly what it says it is.
     */
    private function url(?string $raw): ?string
    {
        $value = trim((string) $raw);

        if ($value === '') {
            return null;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);

        return is_string($scheme) && strtolower($scheme) === 'https' ? mb_substr($value, 0, 2000) : null;
    }

    /** 13 digits or nothing — a UPC is widened to an EAN by the resolver, not here. */
    private function normaliseEan(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw) ?? '';

        // A 12-digit UPC is an EAN with a leading zero. Amazon prints both, and
        // treating them as different numbers would put the same product in the
        // catalogue twice.
        if (strlen($digits) === 12) {
            $digits = '0'.$digits;
        }

        return strlen($digits) === 13 ? $digits : null;
    }

    /**
     * One entry per ASIN, first spelling wins.
     *
     * An Amazon results page links each product from its image, its heading and
     * its review count, and a product page repeats its own ASIN throughout.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    private function deduplicate(array $candidates): array
    {
        $byAsin = [];

        foreach ($candidates as $candidate) {
            $asin = strtoupper(trim((string) ($candidate['asin'] ?? '')));

            if ($asin === '' || isset($byAsin[$asin])) {
                continue;
            }

            $candidate['asin'] = $asin;
            $byAsin[$asin] = $candidate;
        }

        return array_values(array_slice($byAsin, 0, self::MAX_PRODUCTS));
    }
}

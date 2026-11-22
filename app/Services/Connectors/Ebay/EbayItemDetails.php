<?php

declare(strict_types=1);

namespace App\Services\Connectors\Ebay;

use App\Enums\Source;
use App\Services\Connectors\Offer;
use App\Services\Identity\Gtin;
use Illuminate\Support\Facades\DB;

/**
 * Brand, barcode and part number for eBay listings, from eBay's item detail (2026-11-22).
 *
 * eBay's search results carry none of the three, and an offer with neither a barcode nor a
 * brand gets no product identity, so it never becomes a product and search never shows it:
 * on 2026-11-22 Spain had 520 eBay offers and 0 products, and a search for "lego" found
 * nothing. The detail endpoint has them ("LEGO", part number 40918), and it says when a
 * listing has no brand at all, which is how a "compatible with LEGO" knock-off stays out of
 * LEGO. Reading the brand off the start of the title was the alternative, and it cannot
 * tell the two apart.
 *
 * One request per listing (the 20-at-once endpoint answered 403 for these keys), against
 * eBay's 5,000 Browse requests a day, shared with search. So each listing is asked once,
 * in the background (FetchEbayItemDetails), never while a visitor waits, at most
 * `connectors.ebay.details_per_day` a day, and the answer is kept in `ebay_item_details`.
 * A listing found again in a later search takes its details from there (apply()), or the
 * upsert, which writes what changed, would wipe the brand with the search result's empty one.
 */
final class EbayItemDetails
{
    /**
     * What sellers put in the brand field when there is none, in the languages of the
     * marketplaces we ask (es, fr, nl, en, and the it/de some listings are written in).
     * Matched on the whole value, lower case, without punctuation.
     */
    private const NO_BRAND = '/^(unbranded|no brand|generic|generico|genérico|sin marca|sans marque|generique|générique|sans marque generique|sans marque générique|merkloos|geen merk|markenlos|ohne marke|senza marca|does not apply|no aplica|non applicable|n a|na)$/u';

    /** Part numbers and barcodes that say "none". */
    private const NONE = '/^(does not apply|no aplica|non applicable|ne s applique pas|niet van toepassing|nicht zutreffend|non applicabile|n a|na|none|ninguno|aucun|-)$/u';

    /** How many details may still be fetched today. */
    public function remainingToday(): int
    {
        $cap = (int) config('giftcoves.connectors.ebay.details_per_day', 2000);
        $used = DB::table('ebay_item_details')->where('fetched_at', '>=', now()->startOfDay())->count();

        return max(0, $cap - $used);
    }

    /**
     * The ids among these that were never asked.
     *
     * @param  list<string>  $externalIds
     * @return list<string>
     */
    public function unknown(array $externalIds): array
    {
        $externalIds = array_values(array_unique($externalIds));
        $known = $externalIds === [] ? [] : DB::table('ebay_item_details')->whereIn('external_id', $externalIds)->pluck('external_id')->all();

        return array_values(array_diff($externalIds, $known));
    }

    /**
     * eBay offers with what their detail said filled in where they have nothing.
     *
     * @param  list<Offer>  $offers
     * @return list<Offer>
     */
    public function apply(array $offers): array
    {
        $ids = [];
        foreach ($offers as $offer) {
            if ($offer->source === Source::Ebay) {
                $ids[] = $offer->externalId;
            }
        }

        if ($ids === []) {
            return $offers;
        }

        $known = DB::table('ebay_item_details')->whereIn('external_id', array_values(array_unique($ids)))->get()->keyBy('external_id');

        return array_map(function (Offer $offer) use ($known): Offer {
            $row = $offer->source === Source::Ebay ? $known->get($offer->externalId) : null;

            return $row === null ? $offer : $offer->withDetails($row->brand, $row->gtin, $row->mpn);
        }, $offers);
    }

    /**
     * Brand, barcode and part number from one item detail response.
     *
     * @param  array<string, mixed>  $item
     * @return array{brand: ?string, gtin: ?string, mpn: ?string}
     */
    public static function parse(array $item): array
    {
        $brand = self::clean($item['brand'] ?? null);
        $mpn = self::clean($item['mpn'] ?? null);

        // Some listings carry them only among the item specifics
        foreach ((array) ($item['localizedAspects'] ?? []) as $aspect) {
            $name = mb_strtolower(trim((string) ($aspect['name'] ?? '')));
            $value = $aspect['value'] ?? null;

            if ($brand === null && in_array($name, ['brand', 'marca', 'marque', 'merk', 'marke'], true)) {
                $brand = self::clean($value);
            }

            if ($mpn === null && in_array($name, ['mpn', 'numéro de pièce fabricant', 'número de pieza del fabricante', 'herstellernummer', 'fabrikantnummer', 'manufacturer part number'], true)) {
                $mpn = self::clean($value);
            }
        }

        if ($brand !== null && preg_match(self::NO_BRAND, self::fold($brand)) === 1) {
            $brand = null;
        }

        if ($mpn !== null && preg_match(self::NONE, self::fold($mpn)) === 1) {
            $mpn = null;
        }

        $gtin = null;
        foreach (['gtin', 'ean', 'upc'] as $field) {
            $value = $item[$field] ?? null;
            $value = is_array($value) ? ($value[0] ?? null) : $value;
            $gtin = Gtin::normalise(is_scalar($value) ? (string) $value : null);

            if ($gtin !== null) {
                break;
            }
        }

        return [
            'brand' => $brand === null ? null : mb_substr($brand, 0, 255),
            'gtin' => $gtin,
            'mpn' => $mpn === null ? null : mb_substr($mpn, 0, 255),
        ];
    }

    /** @param  array{brand: ?string, gtin: ?string, mpn: ?string}  $details */
    public function remember(string $externalId, array $details): void
    {
        DB::table('ebay_item_details')->upsert(
            [['external_id' => $externalId, 'brand' => $details['brand'], 'gtin' => $details['gtin'], 'mpn' => $details['mpn'], 'fetched_at' => now()]],
            ['external_id'],
            ['brand', 'gtin', 'mpn', 'fetched_at'],
        );
    }

    private static function clean(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }

    /** Lower case, punctuation to spaces, single spaces: "- Sans marque/Générique -" => "sans marque générique". */
    private static function fold(string $value): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($value)));
    }
}

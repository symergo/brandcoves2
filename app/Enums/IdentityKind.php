<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a product group's members were decided. Stored so a bad merge can be
 * audited and so the two paths can be measured against each other.
 */
enum IdentityKind: string
{
    /** Validated GTIN-13. Authoritative. */
    case Ean = 'ean';

    /**
     * Brand + normalised title. The fallback, needed because a large share of
     * feed rows carry no EAN at all — without it those products could never be
     * compared across merchants.
     */
    case Title = 'title';

    /**
     * One marketplace listing, a product of its own (2026-11-22). For an eBay offer with neither a
     * barcode nor a brand, so it shows in search straight after the live search; merged into the
     * real product once its item detail gives an identity (FetchEbayItemDetails). Never shared by
     * two offers, so it never compares prices.
     */
    case Listing = 'listing';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $k) => $k->value, self::cases());
    }
}

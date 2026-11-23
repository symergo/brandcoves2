<?php

declare(strict_types=1);

namespace App\Services\Amazon;

/** What ASIN Data API said about one barcode: its ASIN, or null for "not on this storefront". */
final readonly class AsinLookup
{
    public function __construct(public ?string $asin) {}
}

<?php

declare(strict_types=1);

namespace App\Services\PageReading;

/**
 * What a shop's page says about the product on it.
 *
 * Stated, not verified: every field is the shop's own claim, read once. That is
 * why none of it is written into the catalogue's offers (see
 * docs/features/pasted-links.md, "What a read does not do").
 */
final readonly class PageProduct
{
    public function __construct(
        public string $title,
        public ?string $brand = null,
        public ?string $description = null,
        public ?string $imageUrl = null,
        /** Cents (invariant 7). Null when the page gave none we could read. */
        public ?int $price = null,
        public ?string $currency = null,
        /** `in_stock`, `out_of_stock`, or null when the page does not say. */
        public ?string $availability = null,
        /** A validated GTIN-13, or null. */
        public ?string $gtin = null,
        public ?string $mpn = null,
    ) {}

    /** @return array<string, string|int|null> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            title: (string) $data['title'],
            brand: $data['brand'] ?? null,
            description: $data['description'] ?? null,
            imageUrl: $data['imageUrl'] ?? null,
            price: isset($data['price']) ? (int) $data['price'] : null,
            currency: $data['currency'] ?? null,
            availability: $data['availability'] ?? null,
            gtin: $data['gtin'] ?? null,
            mpn: $data['mpn'] ?? null,
        );
    }
}

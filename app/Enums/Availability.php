<?php

declare(strict_types=1);

namespace App\Enums;

enum Availability: string
{
    case InStock = 'in_stock';
    case OutOfStock = 'out_of_stock';
    case Preorder = 'preorder';
    case Unknown = 'unknown';

    public function isBuyable(): bool
    {
        return $this === self::InStock || $this === self::Preorder;
    }

    /**
     * Feeds express stock a dozen different ways. Anything unrecognised becomes
     * Unknown rather than being optimistically read as in-stock — showing a
     * sold-out product as available is the worse failure.
     */
    public static function fromFeedValue(?string $raw): self
    {
        $value = strtolower(trim((string) $raw));

        return match (true) {
            $value === '' => self::Unknown,
            in_array($value, ['1', 'y', 'yes', 'true', 'in stock', 'in_stock', 'instock', 'op voorraad', 'en stock'], true) => self::InStock,
            in_array($value, ['0', 'n', 'no', 'false', 'out of stock', 'out_of_stock', 'outofstock', 'niet op voorraad', 'rupture de stock'], true) => self::OutOfStock,
            str_contains($value, 'preorder'), str_contains($value, 'pre-order'), str_contains($value, 'voorbestel') => self::Preorder,
            str_contains($value, 'out') => self::OutOfStock,
            str_contains($value, 'stock') || str_contains($value, 'voorraad') => self::InStock,
            default => self::Unknown,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $a) => $a->value, self::cases());
    }
}

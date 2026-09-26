<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which rule proposed that two products are the same one.
 *
 * Stored on every candidate so each rule's precision (merged ÷ decided) can be
 * measured on its own. That number is what would let a rule merge without a
 * person one day; see docs/features/match-review.md.
 */
enum MatchRule: string
{
    /** Offers in two products carry the same barcode. */
    case Barcode = 'barcode';

    /** Same brand and the same model number (`42125`, `WH-1000XM5`). */
    case Model = 'model';

    /** Same brand and titles that are nearly the same (trigram similarity). */
    case Title = 'title';

    /**
     * A decision a person made outside the review queue: a split, or a
     * rejection carried over to the product a merge kept. Never counted in a
     * rule's precision, because no rule proposed it.
     */
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Barcode => 'Same barcode',
            self::Model => 'Same model number',
            self::Title => 'Similar title',
            self::Manual => 'Decided by hand',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $r) => $r->value, self::cases());
    }
}

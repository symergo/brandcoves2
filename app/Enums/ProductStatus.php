<?php

declare(strict_types=1);

namespace App\Enums;

enum ProductStatus: string
{
    /** Live in the catalogue, returned by search. */
    case Active = 'active';

    /**
     * Fell out of a feed but is kept because a wishlist item or a published
     * guide still points at it. Deleting these would break those pages.
     */
    case Stale = 'stale';

    /** Deliberately suppressed — bad data, hazmat, or an admin decision. */
    case Excluded = 'excluded';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}

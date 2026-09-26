<?php

declare(strict_types=1);

namespace App\Enums;

/** Where a proposed match stands. */
enum MatchStatus: string
{
    /** Waiting in the review queue. */
    case Pending = 'pending';

    /** A person said "the same product" and the two were merged. */
    case Merged = 'merged';

    /** A person said "not the same". Kept so the pair is never proposed again. */
    case Rejected = 'rejected';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}

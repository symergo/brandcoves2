<?php

declare(strict_types=1);

namespace App\Enums;

enum SantaStatus: string
{
    /** Members may still join. Nothing has been assigned.  */
    case Open = 'open';

    /** Assignments exist. Joining now would leave someone without a giver. */
    case Drawn = 'drawn';

    public function isDrawn(): bool
    {
        return $this === self::Drawn;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}

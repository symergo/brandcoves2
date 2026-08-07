<?php

declare(strict_types=1);

namespace App\Enums;

enum AlertState: string
{
    case Active = 'active';
    case Triggered = 'triggered';
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A thumb on one of Find a gift's ideas: up keeps it and says "more like
 * this", down replaces it and says "not this". Stored as a string with a CHECK
 * in `recipient_feedback.vote` and `gift_votes.vote`. See
 * docs/features/find-a-gift.md, "Thumbs up, thumbs down".
 */
enum Thumb: string
{
    case Up = 'up';
    case Down = 'down';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}

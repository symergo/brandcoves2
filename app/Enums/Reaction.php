<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The reaction on a daily pick. Fun in itself, but it is also the learning
 * signal: over weeks it teaches the engine what *this* audience finds
 * surprising, which is subjective and drifts.
 */
enum Reaction: string
{
    case Mindblown = 'mindblown';
    case Meh = 'meh';

    public function emoji(): string
    {
        return match ($this) {
            self::Mindblown => '🤯',
            self::Meh => '😐',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $r) => $r->value, self::cases());
    }
}

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

    /*
     * Thumbs, not the exploding head and the flat face they started as: the
     * reaction is read at a glance beside a price, and "is this any good"
     * carries there where "did this astonish you" did not.
     *
     * The case names and the `mindblown_count` / `meh_count` columns keep the
     * old vocabulary on purpose. Renaming them is a data migration across
     * `pick_reactions` and two counters for a change nobody can see, and the
     * enum is the one place the two spellings have to meet.
     */
    public function emoji(): string
    {
        return match ($this) {
            self::Mindblown => '👍',
            self::Meh => '👎',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $r) => $r->value, self::cases());
    }
}

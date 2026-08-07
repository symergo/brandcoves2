<?php

declare(strict_types=1);

namespace App\Services\Cove;

/**
 * How close one guess was.
 *
 * Bands rather than a raw distance, for two reasons. A number ("you were €43
 * out") turns the game into arithmetic and lets a player binary-search their way
 * to the answer in three moves, which is not a game. And the band is what gets
 * shared — an emoji per guess, which says how the round went without spoiling
 * what the answer was.
 */
enum GuessBand: string
{
    case Exact = 'exact';
    case Warm = 'warm';
    case Cool = 'cool';
    case Cold = 'cold';

    /** True when the guess counts as solving it. */
    public function solves(): bool
    {
        return $this === self::Exact || $this === self::Warm;
    }

    /**
     * The share grid character.
     *
     * Deliberately readable without colour — a row of squares has to survive
     * being pasted into a plain-text message, and roughly 8% of men cannot tell
     * the red one from the green one.
     */
    public function emoji(bool $over): string
    {
        return match ($this) {
            self::Exact => '🎯',
            self::Warm => '🟩',
            self::Cool => $over ? '🔽' : '🔼',
            self::Cold => $over ? '⬇️' : '⬆️',
        };
    }

    /**
     * Classify a guess against the answer.
     *
     * Proportional, not absolute: being €20 out on a €40 kettle is a wild miss
     * and being €20 out on a €900 machine is a bullseye. The thresholds are
     * ratios for that reason.
     */
    public static function classify(int $guessCents, int $answerCents): self
    {
        if ($answerCents <= 0) {
            return self::Cold;
        }

        $ratio = abs($guessCents - $answerCents) / $answerCents;

        return match (true) {
            // Within 5%: for a €80 product that is €4, which is closer than most
            // people can guess and deserves to read as a win.
            $ratio <= 0.05 => self::Exact,
            $ratio <= 0.15 => self::Warm,
            $ratio <= 0.40 => self::Cool,
            default => self::Cold,
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A rough price for an idea nobody sells here, set by the person who
 * approves it.
 *
 * Rough on purpose: "a spa day" costs anything from 40 to 400 euros, so a
 * price would be a guess dressed up as a fact. Three bands are enough to keep
 * an expensive idea away from a brief with a small budget, which is the only
 * thing the band is used for.
 */
enum IdeaPriceBand: string
{
    /** Under 25 euros. */
    case Low = 'low';

    /** 25 to 75 euros. */
    case Mid = 'mid';

    /** Over 75 euros. */
    case High = 'high';

    /** The cheapest this band can be, in cents. */
    public function floor(): int
    {
        return match ($this) {
            self::Low => 0,
            self::Mid => 2500,
            self::High => 7500,
        };
    }

    /** The dearest this band can be, in cents; null for no ceiling. */
    public function ceiling(): ?int
    {
        return match ($this) {
            self::Low => 2500,
            self::Mid => 7500,
            self::High => null,
        };
    }

    /**
     * Does a brief's budget leave room for this band?
     *
     * Overlap, not containment: a brief of 20 to 40 euros can take a Mid idea,
     * because a Mid idea can be had for 30.
     */
    public function fits(?int $budgetMin, ?int $budgetMax): bool
    {
        if ($budgetMax !== null && $this->floor() > $budgetMax) {
            return false;
        }

        $ceiling = $this->ceiling();

        return ! ($budgetMin !== null && $ceiling !== null && $ceiling < $budgetMin);
    }

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Under 25 euros',
            self::Mid => '25 to 75 euros',
            self::High => 'Over 75 euros',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $b) => $b->value, self::cases());
    }
}

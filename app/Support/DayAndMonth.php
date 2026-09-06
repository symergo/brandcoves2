<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * A birthday without a year.
 *
 * What anybody needs from somebody else's birthday is when to buy them
 * something, which is a day and a month. The year is their age, and it belongs
 * to them: `users.birthday` holds a full date because a person may give one
 * about themselves, and everything second-hand — the date you wrote down about
 * a friend — is a day and a month and nothing else.
 *
 * On the wire it is `MM-DD`, one string rather than two numbers, so the page
 * has one thing to render and one thing to check for null. The client formats
 * it against a leap year, which is why 29 February survives the round trip.
 */
final readonly class DayAndMonth
{
    public function __construct(public int $day, public int $month) {}

    /** From the two columns, or null when neither is set. */
    public static function fromColumns(?int $day, ?int $month): ?self
    {
        return $day === null || $month === null ? null : new self($day, $month);
    }

    /** From a full date, dropping the year — the one direction that is lossy. */
    public static function fromDate(?CarbonInterface $date): ?self
    {
        return $date === null ? null : new self($date->day, $date->month);
    }

    /**
     * From `MM-DD` as the form sends it, or null for anything that is not that.
     *
     * Deliberately forgiving about what it rejects rather than what it accepts:
     * the calendar bounds are the database's CHECK, and this is the parse.
     */
    public static function fromString(?string $value): ?self
    {
        if ($value === null || preg_match('/^(\d{2})-(\d{2})$/', trim($value), $m) !== 1) {
            return null;
        }

        $month = (int) $m[1];
        $day = (int) $m[2];

        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }

        return new self($day, $month);
    }

    public function toString(): string
    {
        return sprintf('%02d-%02d', $this->month, $this->day);
    }
}

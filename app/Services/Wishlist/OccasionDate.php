<?php

declare(strict_types=1);

namespace App\Services\Wishlist;

use App\Enums\EventType;
use App\Enums\Market;
use App\Support\DayAndMonth;
use Carbon\CarbonImmutable;

/**
 * When does this occasion fall next?
 *
 * Most of the time nobody should be asked. A birthday is *the person's
 * birthday*, and Christmas has not moved lately, so a date field beside either
 * is asking somebody to look up something already on the screen.
 *
 * Two sources, and a deliberately short list of occasions they can answer:
 *
 * 1. **The person**, for a birthday. Nothing else here can answer it.
 * 2. **The calendar**, for the days that are a number rather than a custom.
 *
 * Everything else is null on purpose, and null means the wizard asks.
 *
 * ## Why Mother's Day and Father's Day are not here
 *
 * They move, and not only between countries. Father's Day is the second Sunday
 * of June in Flanders and the second Sunday of March in Wallonia; Mother's Day
 * is 15 August in Antwerp and the second Sunday of May in the rest of Belgium.
 * A market is not a region, so any single date this could return would be
 * confidently wrong for a chunk of the people reading it, on a day they care
 * about. Wrong beats absent here only if you never meet the people it was wrong
 * for. The site's editorial calendar still keeps a market-level date for
 * *stocking a themed Cove*, which is a different job with a different cost when
 * it is a week out.
 *
 * Always the *next* one, today included: a list made on 25 December is for
 * today's Christmas, one made on the 26th is for next year's.
 */
final readonly class OccasionDate
{
    /**
     * The occasions that are a date rather than a custom, as month-day.
     *
     * Christmas Day and Valentine's are the same square on the calendar in
     * every market this site serves. *When people exchange presents* is another
     * matter, which is why the wizard offers to change the date it fills in:
     * plenty of families here do it on the evening of the 24th.
     */
    private const FIXED = [
        EventType::Christmas->value => '12-25',
        EventType::Valentines->value => '02-14',
    ];

    /**
     * The date to put on a list, or null when only its owner knows.
     *
     * @param  DayAndMonth|null  $birthday  whose birthday it is, for `Birthday`
     * @param  CarbonImmutable|null  $from  today, unless a test says otherwise
     */
    public function for(
        EventType $type,
        Market $market,
        ?DayAndMonth $birthday = null,
        ?CarbonImmutable $from = null,
    ): ?CarbonImmutable {
        $from = ($from ?? CarbonImmutable::today())->startOfDay();

        if ($type === EventType::Birthday) {
            return $birthday === null
                ? null
                : $this->next($birthday->day, $birthday->month, $from);
        }

        if (! isset(self::FIXED[$type->value])) {
            return null;
        }

        [$month, $day] = array_map('intval', explode('-', self::FIXED[$type->value]));

        return $this->next($day, $month, $from);
    }

    /**
     * The next time this day and month comes round, today counting as next.
     *
     * 29 February lands on the 28th in a common year. The alternative is
     * 1 March, which is a different month and reads as the wrong day; the
     * placeholder year a birthday is stored under is a leap year for exactly
     * this reason, so the pair itself is never lost.
     */
    private function next(int $day, int $month, CarbonImmutable $from): ?CarbonImmutable
    {
        $date = $this->on($day, $month, $from->year);

        return $date !== null && $date->greaterThanOrEqualTo($from)
            ? $date
            : $this->on($day, $month, $from->year + 1);
    }

    private function on(int $day, int $month, int $year): ?CarbonImmutable
    {
        if ($month === 2 && $day === 29 && ! checkdate(2, 29, $year)) {
            $day = 28;
        }

        return checkdate($month, $day, $year)
            ? CarbonImmutable::create($year, $month, $day)?->startOfDay()
            : null;
    }
}

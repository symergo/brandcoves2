<?php

declare(strict_types=1);

namespace App\Services\Cove;

use App\Enums\Market;
use Illuminate\Support\Facades\Cache;

/**
 * The short caches that list a market's newest Coves, and the one place they
 * are forgotten.
 *
 * `/coves`, the Discover page and the "more Coves" band beside every Cove each
 * cache their lists for ten to thirty minutes (see each for why). That is fine
 * for everything except the moment a Cove is published: the Daily Cove is
 * released at a set time, and a list still showing yesterday's for half an
 * hour after that would be a visible miss. So publishing forgets them:
 * `BuildDailyEdition`, `BuildCove` (which `PublishDueCoves` dispatches) and
 * `RedoCove` call `forgetMarket()` after a build, and every one of these caches
 * ends at the next Daily release at the latest (`ttl()`), because the Daily is
 * built hours before it is released.
 *
 * Deliberately not a general "forget whatever depends on this" scheme (the
 * owner decided against one, 2026-09-27). These keys are named here so the
 * forget and the caches cannot drift apart; everything else simply expires.
 *
 * Not forgotten: the home page's shelf of other Coves (`home.coves:{market}`).
 * It leaves the Dailies out and is a random draw held for an hour on purpose,
 * so that a visitor who reloads sees the same shelf; today's edition on the
 * home page is read uncached.
 */
final class CoveCaches
{
    /** The rail bands, as `CoveRail::sectionOf()` names them. */
    private const RAIL_SECTIONS = ['daily', 'gift', 'smart', 'shop'];

    /** `/coves`: every section of the overview. */
    public static function covesKey(Market $market): string
    {
        return 'bc:coves:'.$market->value;
    }

    /** The Discover page's Cove bands (today, the days before, personas, guides). */
    public static function discoverKey(Market $market): string
    {
        return 'bc:discover:'.$market->value;
    }

    /** The newest Coves of one rail band. */
    public static function railKey(Market $market, string $section): string
    {
        return 'bc:cove-rail:coves:'.$market->value.':'.$section;
    }

    /**
     * A cache lifetime that never runs past the next Daily release.
     *
     * The forget above cannot cover the Daily on its own: `BuildDailyEdition`
     * builds at 06:00 and the edition only counts as published from its
     * `published_at`, the drop time (`giftcoves.picks.drop_time`, 09:00). A
     * list cached at 08:55 would otherwise carry yesterday's edition until
     * 09:25. Ending every Cove-list cache at the drop time is what makes the
     * edition appear at its release, with no job running at that minute.
     */
    public static function ttl(int $seconds): int
    {
        $drop = now()->setTimeFromTimeString((string) config('giftcoves.picks.drop_time', '09:00'));

        if ($drop->lessThanOrEqualTo(now())) {
            $drop = $drop->addDay();
        }

        return max(1, min($seconds, (int) ceil(now()->diffInSeconds($drop, true))));
    }

    /** Forget every list of this market's Coves, so a new one shows at once. */
    public static function forgetMarket(Market $market): void
    {
        Cache::forget(self::covesKey($market));
        Cache::forget(self::discoverKey($market));

        foreach (self::RAIL_SECTIONS as $section) {
            Cache::forget(self::railKey($market, $section));
        }
    }
}

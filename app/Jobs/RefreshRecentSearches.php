<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Market;
use App\Services\Search\RecentSearches;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;

/**
 * Resolve the recent searches into pictures, once an hour, per market.
 *
 * Queued rather than run by the scheduler inline: it performs a handful of real
 * searches, and the scheduler container's job is to dispatch rather than to
 * work — one slow task there delays every other schedule behind it.
 *
 * Safe to run at any time and safe to run twice. It only writes a cache key, so
 * a failure leaves the previous hour's band in place rather than an empty one.
 */
#[Queue('batch')]
class RefreshRecentSearches implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    // Hourly, and a handful of searches: five minutes is a hung search, not
    // a slow one. Without it the job took the `batch` default of an hour.
    public int $timeout = 300;

    /**
     * One run per market at a time, however often it is queued (2026-09-28).
     * The schedule's `withoutOverlapping()` only guards the moment of
     * dispatch, which is over in milliseconds; this holds until the job has
     * run. Released when it finishes or fails, and after `$uniqueFor` at the
     * latest if a worker is killed mid-run.
     */
    public int $uniqueFor = 600;

    public function uniqueId(): string
    {
        return $this->market->value;
    }

    public function __construct(public Market $market) {}

    public function handle(RecentSearches $recent): void
    {
        $recent->refresh($this->market);
    }
}

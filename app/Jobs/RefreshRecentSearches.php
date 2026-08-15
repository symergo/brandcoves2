<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Market;
use App\Services\Search\RecentSearches;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
class RefreshRecentSearches implements ShouldQueue
{
    use Queueable;

    public function __construct(public Market $market) {}

    public function handle(RecentSearches $recent): void
    {
        $recent->refresh($this->market);
    }
}

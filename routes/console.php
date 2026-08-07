<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Jobs\GroupProducts;
use App\Jobs\IngestFeed;
use App\Jobs\RefreshWishlistedProducts;
use App\Models\Feed;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Runs in the `scheduler` container, exactly one replica. Everything here
| dispatches to the queue rather than doing work inline — the scheduler's job is
| to decide *when*, and Horizon's is to decide *where*.
|
| Nothing here may run in a web request, and nothing here that costs AI tokens
| may run outside a queued job. See docs/features/ai-invariant.md.
*/

/*
 * Feed ingestion, twice a day.
 *
 * Awin regenerates an advertiser feed once or twice daily, so downloading
 * hourly re-fetches an unchanged file — hundreds of megabytes of bandwidth,
 * per feed, for no new data.
 *
 * Prices that move intra-day are covered by the live sources (bol queries at
 * request time) and by the wishlist refresh, which re-checks only the handful
 * of products someone actually cares about.
 *
 * 04:10 and 16:10: after the overnight regeneration, and again mid-afternoon.
 */
Schedule::call(function (): void {
    Feed::query()->enabled()->get()->each(
        fn (Feed $feed) => IngestFeed::dispatch($feed->id)
    );
})
    ->name('ingest-feeds')
    ->twiceDailyAt(4, 16, 10)
    // A run that overlaps the previous one would fight over the same cursor.
    // name() must come first — the mutex is keyed on it.
    ->withoutOverlapping()
    ->onOneServer();

// Grouping runs after ingestion has had time to land. Separate from ingestion
// because it is set-based over a whole market: doing it per feed would compute
// a group's cheapest offer from a half-loaded catalogue.
//
// Offset by 50 minutes rather than a few, because a multi-hundred-megabyte feed
// legitimately takes a while.
Schedule::call(function (): void {
    foreach (Market::cases() as $market) {
        GroupProducts::dispatch($market);
    }
})
    ->name('group-products')
    ->twiceDailyAt(5, 17, 0)
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Fire price and restock alerts.
 *
 * Twenty minutes after grouping, not on its own cadence: the only thing that
 * moves a stored price is a feed ingest, and grouping is what turns that into
 * the aggregates an alert compares against. Running more often than the data
 * changes would burn queries to re-read the same numbers.
 */
Schedule::job(new RefreshWishlistedProducts)
    ->name('refresh-wishlisted')
    ->twiceDailyAt(5, 17, 20)
    ->withoutOverlapping()
    ->onOneServer();

// Trim price history to the retention window. Without this the table grows
// without bound to support a 30-day median and a sparkline.
Schedule::command('bc:prune-price-history')
    ->dailyAt('03:30')
    ->onOneServer();

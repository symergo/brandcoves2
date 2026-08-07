<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Jobs\GroupProducts;
use App\Jobs\IngestFeed;
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

// Feed ingestion. Hourly rather than nightly because prices move during the
// day and a stale "cheapest offer" is the one claim this site cannot get wrong.
Schedule::call(function (): void {
    Feed::query()->enabled()->get()->each(
        fn (Feed $feed) => IngestFeed::dispatch($feed->id)
    );
})
    ->name('ingest-feeds')
    ->hourly()
    // A run that overlaps the previous one would fight over the same cursor.
    // name() must come first — the mutex is keyed on it.
    ->withoutOverlapping()
    ->onOneServer();

// Grouping runs after ingestion has had time to land. Separate from ingestion
// because it is set-based over a whole market: doing it per feed would compute
// a group's cheapest offer from a half-loaded catalogue.
Schedule::call(function (): void {
    foreach (Market::cases() as $market) {
        GroupProducts::dispatch($market);
    }
})
    ->name('group-products')
    ->hourlyAt(40)
    ->withoutOverlapping()
    ->onOneServer();

// Trim price history to the retention window. Without this the table grows
// without bound to support a 30-day median and a sparkline.
Schedule::command('bc:prune-price-history')
    ->dailyAt('03:30')
    ->onOneServer();

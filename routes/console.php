<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Jobs\BuildDailyEdition;
use App\Jobs\ClassifyGiftability;
use App\Jobs\GroupProducts;
use App\Jobs\IngestFeed;
use App\Jobs\RefreshBrandStats;
use App\Jobs\RefreshWishlistedProducts;
use App\Jobs\ScoreSerendipity;
use App\Jobs\SendCoveDigest;
use App\Jobs\WidenGiftAngles;
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
 * Re-classify giftability after grouping.
 *
 * A full pass over the catalogue, not an incremental one: the classifier's
 * rules change more often than the products do, and a partial pass would leave
 * yesterday's verdict on most rows with no way to tell which. It is pure CPU
 * with no network, so a full pass is seconds.
 *
 * Ten minutes after grouping, because it reads the denormalised title, category
 * and cheapest price that grouping is what produces.
 */
Schedule::call(function (): void {
    foreach (Market::cases() as $market) {
        ClassifyGiftability::dispatch($market);
    }
})
    ->name('classify-giftability')
    ->twiceDailyAt(5, 17, 10)
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Score serendipity.
 *
 * After giftability, because the quality gate reads that verdict — a row
 * already known to be a printer cartridge must never be scored as an exciting
 * find. Builds the whole market's word-frequency distribution once per run,
 * which is why this is a job and not something a request could ever do.
 */
Schedule::call(function (): void {
    foreach (Market::cases() as $market) {
        ScoreSerendipity::dispatch($market);
    }
})
    ->name('score-serendipity')
    ->twiceDailyAt(5, 17, 25)
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Recompute brand statistics.
 *
 * Brand pages are made entirely of these numbers — "N products, from €X, M of
 * them reduced" — so this has to follow grouping, which is what produces the
 * cheapest price and the median those sentences quote. Five minutes after
 * serendipity, which is the last thing that touches product_groups.
 */
Schedule::call(function (): void {
    foreach (Market::cases() as $market) {
        RefreshBrandStats::dispatch($market);
    }
})
    ->name('refresh-brand-stats')
    ->twiceDailyAt(5, 17, 30)
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Widen the gift angle map, one market per night.
 *
 * The AI invariant in one line: the model runs here, on a schedule, under a
 * daily cap, and writes rows the request path only reads. Staggered across the
 * hour so five markets do not open five connections at once, and a no-op when
 * AI_ENABLED=false — the curated seed is written to be sufficient alone.
 */
foreach (Market::cases() as $index => $market) {
    Schedule::job(new WidenGiftAngles($market))
        ->name('widen-gift-angles-'.$market->value)
        ->dailyAt(sprintf('02:%02d', $index * 7))
        ->onOneServer();
}

/*
 * Build the day's Daily Cove edition, one market at a time.
 *
 * At 06:00, three hours before the 09:00 drop time. The gap is deliberate: the
 * build can fail — a thin catalogue day, an AI hiccup, a feed that arrived late
 * — and three hours is enough for the retry to land or for someone to notice
 * before the page is meant to be there.
 *
 * Staggered per market so five editions do not build at once, each holding a
 * catalogue-wide statistics pass in memory.
 */
foreach (Market::cases() as $index => $market) {
    Schedule::job(new BuildDailyEdition($market))
        ->name('build-daily-cove-'.$market->value)
        ->dailyAt(sprintf('06:%02d', $index * 6))
        ->withoutOverlapping()
        ->onOneServer();
}

/*
 * Send the day's digest, after the edition is live.
 *
 * 09:15, three hours after the build and fifteen minutes after the 09:00 drop.
 * The gap is the point: an email that arrives before the page it links to is a
 * link to a 404 in every inbox at once, and unlike a broken page a sent email
 * cannot be fixed.
 *
 * Staggered per market so five sends do not open five SMTP connections at the
 * same moment.
 */
foreach (Market::cases() as $index => $market) {
    Schedule::job(new SendCoveDigest($market))
        ->name('send-cove-digest-'.$market->value)
        ->dailyAt(sprintf('09:%02d', 15 + ($index * 4)))
        // A second run overlapping the first would re-read `last_sent_on` mid
        // flight; the guard is per subscriber, but the mutex is cheaper.
        ->withoutOverlapping()
        ->onOneServer();
}

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

/*
 * Keep the editorial calendar stocked, 120 days ahead.
 *
 * Weekly rather than daily: it drafts a plan for every day in the window, so a
 * daily run would add exactly one row and re-read four months of dates to do it.
 * Weekly keeps the horizon between 113 and 120 days, which is far enough ahead
 * for anyone planning around Christmas.
 *
 * Idempotent, and it never touches a row a human has looked at — an editor's
 * rejected plan would otherwise come back every Monday.
 */
Schedule::command('bc:plan-coves')
    ->name('plan-coves')
    ->weeklyOn(1, '03:50')
    ->withoutOverlapping()
    ->onOneServer();

// Trim price history to the retention window. Without this the table grows
// without bound to support a 30-day median and a sparkline.
Schedule::command('bc:prune-price-history')
    ->dailyAt('03:30')
    ->onOneServer();

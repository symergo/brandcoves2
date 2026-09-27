<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Jobs\BuildDailyEdition;
use App\Jobs\CheckSearchAlerts;
use App\Jobs\CountListSignals;
use App\Jobs\CountOfflineIdeas;
use App\Jobs\PlanPersonasFromDemand;
use App\Jobs\PublishDueCoves;
use App\Jobs\PullPopularCharts;
use App\Jobs\RefreshRecentSearches;
use App\Jobs\RunEditorialAutomation;
use App\Jobs\SendCoveDigest;
use App\Jobs\SendListPriceDigests;
use App\Jobs\SendOccasionReminders;
use App\Jobs\WidenGiftAngles;
use App\Services\Ingestion\CatalogueRun;
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
 *
 * THE CATALOGUE RUN (since 2026-09-28). One entry starts everything that used
 * to have its own clock time: per market, ingest its feeds, then group,
 * classify, brand statistics, match candidates and (mornings) gift landing
 * pages; market after market; then barcode links and the watched products'
 * refresh. Each step starts when the one before it is done. Before, grouping
 * ran at 05:00 whether the feeds were in or not, classification at 05:10,
 * brand statistics and barcode links at 05:30, match review and landing pages
 * at 05:40, the watched products at 05:20 (and 16:10–17:40 likewise).
 * App\Services\Ingestion\CatalogueRun has the order and the reasons.
 *
 * `withoutOverlapping()` here guards only the dispatch, which takes a moment;
 * each step guards itself when it runs (App\Jobs\Concerns\RunsOneAtATime).
 * name() must come first — the mutex is keyed on it.
 */
Schedule::call(fn () => CatalogueRun::start(Market::cases(), morning: true))
    ->name('catalogue-run-morning')
    ->dailyAt('04:10')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::call(fn () => CatalogueRun::start(Market::cases(), morning: false))
    ->name('catalogue-run-afternoon')
    ->dailyAt('16:10')
    ->withoutOverlapping()
    ->onOneServer();

// What people's lists teach the catalogue: crowd tags on products, and
// products linked by the lists they share. Once a night, in the quiet hours,
// after the personal-data prune (03:20) so deleted lists no longer count.
// See docs/features/list-signals.md.
Schedule::job(new CountListSignals)
    ->name('count-list-signals')
    ->dailyAt('03:50')
    ->onOneServer();

// Offline items people typed by hand, proposed as gift ideas for others once
// five different people wrote the same thing; a person approves each before
// it shows. After the list signals, in the same quiet hour, and after the
// prune (03:20) so deleted items no longer count.
// See docs/features/offline-ideas.md.
Schedule::job(new CountOfflineIdeas)
    ->name('count-offline-ideas')
    ->dailyAt('04:00')
    ->onOneServer();

// Which gift landing pages exist (/gift-ideas/for/papa/koken) is a step of the
// morning catalogue run above since 2026-09-28, after each market's grouping
// and brand statistics. It ran at 05:40. See docs/features/gift-landing-pages.md.

// Gift personas drafted from what people search for: readings searched often
// enough that no persona or landing page answers yet. Drafts only, for a
// person to approve. After the landing pages so a pair that got its page
// tonight is not also drafted as a persona: those are planned by the morning
// catalogue run, which should be through the markets by 06:30 on a normal
// night; if it is not, a pair may be drafted a day early, which a person
// rejecting a draft already covers.
// See docs/features/persona-demand.md.
Schedule::call(function (): void {
    foreach (Market::published() as $market) {
        PlanPersonasFromDemand::dispatch($market);
    }
})
    ->name('plan-personas-from-demand')
    ->dailyAt('06:30')
    ->onOneServer();

/*
 * Bestseller charts — the demand signal.
 *
 * Once a day. A retailer's chart does not turn over hourly, and a second pull
 * would only overwrite the same day's snapshot; what makes the history useful is
 * one honest sample per day over months, not a fine-grained one over a week.
 *
 * 03:40, deliberately ahead of feed ingestion (04:10) and grouping (05:00). The
 * chart's products are upserted into the catalogue, so pulling first means they
 * are grouped in the same overnight cycle rather than waiting a day to become
 * suggestable. See docs/features/popularity-charts.md.
 */
Schedule::call(function (): void {
    foreach (Market::cases() as $market) {
        PullPopularCharts::dispatch($market);
    }
})
    ->name('pull-popular-charts')
    ->dailyAt('03:40')
    // Two crawls would fight over the same cursor and the same per-run budget.
    // name() must come first — the mutex is keyed on it.
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Giftability, brand statistics, match candidates, barcode links and the
 * watched products' refresh are steps of the catalogue run at the top of this
 * file since 2026-09-28, each started when grouping of its market is done.
 *
 * Score serendipity: OFF THE SCHEDULE since 2026-09-27, as follows.
 */

/*
 * Score serendipity: OFF THE SCHEDULE since 2026-09-27.
 *
 * The owner switched the Serendipity Engine (the surprise score) off that day
 * as a trial, to see whether anything visibly gets worse without it: the score
 * had ranked unrelated products high on a daily Cove, and this job failed
 * twice a day, timing out on the whole-market word-frequency pass. A decision
 * on removing it for good is due around 2026-10-11.
 *
 * Only the schedule entry is gone. App\Jobs\ScoreSerendipity and the stored
 * scores stay, and `bc:refresh-discovery` still runs it by hand. To bring it
 * back, add it to CatalogueRun::stepsFor() right after ClassifyGiftability
 * (its quality gate reads that verdict). It ran at 05:25 and 17:25 as:
 *
 *   Schedule::call(function (): void {
 *       foreach (Market::cases() as $market) {
 *           ScoreSerendipity::dispatch($market);
 *       }
 *   })->name('score-serendipity')->twiceDailyAt(5, 17, 25)
 *     ->withoutOverlapping()->onOneServer();
 */

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
 * The morning, in order (reshaped 2026-09-28 so nothing editorial runs while
 * the catalogue run is grouping, which is the heaviest thing the database
 * does all day):
 *
 *   04:10        catalogue run starts (top of this file)
 *   06:00–06:24  editorial automation, one market every six minutes
 *   06:30        persona drafts from demand
 *   06:40–07:04  the Daily builds, one market every six minutes
 *   07:15        watched searches
 *   07:30–07:54  due Coves published
 *   07:40        list price digest
 *   08:10        occasion reminders
 *   09:00        the Daily drops (a property of the edition, not a job)
 *   09:15–09:31  Cove digest mails
 *
 * These are still clock times rather than steps of the catalogue run, because
 * each answers to a promise about the time of day (a mail over breakfast, a
 * Daily ready well before 09:00), not to the catalogue being done. On a night
 * the catalogue run is late they read a catalogue a few hours old, which is
 * what they did before whenever grouping failed.
 */

/*
 * The editorial pipeline, walked once per market.
 *
 * The same stages an instruction drives — plan, curate, write, approve, build —
 * on the scheduler instead of on somebody asking. Which of them run is a switch
 * per market and per kind, and the grid ships seeded to reproduce exactly what
 * the entries below already do, so the first deploy changes nothing. See
 * App\Services\Settings\AutomationSettingsStore.
 *
 * One job per market that walks the enabled stages **in order**, rather than one
 * per stage: staggered stages mean a plan drafted at 03:50 waits until tomorrow
 * to be curated, where a sequential walk takes a plan from nothing to approved
 * in a single run.
 *
 * 06:00 (05:00 until 2026-09-28, which put it on top of grouping), before the
 * Daily builds below and before `PublishDueCoves` at 07:30, so anything this
 * approves is honoured the same morning rather than waiting a day. Staggered
 * per market for the same reason everything else here is: each build holds a
 * catalogue-wide selection in memory.
 *
 * It cannot publish on its own. `buildArticle()` refuses a plan nobody
 * approved, and `approve` ships off for every kind.
 */
foreach (Market::cases() as $index => $market) {
    Schedule::job(new RunEditorialAutomation($market))
        ->name('editorial-automation-'.$market->value)
        ->dailyAt(sprintf('06:%02d', $index * 6))
        ->withoutOverlapping()
        ->onOneServer();
}

/*
 * Build the day's Daily Cove edition, one market at a time.
 *
 * At 06:40 (06:00 until 2026-09-28), after the editorial automation and more
 * than two hours before the 09:00 drop time. The gap is deliberate: the build
 * can fail — a thin catalogue day, an AI hiccup, a feed that arrived late —
 * and two hours is enough for the retry to land or for someone to notice
 * before the page is meant to be there.
 *
 * Staggered per market so five editions do not build at once, each holding a
 * catalogue-wide statistics pass in memory.
 */
foreach (Market::cases() as $index => $market) {
    Schedule::job(new BuildDailyEdition($market))
        ->name('build-daily-cove-'.$market->value)
        // 06:40, 06:46, 06:52, 06:58, 07:04.
        ->dailyAt(sprintf('%02d:%02d', 6 + intdiv(40 + $index * 6, 60), (40 + $index * 6) % 60))
        ->withoutOverlapping()
        ->onOneServer();
}

/*
 * Publish the approved Coves whose date has arrived.
 *
 * 07:30 (07:00 until 2026-09-28), after the last market's Daily has built.
 * Seasonal Coves are laid out as a series of dated parts across their window,
 * and this is what makes that date mean something — an editor approves the
 * part and it goes live on the day they scheduled it for, rather than whenever
 * somebody remembers to press Build.
 *
 * Not automatic publishing: `buildArticle()` refuses anything that is not
 * approved, so a draft on a past date sits here for ever. See
 * App\Jobs\PublishDueCoves and docs/features/seasonal-series.md.
 *
 * One pass per market rather than one for everything, for the same reason the
 * build above is staggered: each build holds a catalogue-wide selection in
 * memory, and five markets' worth at once is five of them.
 */
foreach (Market::cases() as $index => $market) {
    Schedule::job(new PublishDueCoves($market))
        ->name('publish-due-coves-'.$market->value)
        ->dailyAt(sprintf('07:%02d', 30 + ($index * 6)))
        // A second pass overlapping the first would dispatch every due plan
        // twice, and two builds of one article race over the same edition row.
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
 * Watched searches. Once a day, after the morning catalogue run has grouped
 * the markets: the catalogue changes with ingestion, and a check between two
 * ingests re-reads the same rows. 07:15 since 2026-09-28 (06:30 before), out of
 * the way of the Daily builds. See App\Jobs\CheckSearchAlerts.
 *
 * The price and restock alerts are fired at the end of the catalogue run
 * (RefreshWishlistedProducts, then FireWatchAlerts), no longer at 05:20.
 */
Schedule::job(new CheckSearchAlerts)
    ->name('check-search-alerts')
    ->dailyAt('07:15')
    ->onOneServer();

/*
 * The list price digest: bstore's wishlist mail, on GiftCoves.
 *
 * Once a day, after the morning catalogue run's live refresh of the watched
 * products has made a bol price today's (it ran at 05:20 before; it is now the
 * last step of the run, which on a normal night is done well before 07:40).
 * Not after the afternoon run as well: a second pass would mail the same
 * person twice a day about one product, and a digest that arrives twice is a
 * digest that gets muted. See App\Jobs\SendListPriceDigests and
 * docs/features/list-price-watch.md.
 */
Schedule::job(new SendListPriceDigests)
    ->name('list-price-digests')
    ->dailyAt('07:40')
    ->onOneServer();

/*
 * Occasion reminders.
 *
 * Once a day, in the morning: a reminder that a birthday is a fortnight away is
 * something to read over coffee, not at 3am. Three dates feed it —
 * `recipients.birthday`, a Secret Santa exchange, and the occasion on a list —
 * and all three were written and never read until this job existed.
 *
 * The job dedupes per occurrence itself rather than relying on the schedule
 * running exactly once — a redeploy can replay a window, and a duplicated
 * reminder is how a notification channel gets muted. It also emails on the pass
 * that writes the row, so the dedupe covers both channels.
 *
 * **How many days ahead is not decided here.** It is
 * `config('giftcoves.reminders.lead_days')`, edited at Operations → Reminders,
 * so the schedule stays "once a day" and the judgement about how people shop
 * stays where somebody can change it without a deploy.
 */
Schedule::job(new SendOccasionReminders)
    ->name('occasion-reminders')
    ->dailyAt('08:10')
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

/*
 * Give published guides their prose back, and keep it current.
 *
 * Nothing else revisits a published guide, so one built while the model was
 * unreachable kept template copy for good. That is not hypothetical: every guide
 * generated before the response parser was fixed is in exactly that state.
 *
 * Daily and small. The per-feature cap is the real limiter, and a run that walks
 * a handful of guides a night clears the backlog inside a fortnight without ever
 * competing with the morning editions for the day's budget. 04:40 is after the
 * prunes and well before the editorial automation (06:00) and Daily builds
 * (06:40).
 */
Schedule::command('bc:refresh-guide-copy --limit=8')
    ->name('refresh-guide-copy')
    ->dailyAt('04:40')
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Enforce the retention windows the privacy policy publishes.
 *
 * GDPR Article 5(1)(e). A retention period stated in a privacy notice and not
 * enforced anywhere in the code is not a retention period, it is a sentence.
 * Nightly, before the price-history prune, because both are cheap and quiet.
 */
Schedule::command('bc:prune-personal-data')
    ->name('prune-personal-data')
    ->dailyAt('03:20')
    ->onOneServer();

// Trim rank history to its retention window. Price history no longer exists
// (2026-09-12): an offer keeps first, previous and current price on its row.
Schedule::command('bc:prune-rank-history')
    ->dailyAt('03:30')
    ->onOneServer();

/*
 * Turn the last hour of searches into pictures for the front page.
 *
 * `search_log` stores queries and never the products they returned, so this
 * band can only exist by running those searches again — which is exactly why it
 * happens here and not in the request. The homepage had three COUNT(*) queries
 * removed for being too expensive; six searches per page view would be worse.
 *
 * Staggered by two minutes per market so five markets do not run their searches
 * in the same second, and published markets only: an unpublished market has no
 * visitors and therefore no searches to resolve.
 *
 * From :05 rather than :00 (2026-09-28): on the hour is when every other
 * hourly and daily entry fires, and the first market's searches used to land
 * on top of them.
 */
foreach (Market::published() as $index => $market) {
    Schedule::job(new RefreshRecentSearches($market))
        ->name("refresh-recent-searches-{$market->value}")
        ->hourlyAt(5 + $index * 2)
        ->onOneServer();
}

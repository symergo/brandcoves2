<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Models\CovePlan;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Build the approved plans whose date has arrived.
 *
 * A date on a plan has always meant something for the Daily — `BuildDailyEdition`
 * reads it at 06:00 — and meant nothing for anything else, because nothing else
 * could carry one. Seasonal Coves now can: a season is laid out as a series of
 * parts across its window, each with a `drop_date`, and without this that date
 * would be decoration on a planner screen.
 *
 * ## This is not automatic publishing
 *
 * `buildArticle()` refuses any plan that is not `approved`, and approving is a
 * person reading the shortlist and the brief and deciding. What this adds is
 * that the approval is honoured **on the day the editor scheduled it for**
 * rather than whenever somebody remembers to press Build. A draft sitting on a
 * past date does nothing here, for ever.
 *
 * ## A season comes round, and the page does not move
 *
 * The calendar repeats: the camping window opens every March. When the planner
 * slides a part's `drop_date` into the coming window, this rebuilds it **at the
 * same URL** — new products, newly written prose, `published_at` untouched. So
 * the yearly refresh is one date change here and a rebuild there, and the page
 * keeps the ranking it spent a year earning.
 *
 * The alternative was to publish a season the moment it was approved, which
 * defeats the point of spreading it: four pages that went live together are one
 * long page delivered awkwardly, and the last of them would have three months
 * of window left to be indexed in that it never uses.
 *
 * ## Two things it deliberately does not do
 *
 * **It never touches a Daily.** `BuildDailyEdition` owns that date, does other
 * work about the day around it — mining the search log, seeding the seasonal
 * topics — and running both would build one edition twice.
 *
 * **It stops at the end of a season's window.** An approved Halloween part that
 * could not build in October must not appear in December: the window is when
 * the demand exists, and a page dated outside it is worse than a missing one. It
 * is not cancelled either — the plan keeps its approval, and the window reopens
 * next year.
 */
class PublishDueCoves implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function __construct(public Market $market) {}

    public function handle(): void
    {
        $today = CarbonImmutable::today();

        $due = CovePlan::query()
            ->where('market', $this->market->value)
            ->where('kind', '!=', CoveKind::Daily->value)
            ->where('status', 'approved')
            ->whereNotNull('drop_date')
            ->whereDate('drop_date', '<=', $today->toDateString())
            /*
             * Not already built for this date.
             *
             * `built_for` is the `drop_date` a plan was last honoured on, and
             * comparing it with the current one is what lets a season come round
             * without letting a page rebuild itself nightly. A part built for
             * April 2027 and re-dated into the March 2028 window is due again;
             * one still sitting on the date it was built for is not, and
             * re-running it would re-write the prose of every part of every
             * season every night — real spend against `guide_copy` for a page
             * nobody asked to change.
             *
             * This used to read `edition_id IS NULL`, which meant "never built"
             * and quietly also meant "never buildable again". See the migration
             * that added the column.
             */
            ->where(fn ($q) => $q->whereNull('built_for')->orWhereColumn('built_for', '<', 'drop_date'))
            // Oldest first, so a catch-up after an outage publishes a series in
            // the order it was written to be read.
            ->orderBy('drop_date')
            ->orderBy('part')
            ->get();

        foreach ($due as $plan) {
            if (! $this->inSeason($plan, $today)) {
                continue;
            }

            BuildCove::dispatch($plan->id);

            Log::info('Due Cove queued for build', [
                'plan' => $plan->id,
                'kind' => $plan->kind->value,
                'market' => $plan->market->value,
                'due' => $plan->drop_date?->toDateString(),
            ]);
        }
    }

    /**
     * Is this plan still inside the window it was written for?
     *
     * True for anything with no window at all — most kinds have none, and
     * "undated demand" is the normal case rather than a missing value.
     *
     * Compared as `MM-DD` strings, which sorts chronologically. A window whose
     * end precedes its start wraps the year: Valentine's runs from 27 December,
     * and read literally that is an empty range that would silently stop every
     * part of it from ever publishing.
     */
    private function inSeason(CovePlan $plan, CarbonImmutable $today): bool
    {
        $from = (string) $plan->season_from;
        $to = (string) $plan->season_to;

        if ($from === '' || $to === '') {
            return true;
        }

        $day = $today->format('m-d');

        return $from <= $to
            ? $day >= $from && $day <= $to
            : $day >= $from || $day <= $to;
    }
}

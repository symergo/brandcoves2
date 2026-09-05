<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Models\CovePlan;
use App\Services\Cove\EditionBuilder;
use App\Services\Cove\Observance;
use App\Services\Cove\ObservanceCalendar;
use App\Services\Cove\SeasonalSeries;
use App\Services\Curation\PlanCurator;
use App\Services\Guides\SeasonalTopics;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Fill the editorial calendar from the observance list.
 *
 * The calendar and the planner were built separately and did not meet: the
 * observances only became visible on the morning they fired, and the plan table
 * opened empty, so an editor had to know the year's themed days by heart to put
 * anything in it.
 *
 * This walks forward and writes a **draft** plan for every themed day it finds.
 * Draft, not approved — the point is to give someone something to react to, and
 * auto-approving would put a machine's guess in front of readers under the
 * banner of editorial planning.
 *
 * It also lays out the **seasons**. A seasonal Cove is published as a series of
 * dated parts across its window — one per subject the season names — and until
 * this ran them, the calendar was the one place a season's schedule could not be
 * seen. Both halves are drawn by the same command because they answer one
 * question: what is this market publishing over the next few months. See
 * docs/features/seasonal-series.md, and `--no-seasons` to draw only the Dailies.
 *
 * Idempotent, and it never touches a plan a human has looked at: an existing
 * row for a date is left exactly as it is, whatever its status. A season that
 * already has a plan is never laid out twice.
 */
class PlanCovesCommand extends Command
{
    protected $signature = 'bc:plan-coves
        {--market= : One market. Omit for all of them.}
        {--days=120 : How far ahead to look.}
        {--from= : Start date (Y-m-d). Defaults to tomorrow.}
        {--no-products : Draft the themes only, and leave the shortlists empty. Seasonal parts are exempt — the shortlist is what decides whether a part exists at all.}
        {--no-seasons : Draft the Daily calendar only, and leave the seasonal series alone.}';

    protected $description = 'Draft Cove plans for upcoming themed days, each with a shortlist to curate.';

    public function handle(
        ObservanceCalendar $calendar,
        EditionBuilder $builder,
        PlanCurator $curator,
        SeasonalTopics $seasons,
        SeasonalSeries $series,
    ): int {
        $markets = $this->markets();

        if ($markets === []) {
            $this->error('Unknown market. Valid: '.implode(', ', Market::values()));

            return self::FAILURE;
        }

        // From tomorrow: today's edition has already been built, and a plan for
        // it would be read too late to change anything.
        $from = $this->option('from')
            ? CarbonImmutable::parse((string) $this->option('from'))
            : CarbonImmutable::tomorrow();

        $days = max(1, (int) $this->option('days'));
        $created = 0;
        $skipped = 0;
        $filled = 0;
        $perPlan = (int) config('giftcoves.picks.per_day');

        /*
         * Products already handed to a plan in this run.
         *
         * The rolling repeat memory reads `daily_picks`, and none of these days
         * has been built yet — so without this the highest-scoring seven
         * products in the market would be suggested for every one of the next
         * hundred plans, and the feature would look broken on first sight.
         */
        $spoken = [];

        foreach ($markets as $market) {
            foreach ($this->calendar($calendar, $from, $market, $days) as $date => $observance) {
                /*
                 * A *Daily* for this date, specifically.
                 *
                 * A seasonal part carries a date too now, and it is a due date
                 * rather than an address — nothing is competing for
                 * `/daily/{date}`. Left unscoped, one part of one season would
                 * silently cost that day its Daily Cove, and the reason would be
                 * a row on a different tab of the planner.
                 */
                $exists = CovePlan::query()
                    ->where('market', $market->value)
                    ->where('kind', CoveKind::Daily->value)
                    ->where('drop_date', $date)
                    ->exists();

                if ($exists) {
                    // Somebody has already decided something about this day.
                    // Overwriting it would quietly undo their work.
                    $skipped++;

                    continue;
                }

                $plan = CovePlan::create([
                    'market' => $market->value,
                    // Spelled out rather than left to the column default, now
                    // that `daily` is no longer the only kind that can hold a
                    // date.
                    'kind' => CoveKind::Daily->value,
                    'drop_date' => $date,
                    'title' => $observance->title($market),
                    'blurb' => $observance->blurb($market),
                    'queries' => $observance->queries,
                    'status' => 'draft',
                    'note' => $observance->evergreen
                        ? 'Rotation theme ('.$observance->key.') — no named day falls here. Replace it with something better if you have one.'
                        : 'Drafted from the observance calendar ('.$observance->key.'). Edit or approve.',
                ]);

                $created++;

                /*
                 * The shortlist a curator will react to.
                 *
                 * A plan that opens empty asks somebody to invent seven
                 * products from nothing — the blank page, and the reason the
                 * old pinned-products field went unused. This is the same
                 * selection the builder would make on the day, so leaving it
                 * untouched publishes exactly what would have published anyway
                 * and every edit is an improvement on it.
                 */
                if (! $this->option('no-products')) {
                    $candidates = $builder->candidates($plan, $perPlan, $spoken[$market->value] ?? []);
                    $filled += $curator->prefill($plan, $candidates);

                    foreach ($candidates as $group) {
                        // Per market: `product_groups` is unique on (market,
                        // identity_key), so the same product in two markets is
                        // two different rows and suggesting both is correct.
                        $spoken[$market->value][] = $group->id;
                    }
                }
            }
        }

        $this->components->info(
            "Drafted {$created} Daily plan(s) with {$filled} suggested product(s); left {$skipped} existing one(s) alone."
        );

        if (! $this->option('no-seasons')) {
            $this->seasons($markets, $seasons, $series, $days);
        }

        return self::SUCCESS;
    }

    /**
     * The seasons that should already be on the calendar, laid out as parts.
     *
     * A season is the one non-Daily kind that is *defined* by dates, and it was
     * the one kind whose dates appeared nowhere: the planner held Dailies and a
     * pile of undated ideas, and "the Halloween run starts in three weeks" was
     * knowable only by opening a config file. Each season is now several dated
     * parts, one per subject it names, spread across its own window — see
     * App\Services\Cove\SeasonalSeries.
     *
     * Seeded first. `SeasonalTopics::seed()` is idempotent, never overturns a
     * decision an editor has made, and is what puts the calendar's windows in
     * the database at all — without it a fresh environment finds nothing to lay
     * out and reports "0 seasons" as though there were none.
     *
     * `--days` bounds this the same way it bounds the Daily walk: a season whose
     * window opens beyond the horizon is not yet worth putting in front of
     * anybody. `--from` does **not** move it, because a part's date comes from
     * its own window rather than from where the walk happened to start.
     *
     * ## The calendar repeats
     *
     * A season that already ran is **renewed**, not laid out again: its parts
     * slide onto the coming window's dates and rebuild at the same URLs, so the
     * page keeps the ranking it spent a year earning. Which of the two happens
     * is `SeasonalSeries::plan()`'s decision, not this command's — and a season
     * whose parts are already dated inside the window it is heading for reports
     * nothing at all, which is what this weekly run finds almost every time.
     *
     * @param  list<Market>  $markets
     */
    private function seasons(array $markets, SeasonalTopics $seasons, SeasonalSeries $series, int $days): void
    {
        $laid = 0;
        $renewed = 0;
        $parts = 0;
        $thin = 0;

        foreach ($markets as $market) {
            $seasons->seed($market);

            foreach ($seasons->opening($market, CarbonImmutable::today(), $days) as $topic) {
                // Asked before, because after the call the topic points at a
                // plan either way and the two outcomes are indistinguishable.
                $returning = $topic->plan_id !== null;
                $touched = $series->plan($topic);

                if ($touched === []) {
                    /*
                     * Either the catalogue cannot fill a single part of it yet —
                     * the topic stays a candidate, parked rather than banned, so
                     * next week's run tries again — or the season is already
                     * scheduled for the window it is heading for and there was
                     * nothing to do. Counted as thin only in the first case;
                     * the second is the steady state and reporting it would
                     * make a healthy run read as a failing one.
                     */
                    $thin += $returning ? 0 : 1;

                    continue;
                }

                $returning ? $renewed++ : $laid++;
                $parts += count($touched);
            }
        }

        $this->components->info(
            "Laid out {$laid} new season(s) and brought {$renewed} round again, across {$parts} dated part(s)"
            .($thin > 0 ? "; skipped {$thin} the catalogue cannot fill yet." : '.')
        );
    }

    /**
     * Every day in the window, themed.
     *
     * `upcoming()` returns named days only, which left the plan table with a
     * dozen rows a quarter and nothing to review in between. The planner wants
     * the opposite: a row for every day, so an editor can see the whole month
     * and only has to intervene where the rotation's guess is weak.
     *
     * @return array<string, Observance>
     */
    private function calendar(ObservanceCalendar $calendar, CarbonImmutable $from, Market $market, int $days): array
    {
        $found = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $from->addDays($i);
            $theme = $calendar->themeFor($date, $market);

            if ($theme !== null) {
                $found[$date->toDateString()] = $theme;
            }
        }

        return $found;
    }

    /** @return list<Market> */
    private function markets(): array
    {
        $requested = $this->option('market');

        if ($requested === null) {
            return Market::cases();
        }

        $market = Market::tryFrom((string) $requested);

        return $market === null ? [] : [$market];
    }
}

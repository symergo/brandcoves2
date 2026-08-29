<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Market;
use App\Models\CovePlan;
use App\Services\Cove\EditionBuilder;
use App\Services\Cove\Observance;
use App\Services\Cove\ObservanceCalendar;
use App\Services\Curation\PlanCurator;
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
 * Idempotent, and it never touches a plan a human has looked at: an existing
 * row for a date is left exactly as it is, whatever its status.
 */
class PlanCovesCommand extends Command
{
    protected $signature = 'bc:plan-coves
        {--market= : One market. Omit for all of them.}
        {--days=120 : How far ahead to look.}
        {--from= : Start date (Y-m-d). Defaults to tomorrow.}
        {--no-products : Draft the themes only, and leave the shortlists empty.}';

    protected $description = 'Draft Cove plans for upcoming themed days, each with a shortlist to curate.';

    public function handle(ObservanceCalendar $calendar, EditionBuilder $builder, PlanCurator $curator): int
    {
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
                $exists = CovePlan::query()
                    ->where('market', $market->value)
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
            "Drafted {$created} plan(s) with {$filled} suggested product(s); left {$skipped} existing one(s) alone."
        );

        return self::SUCCESS;
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

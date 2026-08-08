<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Market;
use App\Models\CovePlan;
use App\Services\Cove\Observance;
use App\Services\Cove\ObservanceCalendar;
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
        {--from= : Start date (Y-m-d). Defaults to tomorrow.}';

    protected $description = 'Draft Cove plans for upcoming themed days.';

    public function handle(ObservanceCalendar $calendar): int
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

                CovePlan::create([
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
            }
        }

        $this->components->info("Drafted {$created} plan(s); left {$skipped} existing one(s) alone.");

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

<?php

declare(strict_types=1);

namespace App\Services\Cove;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Models\CovePlan;
use App\Models\GuideTopic;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The editorial year for one market: its seasons, its named days, and what is
 * planned against each.
 *
 * The year was knowable and nowhere visible. `config/observances.php` held
 * ninety-five named days, `config/cove_seasons.php` two dozen season windows and
 * `config/cove_themes.php` the sixty-four themes that fill the rest — three
 * files, none of them a calendar, and the only screen that showed a date at all
 * was the planner, which lists what has already been drafted. "What is coming,
 * and have we done anything about it" was a question you answered by reading
 * PHP.
 *
 * So this assembles the year from the calendar itself and hangs the planning
 * state off it. Two consequences worth knowing:
 *
 * **The config is the spine, not the database.** A fresh environment that has
 * never run `bc:refresh-discovery` shows the complete year on the first page
 * load, because the year does not depend on anything having been seeded. The
 * `guide_topics` and `cove_plans` rows are joined on where they exist and their
 * absence is a state — "nothing planned" — rather than a gap.
 *
 * **It is per year, and every year is the same calendar.** `MM-DD` entries
 * resolve against the year asked for, moving observances are computed for it,
 * and a season window that wraps the year end is anchored to the year it opens
 * in. That is what makes the calendar recurring rather than a one-off: 2029 is
 * already fully drawn, and by the time it arrives the planner will have filled
 * it in.
 *
 * Read-only. Nothing here writes; the page that renders it dispatches the same
 * services the planner and the console command use.
 */
final readonly class YearCalendar
{
    public function __construct(private ObservanceCalendar $calendar) {}

    /**
     * The twelve months of `$year` in `$market`.
     *
     * @return list<array<string, mixed>>
     */
    public function for(Market $market, int $year): array
    {
        $seasons = $this->seasons($market, $year);
        $days = $this->days($market, $year);

        $months = [];

        foreach (range(1, 12) as $month) {
            $months[] = [
                'month' => $month,
                'label' => CarbonImmutable::create($year, $month, 1)->translatedFormat('F'),
                /*
                 * A season appears in every month it runs through, marked on the
                 * one it opens in.
                 *
                 * Listing it only where it starts would hide the fact that half
                 * of August is three overlapping windows, which is exactly the
                 * sort of thing an editor opens a year view to see. Repeating it
                 * without saying which month is the start would be worse: every
                 * month would read as a deadline.
                 */
                'seasons' => array_values(array_filter(
                    $seasons,
                    fn (array $season) => $this->runsThrough($season, $year, $month),
                )),
                'days' => array_values(array_filter(
                    $days,
                    fn (array $day) => $day['month'] === $month,
                )),
            ];
        }

        return $months;
    }

    /**
     * How much of this year is already decided, in one line.
     *
     * The number a person wants before they start reading twelve months of
     * detail: it is the difference between "the calendar is in hand" and "nobody
     * has looked at the autumn".
     *
     * @return array<string, int>
     */
    public function summary(Market $market, int $year): array
    {
        $seasons = $this->seasons($market, $year);
        $days = $this->days($market, $year);

        return [
            'seasons' => count($seasons),
            'seasonsPlanned' => count(array_filter($seasons, fn (array $s) => $s['parts'] !== [])),
            'parts' => array_sum(array_map(fn (array $s) => count($s['parts']), $seasons)),
            'days' => count($days),
            'daysPlanned' => count(array_filter($days, fn (array $d) => $d['plan'] !== null)),
        ];
    }

    /**
     * Every season this market runs in `$year`, with its parts.
     *
     * @return list<array<string, mixed>>
     */
    private function seasons(Market $market, int $year): array
    {
        $topics = GuideTopic::query()
            ->where('market', $market->value)
            ->where('origin', 'seasonal')
            ->get()
            ->keyBy('topic');

        $parts = $this->parts($market);

        $seasons = [];

        foreach ((array) config('cove_seasons', []) as $entry) {
            if (! is_array($entry) || ! isset($entry['topic'])) {
                continue;
            }

            $markets = (array) ($entry['markets'] ?? ['*']);

            // Sinterklaas is not a Spanish season, and a calendar that showed it
            // in `es` would be inviting somebody to plan a page for an event
            // that does not happen there.
            if (! in_array('*', $markets, true) && ! in_array($market->value, $markets, true)) {
                continue;
            }

            $topic = (string) $entry['topic'];
            $window = (array) ($entry['window'] ?? []);
            $from = $this->monthDay((string) ($window['from'] ?? ''), $year);
            $to = $this->monthDay((string) ($window['to'] ?? ''), $year);

            if ($from === null || $to === null) {
                continue;
            }

            // A window that ends before it starts wraps the year end —
            // Valentine's runs from 27 December — and is anchored to the year it
            // opens in, so it appears once rather than as two half-seasons.
            if ($to->lessThan($from)) {
                $to = $to->addYear();
            }

            $row = $topics->get($topic);

            $seasons[] = [
                'topic' => $topic,
                'from' => $from,
                'to' => $to,
                'window' => $from->translatedFormat('j M').' – '.$to->translatedFormat('j M'),
                /*
                 * Only known once the topic has been seeded. Null is "nobody has
                 * counted", which is a different thing from zero and has to read
                 * differently on the page — a fresh environment would otherwise
                 * show two dozen seasons all claiming no products exist.
                 */
                'availableProducts' => $row?->available_products,
                'rejected' => $row?->status === 'rejected',
                'parts' => $parts->get($topic, collect())->values()->all(),
            ];
        }

        usort($seasons, fn (array $a, array $b) => $a['from'] <=> $b['from']);

        return $seasons;
    }

    /**
     * The seasonal parts already planned in this market, by season.
     *
     * Every year at once rather than the year on screen. A part carries the date
     * it is next due, so a series scheduled for 2028 has no rows in 2027 at all
     * — and a 2027 calendar that showed a season as unplanned because its parts
     * had already been rolled forward would be lying about the work that exists.
     *
     * @return Collection<string, Collection<int, array<string, mixed>>>
     */
    private function parts(Market $market): Collection
    {
        return CovePlan::query()
            ->where('market', $market->value)
            ->where('kind', CoveKind::Seasonal->value)
            ->orderBy('part')
            ->orderBy('drop_date')
            ->get()
            ->map(fn (CovePlan $plan) => [
                'id' => $plan->id,
                /*
                 * `series_key` where there is one, and the plan's own slug where
                 * there is not: a season the catalogue could fill one subject of
                 * carries neither a series key nor a part number, and grouping
                 * every such plan under one null key would pile unrelated
                 * seasons together.
                 */
                'series' => $plan->series_key ?? $this->seasonOf($plan),
                'part' => $plan->part,
                'title' => $plan->title,
                'slug' => $plan->slug,
                'status' => $plan->status,
                'due' => $plan->drop_date?->toDateString(),
                'dueLabel' => $plan->drop_date?->translatedFormat('j M Y'),
                'built' => $plan->built_for?->toDateString(),
                // The date has moved past the last build, so the next run of the
                // due job will refresh this page. Worth showing: it is the whole
                // recurrence, and it is invisible from a title and a date.
                'refreshDue' => $plan->edition_id !== null
                    && $plan->drop_date !== null
                    && ($plan->built_for === null || $plan->built_for->lessThan($plan->drop_date)),
                'published' => $plan->edition_id !== null,
            ])
            ->groupBy('series');
    }

    /**
     * Which season a part with no series key belongs to.
     *
     * A single-part season is planned from a topic whose word is in the plan's
     * slug — `beste-kamperen` — and the topic is what the calendar is keyed on.
     * Falling back to the slug means such a plan groups under something rather
     * than under null, even when the guess is wrong.
     */
    private function seasonOf(CovePlan $plan): string
    {
        $topic = GuideTopic::query()
            ->where('market', $plan->market->value)
            ->where('plan_id', $plan->id)
            ->value('topic');

        return (string) ($topic ?? $plan->slug ?? 'cove');
    }

    /**
     * Every named day in `$year`, with the plan against it if there is one.
     *
     * `on()` rather than `themeFor()`, deliberately. The evergreen rotation
     * gives every remaining date a theme, and listing all three hundred of them
     * would bury the ninety-five days that are actually occasions — the rotation
     * is the fallback that stops an edition opening with a shrug, not something
     * to plan around. A rotation day still gets a Daily plan; it simply does not
     * belong on a calendar of things that are happening.
     *
     * @return list<array<string, mixed>>
     */
    private function days(Market $market, int $year): array
    {
        $start = CarbonImmutable::create($year, 1, 1);
        $end = $start->addYear();

        $plans = CovePlan::query()
            ->where('market', $market->value)
            ->where('kind', CoveKind::Daily->value)
            ->whereNotNull('drop_date')
            ->whereDate('drop_date', '>=', $start->toDateString())
            ->whereDate('drop_date', '<', $end->toDateString())
            ->get()
            ->keyBy(fn (CovePlan $plan) => $plan->drop_date->toDateString());

        $days = [];

        for ($date = $start; $date->lessThan($end); $date = $date->addDay()) {
            $observance = $this->calendar->on($date, $market);

            if ($observance === null) {
                continue;
            }

            $plan = $plans->get($date->toDateString());

            $days[] = [
                'date' => $date->toDateString(),
                'month' => $date->month,
                'day' => $date->day,
                'label' => $date->translatedFormat('j M'),
                'key' => $observance->key,
                'title' => $observance->title($market),
                'plan' => $plan === null ? null : [
                    'id' => $plan->id,
                    'title' => $plan->title,
                    'status' => $plan->status,
                    'published' => $plan->edition_id !== null,
                ],
            ];
        }

        return $days;
    }

    /** Does this season run through `$month` of `$year`? */
    private function runsThrough(array $season, int $year, int $month): bool
    {
        $first = CarbonImmutable::create($year, $month, 1);
        $last = $first->endOfMonth();

        return $season['from'] <= $last && $season['to'] >= $first;
    }

    /**
     * One `MM-DD` as a date in `$year`, or null if it is not one.
     *
     * Null rather than a throw: these strings come from a config file a person
     * edits, and one typo must cost that season its row on the calendar rather
     * than costing the whole page.
     */
    private function monthDay(string $monthDay, int $year): ?CarbonImmutable
    {
        if (preg_match('/^(\d{2})-(\d{2})$/', $monthDay, $matches) !== 1) {
            return null;
        }

        $date = CarbonImmutable::create($year, (int) $matches[1], (int) $matches[2]);

        return $date instanceof CarbonImmutable ? $date : null;
    }
}

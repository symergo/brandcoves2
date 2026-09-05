<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Http\Controllers\Controller;
use App\Models\CovePlan;
use App\Models\GuideTopic;
use App\Services\Cove\PlanDrafter;
use App\Services\Cove\SeasonalSeries;
use App\Services\Cove\YearCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The editorial year, and the two ways to act on it.
 *
 * The Cove calendar screen answers "what is coming, and have we done anything
 * about it" — a question the planner cannot answer, because the planner lists
 * plans that already exist. It had two write actions and neither was reachable
 * over HTTP, so an outside author could only ask for "the next N" and take
 * whatever the walk found.
 *
 * ## One source, three consumers
 *
 * `ObservanceCalendar::themeFor()` — a named day, else the evergreen rotation —
 * is read by `YearCalendar` for the screen, by `PlanDrafter` for the drafts, and
 * now by this controller for both. Nothing here is a second calendar.
 *
 * ## Why the year matters more than a count
 *
 * `POST /coves/drafts` walks forward from tomorrow filling whatever it finds.
 * That is right for topping a queue up and wrong for somebody who has looked at
 * September and wants the fourteenth: asking for four months of plans to get the
 * one you meant is not a reasonable trade, and neither is undoing the other
 * hundred and nineteen.
 */
class CoveCalendarController extends Controller
{
    public function __construct(
        private readonly YearCalendar $calendar,
        private readonly PlanDrafter $drafter,
        private readonly SeasonalSeries $series,
    ) {}

    /**
     * The year drawn for one market: season windows, named days, and what is
     * planned against each.
     *
     * Assembled from the config rather than from the database, so a fresh
     * environment that has never run `bc:refresh-discovery` still shows the
     * complete year on the first call — the plans are joined where they exist,
     * and their absence is a state ("nothing planned") rather than a gap.
     */
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'market' => ['required', Rule::in(Market::values())],
            /*
             * Last year through the year after next, which is what the screen
             * offers. Every entry is `MM-DD` and the moving observances are
             * computed per year, so this is a recurring calendar rather than a
             * report on one — 2029 is already fully drawn.
             */
            'year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
        ]);

        $market = Market::from($data['market']);
        $year = (int) ($data['year'] ?? CarbonImmutable::today()->year);

        return response()->json([
            'market' => $market->value,
            'year' => $year,
            'summary' => $this->calendar->summary($market, $year),
            'months' => $this->calendar->for($market, $year),
            /*
             * Said out loud, because leaving it out reads as "nothing happens in
             * the gaps". Every date without a named day still gets a Daily Cove
             * themed from the rotation — about 270 of them — and listing those
             * would bury the ninety-five that are actually occasions.
             */
            'note' => 'Dates with no named day are not listed. They still get a Daily Cove, themed from the '
                .'evergreen rotation; there is nothing to plan around, which is why the screen hides them too.',
        ]);
    }

    /**
     * Draft the plan for one named day.
     *
     * `PlanDrafter::draftOn()` fills exactly the day asked for, and answers null
     * rather than inventing something when that day has no theme or already has
     * a plan. Both are reported as what they are, because "nothing happened" and
     * "somebody already decided this" are different answers and only one of them
     * means try another date.
     */
    public function draftDay(Request $request): JsonResponse
    {
        $data = $request->validate([
            'market' => ['required', Rule::in(Market::values())],
            'date' => ['required', 'date_format:Y-m-d'],
            'kind' => ['nullable', Rule::in([CoveKind::Daily->value])],
        ]);

        $market = Market::from($data['market']);
        $day = CarbonImmutable::parse($data['date']);

        $existing = CovePlan::query()
            ->where('market', $market->value)
            ->where('kind', CoveKind::Daily->value)
            ->whereDate('drop_date', $day->toDateString())
            ->first();

        if ($existing !== null) {
            throw ValidationException::withMessages([
                'date' => "{$market->value} already has a plan for {$day->toDateString()} "
                    ."(#{$existing->id}, {$existing->status}). Read or edit that one rather than adding a second — "
                    .'the unique index allows exactly one Daily per market per day.',
            ]);
        }

        $plan = $this->drafter->draftOn(CoveKind::Daily, $market, $day);

        if ($plan === null) {
            throw ValidationException::withMessages([
                'date' => "Nothing to draft on {$day->toDateString()} in {$market->value}. "
                    .'Check GET /calendar for the days this market has themes for.',
            ]);
        }

        return response()->json([
            'data' => [
                'id' => $plan->id,
                'market' => $plan->market->value,
                'kind' => $plan->kind->value,
                'date' => $plan->drop_date?->toDateString(),
                'title' => $plan->title,
                'status' => $plan->status,
                'queries' => $plan->queries ?? [],
                'curatedCount' => $plan->items()->count(),
                'note' => $plan->note,
            ],
        ], 201);
    }

    /**
     * Lay a season out, or bring it round for the coming window.
     *
     * One endpoint, because they are one editorial event a year apart and
     * `SeasonalSeries::plan()` is the one place that knows which applies. A
     * season that has run before is **renewed** — its parts slide onto the next
     * window and rebuild at the same URLs, keeping the ranking they spent a year
     * earning — and one that has not is laid out fresh.
     */
    public function planSeason(Request $request, GuideTopic $topic): JsonResponse
    {
        $request->validate([]);

        if ($topic->origin !== 'seasonal') {
            throw ValidationException::withMessages([
                'topic' => "Topic {$topic->id} is not a seasonal one — it has no window to lay parts across. "
                    .'Use POST /coves/drafts with kind=guide for a topic from the search log.',
            ]);
        }

        $plans = $this->series->plan($topic);

        return response()->json([
            'count' => count($plans),
            'topic' => $topic->topic,
            'market' => $topic->market,
            /*
             * Zero is a real answer here rather than a failure: a season whose
             * parts are already dated inside the coming window has nothing to
             * move, and saying so beats a caller retrying it every night.
             */
            'message' => $plans === []
                ? 'Nothing to do: this season is already laid out on the coming window.'
                : null,
            'data' => array_map(fn (CovePlan $plan) => [
                'id' => $plan->id,
                'slug' => $plan->slug,
                'title' => $plan->title,
                'due' => $plan->drop_date?->toDateString(),
                'part' => $plan->part,
                'seriesKey' => $plan->series_key,
                'status' => $plan->status,
                'curatedCount' => $plan->items()->count(),
            ], $plans),
        ], $plans === [] ? 200 : 201);
    }
}

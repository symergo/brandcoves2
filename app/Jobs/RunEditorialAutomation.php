<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PlanWriter;
use App\Models\CovePlan;
use App\Services\Cove\EditionBuilder;
use App\Services\Cove\PlanDrafter;
use App\Services\Cove\PlanState;
use App\Services\Curation\PlanCurator;
use App\Services\Settings\AutomationSettingsStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The editorial pipeline, walked once for one market.
 *
 * The same stages an instruction drives, on the scheduler instead. One job per
 * market that walks the enabled stages **in order**, rather than five jobs
 * staggered across the day: staggered stages mean a plan drafted at 03:50 waits
 * until tomorrow to be curated, where a sequential walk takes a plan from
 * nothing to approved in one run.
 *
 * ## What it does not do
 *
 * **It does not publish.** `build` dispatches the same jobs a person's button
 * dispatches, and `buildArticle()` refuses anything that is not approved — so
 * with `approve` off, which is how every non-daily kind ships, this walk can
 * fill the planner and write it and still not put a page in front of anybody.
 *
 * **It does not replace `PublishDueCoves`.** That job honours a scheduled
 * approval on the day it was scheduled for, and carries logic belonging to
 * seasons rather than to automation: `built_for`, the window guard, series
 * ordering on catch-up. The `build` switch gates it; nothing here duplicates it.
 *
 * ## Every run reports
 *
 * A run that silently drafts thirty plans is the failure this whole switchboard
 * has to avoid — so each stage logs what it did, per market, and the counts are
 * the ones a person would check.
 */
class RunEditorialAutomation implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    /** One market's worth of drafting per run. Enough to keep a queue topped up. */
    private const DRAFT_BATCH = 5;

    /** Plans curated or written per run, so one market cannot spend the day's budget. */
    private const WORK_BATCH = 10;

    public function __construct(public Market $market) {}

    public function handle(
        AutomationSettingsStore $settings,
        PlanDrafter $drafter,
        PlanCurator $curator,
        EditionBuilder $builder,
    ): void {
        $did = [];

        foreach (CoveKind::cases() as $kind) {
            if ($settings->enabled('plan', $this->market, $kind)) {
                $did['planned'][$kind->value] = $this->plan($drafter, $kind);
            }

            if ($settings->enabled('curate', $this->market, $kind)) {
                $did['curated'][$kind->value] = $this->curate($curator, $builder, $kind);
            }

            $writer = $settings->writerFor($this->market, $kind);

            if ($writer !== null) {
                $did['marked'][$kind->value] = $this->markWriter($kind, $writer);
            }

            if ($settings->enabled('approve', $this->market, $kind)) {
                $did['approved'][$kind->value] = $this->approve($kind);
            }

            if ($settings->enabled('build', $this->market, $kind)) {
                $did['queued'][$kind->value] = $this->build($kind);
            }
        }

        Log::info('Editorial automation ran', ['market' => $this->market->value, ...$did]);
    }

    /** Top the planner up from the source that knows about this kind. */
    private function plan(PlanDrafter $drafter, CoveKind $kind): int
    {
        if (! $drafter->canDraft($kind)) {
            return 0;
        }

        /*
         * Occasions only, for a Daily.
         *
         * `bc:plan-coves` fills every date, including the ~270 evergreen
         * rotation days. This walk is a queue top-up for a person to curate, and
         * a rotation theme claims nothing about its date — so it fills the days
         * that are actually about something.
         */
        return $drafter->draft(
            $kind,
            $this->market,
            self::DRAFT_BATCH,
            withProducts: true,
            occasionsOnly: $kind === CoveKind::Daily,
        )->count();
    }

    /** Fill the shortlists that are short. */
    private function curate(PlanCurator $curator, EditionBuilder $builder, CoveKind $kind): int
    {
        $filled = 0;

        foreach ($this->plans($kind, PlanState::Draft) as $plan) {
            $want = max(0, $plan->kind->targetItems() - $plan->items()->count());

            if ($want < 1) {
                continue;
            }

            foreach ($builder->candidates($plan, $want) as $group) {
                $curator->add($plan, 'group:'.$group->id);
            }

            $filled++;
        }

        return $filled;
    }

    /**
     * Say who writes the plans that nobody has written yet.
     *
     * This is the whole of the `external` setting: marking a plan `authored`
     * hands it to `GET /coves/queue`, which lists only plans in that state — so
     * the built-in writer and an outside agent can never target the same plan
     * and waste each other's work.
     *
     * `builder` is the default already, so that arm only matters where somebody
     * had switched a market to external and switched it back.
     */
    private function markWriter(CoveKind $kind, PlanWriter $writer): int
    {
        return CovePlan::query()
            ->where('market', $this->market->value)
            ->where('kind', $kind->value)
            ->where('status', 'draft')
            ->where('writer', '!=', $writer->value)
            ->where(fn ($q) => $q->whereNull('editorial')->orWhere('editorial', ''))
            ->limit(self::WORK_BATCH)
            ->update(['writer' => $writer->value]);
    }

    /**
     * Approve what has been written. **The only stage that removes a human.**
     *
     * Ships off for every kind but `daily`, where it is the status quo:
     * `BuildDailyEdition` has always published the column unattended, and an
     * approved plan merely overrides what it would have chosen. For every other
     * kind `buildArticle()` refuses an unapproved plan, so switching this on is
     * a real change in what reaches readers without anybody reading it first.
     */
    private function approve(CoveKind $kind): int
    {
        $approved = 0;

        foreach ($this->plans($kind, PlanState::Written) as $plan) {
            // Never a plan that is marked authored and carries nothing: that is
            // a plan waiting on a writer, not one waiting on a decision.
            if (! $plan->writer->callsModel() && blank($plan->editorial) && blank($plan->body)) {
                continue;
            }

            $plan->update(['status' => 'approved']);
            $approved++;
        }

        return $approved;
    }

    /** Queue the builds for what is approved and has nowhere else to be built from. */
    private function build(CoveKind $kind): int
    {
        $queued = 0;

        foreach ($this->plans($kind, PlanState::Approved) as $plan) {
            /*
             * A dated plan is `PublishDueCoves`'s to honour, on its day.
             *
             * Building it here would publish a seasonal part before its window
             * opens and rebuild a Daily outside its own morning — and two builds
             * of one page race over the same edition row.
             */
            if ($plan->drop_date !== null) {
                continue;
            }

            if (blank($plan->slug)) {
                continue;
            }

            BuildCove::dispatch($plan->id);
            $queued++;
        }

        return $queued;
    }

    /**
     * The plans in one state for this market and kind.
     *
     * `PlanState` is the same vocabulary the planner's tabs and the API's filter
     * read, so what this walk acts on is what a person would see if they opened
     * the screen.
     *
     * @return Collection<int, CovePlan>
     */
    private function plans(CoveKind $kind, PlanState $state)
    {
        return CovePlan::query()
            ->where('market', $this->market->value)
            ->where('kind', $kind->value)
            ->tap(fn ($q) => PlanState::scope($q, $state))
            ->orderByRaw('drop_date is null')
            ->orderBy('drop_date')
            ->limit(self::WORK_BATCH)
            ->get();
    }
}

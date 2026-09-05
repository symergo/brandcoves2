<?php

declare(strict_types=1);

namespace App\Services\Cove;

use App\Models\CovePlan;
use App\Models\DailyPickSet;
use Illuminate\Database\Eloquent\Builder;

/**
 * What state a plan is in, and therefore what to do to it next.
 *
 * One vocabulary, read by three callers: the merged planner screen's tab strip,
 * the `GET /coves?state=` filter, and the skill's selector when an instruction
 * says "the coves for which the products are picked". Two implementations of
 * "curated but not written" would disagree within a month, and the disagreement
 * would look like the API skipping work the panel is showing.
 *
 * ## Why the raw `status` column is not enough
 *
 * `cove_plans.status` has four values — draft, approved, used, rejected — and
 * says nothing about the two questions an editor actually has:
 *
 *   - **has this been curated and written yet?** Both live in other columns.
 *   - **did the build work?** An approved plan whose build skipped on a thin
 *     catalogue is indistinguishable from one that has not been built yet, on
 *     every screen. That is the gap `Thin` closes.
 *
 * ## Derived, never stored
 *
 * A stored state is a state that goes stale the moment somebody edits a row
 * from another screen, and it would need maintaining at nine call sites. These
 * are computed from what is already true.
 */
enum PlanState: string
{
    /** No prose yet. Curate it, write it. */
    case Draft = 'draft';

    /** Prose written, still nobody's decision. Review it. */
    case Written = 'written';

    /** Approved, and the build has not caught up. */
    case Approved = 'approved';

    /**
     * A season part re-dated into the coming window.
     *
     * Not a nicety: it is the state a whole season sits in between somebody
     * sliding its parts onto next year's window and the day each part is
     * honoured by `PublishDueCoves`. Without it that work is invisible on every
     * screen in between.
     */
    case DueAgain = 'due_again';

    /** Live. An edition exists and is published. */
    case Live = 'live';

    /**
     * Approved and built, and the build produced nothing.
     *
     * Almost always a catalogue too thin to clear the kind's floor. It logs a
     * warning at 06:00 and looked exactly like "not built yet" everywhere else,
     * which is the difference between *published* and *nothing happened*.
     */
    case Thin = 'thin';

    /** Rejected, or superseded. Nothing to do. */
    case Archive = 'archive';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Needs writing',
            self::Written => 'Needs review',
            self::Approved => 'Ready to build',
            self::DueAgain => 'Due again',
            self::Live => 'Live',
            self::Thin => 'Too thin to publish',
            self::Archive => 'Archive',
        };
    }

    /** What a person, or Claude, does to a plan in this state. */
    public function nextStage(): ?string
    {
        return match ($this) {
            self::Draft => 'write',
            self::Written => 'approve',
            self::Approved, self::DueAgain => 'build',
            self::Thin => 'curate',
            self::Live, self::Archive => null,
        };
    }

    /**
     * The state this plan is in.
     *
     * Order matters: the tests below are not mutually exclusive on the columns,
     * so the first true one wins and the sequence encodes the precedence. An
     * archived plan is archived whatever else is true of it; a plan that failed
     * to build is Thin even though it is also approved.
     */
    public static function of(CovePlan $plan): self
    {
        if (in_array($plan->status, ['rejected'], true)) {
            return self::Archive;
        }

        if ($plan->status === 'approved' || $plan->status === 'used') {
            /*
             * Built for an earlier date than it is now due on.
             *
             * `built_for` is what lets a season come round: moving `drop_date`
             * into the coming window is the whole of a recurrence, and until
             * `PublishDueCoves` honours it the plan is neither live-for-this-year
             * nor merely approved.
             */
            if ($plan->drop_date !== null
                && $plan->built_for !== null
                && $plan->built_for->lt($plan->drop_date)) {
                return self::DueAgain;
            }

            if ($plan->edition_id !== null) {
                return self::Live;
            }

            /*
             * Approved, the build has run, and there is no edition.
             *
             * `built_for` is stamped by a build that produced a page, so its
             * absence alongside a build attempt is the thin-catalogue case. A
             * plan whose date has not arrived yet is simply Approved.
             */
            if ($plan->last_build_failed_at !== null) {
                return self::Thin;
            }

            return self::Approved;
        }

        return filled($plan->editorial) || filled($plan->body)
            ? self::Written
            : self::Draft;
    }

    /**
     * Narrow a query to one state.
     *
     * Expressed in SQL rather than by filtering a collection, because the
     * planner pages 50 rows at a time out of a table that holds every Cove the
     * site has ever published.
     *
     * @param  Builder<CovePlan>  $query
     */
    public static function scope(Builder $query, self $state): void
    {
        match ($state) {
            self::Archive => $query->where('status', 'rejected'),

            self::Draft => $query
                ->whereIn('status', ['draft'])
                ->where(fn (Builder $q) => $q->whereNull('editorial')->orWhere('editorial', ''))
                ->where(fn (Builder $q) => $q->whereNull('body')->orWhere('body', '')),

            self::Written => $query
                ->whereIn('status', ['draft'])
                ->where(fn (Builder $q) => $q
                    ->where(fn (Builder $w) => $w->whereNotNull('editorial')->where('editorial', '!=', ''))
                    ->orWhere(fn (Builder $w) => $w->whereNotNull('body')->where('body', '!=', ''))),

            self::DueAgain => $query
                ->whereIn('status', ['approved', 'used'])
                ->whereNotNull('drop_date')
                ->whereNotNull('built_for')
                ->whereColumn('built_for', '<', 'drop_date'),

            self::Live => $query
                ->whereIn('status', ['approved', 'used'])
                ->whereNotNull('edition_id')
                ->where(fn (Builder $q) => $q
                    ->whereNull('built_for')
                    ->orWhereNull('drop_date')
                    ->orWhereColumn('built_for', '>=', 'drop_date')),

            self::Thin => $query
                ->whereIn('status', ['approved', 'used'])
                ->whereNull('edition_id')
                ->whereNotNull('last_build_failed_at'),

            self::Approved => $query
                ->whereIn('status', ['approved', 'used'])
                ->whereNull('edition_id')
                ->whereNull('last_build_failed_at'),
        };
    }

    /**
     * How many plans are in each state, for one market or all of them.
     *
     * @return array<string, int>
     */
    public static function counts(?string $market = null, ?string $kind = null): array
    {
        $counts = [];

        foreach (self::cases() as $state) {
            $counts[$state->value] = CovePlan::query()
                ->when($market !== null, fn (Builder $q) => $q->where('market', $market))
                ->when($kind !== null, fn (Builder $q) => $q->where('kind', $kind))
                ->tap(fn (Builder $q) => self::scope($q, $state))
                ->count();
        }

        return $counts;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }

    /**
     * The edition this plan produced, if it produced one.
     *
     * Here rather than on the model because it is only ever asked alongside the
     * state, and `DailyPickSet` is the wrong place to hang a question about a
     * plan.
     */
    public static function editionFor(CovePlan $plan): ?DailyPickSet
    {
        return $plan->edition;
    }
}

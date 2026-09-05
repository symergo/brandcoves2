<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CoveKind;
use App\Enums\CoveScene;
use App\Enums\Market;
use App\Enums\PickMode;
use App\Enums\PlanWriter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A planned Cove — what a day, or a persona, is *for*, decided before it is built.
 *
 * An intention, not an edition. The builder reads it and does the work; the
 * edition is still the thing that gets published. That separation is what lets
 * a plan exist for a date the catalogue later cannot fill without leaving an
 * empty page behind — and what lets a curated shortlist survive a rebuild,
 * because the edition is an output that every rebuild overwrites.
 *
 * Two kinds live here. A Daily Cove has a date; a gift persona has a slug and
 * no date. See App\Enums\CoveKind.
 *
 * @property list<string> $queries
 * @property list<int> $pinned_group_ids
 */
class CovePlan extends Model
{
    protected $guarded = [];

    /**
     * The column default, mirrored in PHP.
     *
     * `cove_plans.kind` defaults to `daily` in the schema, but a model returned
     * by `create()` without one carries a *null* kind until something reloads it
     * — and the kind is now what chooses the selector, so null reached
     * `Selectors::for()` and killed the planner. Everything that reads a kind
     * would otherwise have to null-coalesce, which is the same default written
     * in nine places instead of one.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'kind' => CoveKind::Daily->value,
        // Same reason as `kind` above: a model returned by create() without one
        // carries null until something reloads it, and `writer` is asked three
        // times inside a single build.
        'writer' => PlanWriter::Builder->value,
    ];

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'kind' => CoveKind::class,
            'pick_mode' => PickMode::class,
            // Who writes the prose. Was inferred from which fields happened to
            // be filled, in three places that disagreed. See App\Enums\PlanWriter.
            'writer' => PlanWriter::class,
            // Nullable: null reads as CoveScene::defaultFor($this->kind).
            'scene' => CoveScene::class,
            /*
             * When this plan is due — and on a Daily, also where it is read.
             *
             * Two kinds carry one. On a Daily it is the address: the edition is
             * reached at `/daily/{date}`. On a **seasonal** part it is a due
             * date and nothing more — the published page is slug-addressed and
             * evergreen like every other article, and the date says which day of
             * the season's window this part is scheduled for. See
             * `App\Services\Cove\SeasonalSeries` and the migration that relaxed
             * `cove_plans_dated_kind_check` to allow it.
             */
            'drop_date' => 'date',
            /*
             * The `drop_date` this plan was last built for.
             *
             * `drop_date > built_for` is the whole definition of "due", and it
             * is what lets the calendar repeat: sliding a seasonal part onto next
             * year's window makes it due again, at the same URL, without
             * rewinding its status or clearing anything an editor decided.
             */
            'built_for' => 'date',
            // Which part of a series this is, 1-based. Null unless `series_key`
            // is set; a CHECK constraint keeps the two together.
            'part' => 'integer',
            'queries' => 'array',
            'pinned_group_ids' => 'array',
            // Article kinds only — a guide's FAQ, decided before it is written
            // rather than invented during. See App\Enums\CoveKind.
            'faq' => 'array',
        ];
    }

    /**
     * The plan behind an edition, minting one if nothing planned it.
     *
     * Most Coves were not planned by anybody: the 06:00 job builds a Daily from
     * the theme calendar, and the topic queue used to publish guides on its own.
     * That left the planner able to describe only the future, and the editorial
     * table full of pages with no record of why they exist — so "re-curate this
     * one" was not a thing you could do to most of the site.
     *
     * Called *after* the build has decided its theme, deliberately. A plan's
     * title outranks the calendar in `EditionBuilder::build()`, so minting one
     * beforehand and filling it in later would make every automatic edition look
     * like a planned one and pin tomorrow's theme to today's.
     *
     * ## Why `used` and not `approved`
     *
     * `approvedFor()` matches `approved` only, and it is what decides whether a
     * plan *drives* the next build. A minted plan is a record of what happened,
     * not an instruction — marking it approved would make the machine's own
     * output an editorial decision that the next rebuild obeys.
     *
     * Idempotent: a rebuild finds the existing plan and only re-links it. An
     * existing plan's status is never touched, because a rebuild must not
     * demote the approved plan an author is still working from.
     */
    public static function recordFor(DailyPickSet $edition): self
    {
        $address = $edition->kind === CoveKind::Daily
            ? ['drop_date' => $edition->drop_date?->toDateString()]
            : ['slug' => $edition->slug];

        $plan = static::query()
            ->where('market', $edition->market->value)
            ->where('kind', $edition->kind->value)
            ->where(fn (Builder $q) => $q->where($address))
            ->first();

        if ($plan !== null) {
            // Only the link. See the class note above and the one in
            // EditionBuilder about why the status is left alone.
            $plan->forceFill(['edition_id' => $edition->id])->save();

            return $plan;
        }

        return static::create([
            'market' => $edition->market->value,
            'kind' => $edition->kind->value,
            'title' => $edition->theme_title,
            'blurb' => $edition->theme_blurb,
            'status' => 'used',
            'edition_id' => $edition->id,
            'note' => 'Recorded automatically from the build. Nobody planned this one.',
            ...$address,
        ]);
    }

    /**
     * The curated shortlist, in the curator's order.
     *
     * This replaced `pinned_group_ids`, which could hold ids and nothing else.
     * The column is still written by the legacy API field and still read by
     * nothing — see the migration for the expand/contract.
     *
     * @return HasMany<CovePlanItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CovePlanItem::class, 'plan_id')->ordered();
    }

    /** @return BelongsTo<DailyPickSet, $this> */
    public function edition(): BelongsTo
    {
        return $this->belongsTo(DailyPickSet::class, 'edition_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The plan the builder should use for this date, if any.
     *
     * `approved` only. A draft is someone thinking out loud, and the clock
     * coming round is not a reason to publish it.
     *
     * Personas are excluded by their own CHECK constraint rather than by a
     * clause here — one cannot hold a drop_date at all — but the kind is
     * matched anyway, because a constraint two files away is not something the
     * next reader of this method will know about.
     */
    public static function approvedFor(Market $market, CarbonImmutable $date): ?self
    {
        return static::query()
            ->where('market', $market->value)
            ->where('kind', CoveKind::Daily->value)
            ->where('drop_date', $date->toDateString())
            ->where('status', 'approved')
            ->first();
    }

    /**
     * A persona by its permanent address.
     *
     * Unlike a Daily, a persona is built on demand rather than by the clock, so
     * this is what the build action and the editorial API resolve against.
     */
    public static function persona(Market $market, string $slug): ?self
    {
        return static::query()
            ->where('market', $market->value)
            ->where('kind', CoveKind::Persona->value)
            ->where('slug', $slug)
            ->first();
    }

    /** @param Builder<$this> $query */
    public function scopeQueued(Builder $query): void
    {
        // Undated and approved: ideas waiting for a slot.
        $query->whereNull('drop_date')->where('status', 'approved');
    }

    /** @param Builder<$this> $query */
    public function scopeUpcoming(Builder $query): void
    {
        $query->whereNotNull('drop_date')
            ->whereDate('drop_date', '>=', today())
            ->orderBy('drop_date');
    }

    /** @param Builder<$this> $query */
    public function scopeOfKind(Builder $query, CoveKind $kind): void
    {
        $query->where('kind', $kind->value);
    }

    public function isDaily(): bool
    {
        return $this->kind === CoveKind::Daily;
    }

    public function isPersona(): bool
    {
        return $this->kind === CoveKind::Persona;
    }

    /**
     * Is this plan buildable at all?
     *
     * A locked plan is exactly its shortlist, so a locked plan with fewer
     * products than the edition floor cannot produce a page — and finding that
     * out at 06:00 is the whole thing the curation screen exists to prevent.
     */
    public function isBuildable(): bool
    {
        if ($this->pick_mode === PickMode::Open) {
            return true;
        }

        // The kind's own floor, not the Daily's. A buying guide needs five and
        // an advice article needs none, so judging a guide against the column's
        // minimum called a page unbuildable that would have published fine —
        // and, worse, the other way round.
        return $this->items()->count() >= $this->kind->minimumItems();
    }
}

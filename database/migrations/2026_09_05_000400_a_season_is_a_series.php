<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A seasonal Cove stops being one page and becomes a dated series.
 *
 * A season is months long and has several distinct subjects inside it —
 * "kamperen" is tents *and* sleeping bags *and* camping stoves — and it was
 * being answered with a single page written once, at whatever moment the topic
 * queue happened to reach it. That page is thin against a three-month window and
 * it is invisible in the one place editorial is actually planned: the calendar,
 * which held Dailies and nothing else.
 *
 * So a season is now laid out as parts, one per facet, each with a date inside
 * the window. Three schema facts had to change for that to be expressible.
 *
 * ## 1. A seasonal plan may carry a date
 *
 * `cove_plans_dated_kind_check` said `kind = 'daily' OR drop_date IS NULL`, and
 * it was right for every kind it was written about: a persona and a buying guide
 * never stop being current, so dating one would invite a reader to treat it as
 * stale. A season is the exception the rule was not thinking about — it is
 * *defined* by a date range, and the whole reason it exists is that the search
 * log cannot see a season coming.
 *
 * The date means something slightly different on the two kinds, and that is
 * deliberate rather than sloppy. On a Daily it is the **address**: the edition
 * is reached at `/daily/{date}`. On a seasonal part it is the **due date**: when
 * the approved plan should be built and published. The published page is still
 * slug-addressed and still evergreen — `daily_pick_sets_address_check` is
 * untouched, so no seasonal *edition* gains a date and nothing about how a
 * reader reaches one changes. What both kinds share is the sentence the planner
 * screen is sorted by: "this is the day this plan is due".
 *
 * ## 2. One *Daily* per day, not one plan per day
 *
 * `cove_plans_market_date_idx` was unique on `(market, drop_date)`, described as
 * "two plans for one Tuesday is an editorial argument the builder cannot
 * settle". That argument is real and is about the Daily: only one edition can be
 * reached at `/daily/2026-04-12`, so a second dated Daily plan is a conflict
 * with no honest resolution. A seasonal part is reached by its slug and is not
 * competing for that address, so the index is narrowed to what it always meant.
 *
 * Narrowed rather than dropped: without it two Daily plans for one date insert
 * happily and the builder picks by row order.
 *
 * ## 3. A part knows which series it belongs to
 *
 * `series_key` and `part`, rather than parsing "deel 2" back out of a slug or a
 * note. The planner already carries one marker-in-a-note — the drafted persona's
 * interest — and that is a documented workaround for a fact that had no column;
 * it survives renaming precisely because renaming the title is the workflow. A
 * series is not that: the reader-facing page asks "which part am I on and where
 * are the others", every part's slug is renameable, and `PlanSlugs` suffixes on
 * collision, so a slug is exactly the wrong thing to derive identity from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cove_plans', function (Blueprint $table) {
            /*
             * The season this part belongs to — the topic word, not the slug.
             *
             * Scoped to the market alongside it: `product_groups` is unique on
             * (market, identity_key) for the same reason, and a Dutch kamperen
             * series and a French one are two different series of pages about
             * two different catalogues.
             */
            $table->string('series_key', 64)->nullable();

            // 1-based, and it is what the title and the URL say. Stored rather
            // than derived from the date order so that moving a part's date —
            // which is the point of putting it on a calendar an editor can edit
            // — does not silently renumber the published pages.
            $table->smallInteger('part')->nullable();
        });

        DB::statement(
            'ALTER TABLE cove_plans DROP CONSTRAINT IF EXISTS cove_plans_dated_kind_check'
        );
        DB::statement(
            'ALTER TABLE cove_plans ADD CONSTRAINT cove_plans_dated_kind_check '.
            "CHECK (kind IN ('daily', 'seasonal') OR drop_date IS NULL)"
        );

        /*
         * The two travel together or not at all.
         *
         * A `part` with no `series_key` is a page that claims to be part two of
         * nothing, and a `series_key` with no `part` cannot be ordered. Both
         * read as data corruption long after the code that wrote them is gone.
         */
        DB::statement(
            'ALTER TABLE cove_plans ADD CONSTRAINT cove_plans_series_pair_check '.
            'CHECK ((series_key IS NULL) = (part IS NULL))'
        );

        DB::statement('DROP INDEX IF EXISTS cove_plans_market_date_idx');
        DB::statement(
            'CREATE UNIQUE INDEX cove_plans_market_date_idx ON cove_plans (market, drop_date) '.
            "WHERE drop_date IS NOT NULL AND kind = 'daily'"
        );

        /*
         * One part number per series per market.
         *
         * Laying a series out is idempotent and re-runnable, and the failure it
         * protects against is the quiet one: a second run inserting a second
         * "part 2" that nothing ever shows next to the first.
         */
        DB::statement(
            'CREATE UNIQUE INDEX cove_plans_series_part_idx ON cove_plans (market, series_key, part) '.
            'WHERE series_key IS NOT NULL'
        );
    }

    public function down(): void
    {
        $dated = DB::table('cove_plans')
            ->where('kind', 'seasonal')
            ->whereNotNull('drop_date')
            ->count();

        if ($dated > 0) {
            /*
             * Refuse rather than corrupt. Restoring the old CHECK would fail on
             * these rows anyway, and the tempting fix — null their dates — turns
             * a planned series back into a pile of undated plans with "deel 3"
             * in the title and no record of when any of it was meant to run.
             */
            throw new RuntimeException(
                "Cannot roll back: {$dated} dated seasonal plan(s) exist. Clear their dates deliberately first."
            );
        }

        DB::statement('DROP INDEX IF EXISTS cove_plans_series_part_idx');
        DB::statement('DROP INDEX IF EXISTS cove_plans_market_date_idx');
        DB::statement(
            'CREATE UNIQUE INDEX cove_plans_market_date_idx ON cove_plans (market, drop_date) '.
            'WHERE drop_date IS NOT NULL'
        );

        DB::statement('ALTER TABLE cove_plans DROP CONSTRAINT IF EXISTS cove_plans_series_pair_check');
        DB::statement('ALTER TABLE cove_plans DROP CONSTRAINT IF EXISTS cove_plans_dated_kind_check');
        DB::statement(
            'ALTER TABLE cove_plans ADD CONSTRAINT cove_plans_dated_kind_check '.
            "CHECK (kind = 'daily' OR drop_date IS NULL)"
        );

        Schema::table('cove_plans', function (Blueprint $table) {
            $table->dropColumn(['series_key', 'part']);
        });
    }
};

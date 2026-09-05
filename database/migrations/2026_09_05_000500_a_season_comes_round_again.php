<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A plan records which date it was last built for, so a season can come round.
 *
 * `PublishDueCoves` asked "is this plan approved, due, and **never built**",
 * which was the right question for a series laid out once. It is the wrong one
 * for a calendar that repeats: the camping window opens every March, and a page
 * that has been built once can never be due again.
 *
 * The fix is not a second date column. `drop_date` already says when the plan is
 * due; what was missing is when it was last *honoured*, and comparing the two is
 * the whole test:
 *
 *     due  =  approved  AND  drop_date <= today
 *                       AND (built_for IS NULL OR built_for < drop_date)
 *
 * Moving `drop_date` into next year's window is therefore all a recurrence has
 * to do. Nothing is cleared, no status is rewound, and the plan stays exactly
 * the curated, approved thing an editor made — which is the point, because the
 * page it rebuilds keeps its URL, its `published_at` and the ranking it has
 * spent a year earning.
 *
 * It also replaces `edition_id IS NULL` with something that says what it means.
 * That test was standing in for "not built yet" and quietly also meant "never
 * buildable again"; a reader had to know the second half was load-bearing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cove_plans', function (Blueprint $table) {
            /*
             * The `drop_date` this plan was last built for.
             *
             * Null on a plan that has never been built, and on every plan that
             * has no date at all — a persona and a buying guide are built on
             * demand and there is no schedule for them to be behind.
             */
            $table->date('built_for')->nullable();
        });

        /*
         * Everything already built is up to date as of the date it was built
         * for.
         *
         * Without this every dated plan in the table reads as "due and never
         * built" the first time the job runs after this deploy, and a season
         * that published in April would rebuild in full — new products, newly
         * written prose — for no reason anybody could see.
         */
        DB::table('cove_plans')
            ->whereNotNull('edition_id')
            ->whereNotNull('drop_date')
            ->update(['built_for' => DB::raw('drop_date')]);
    }

    public function down(): void
    {
        Schema::table('cove_plans', function (Blueprint $table) {
            $table->dropColumn('built_for');
        });
    }
};

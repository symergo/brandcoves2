<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The contract half of the guide fold.
 *
 * `2026_08_30_000100_a_guide_is_a_cove` moved every guide into
 * `daily_pick_sets` and every `guide_items` row into `daily_picks`, and
 * deliberately left the source tables standing: shipping the drop alongside the
 * expand would destroy the rows in the same deploy that copies them, which is
 * the failure expand/contract exists to prevent. `cove-planner.md` said "drop
 * them a release later".
 *
 * They outlived that by longer than intended, because three readers were still
 * pointing at them and only one was obvious:
 *
 *   - `CuratedRetriever` joined `guide_items` on the **live Discover surface**.
 *     Its other half already read `daily_pick_sets` — but filtered on
 *     `drop_date >= …`, and an article has no date, so the folded guides were
 *     excluded from the new query and the old join was quietly carrying them.
 *     Two shapes for one thing, and only one of them was folded.
 *   - `EditionController` rendered `$edition->guide` in the API read-back.
 *   - `BuildDailyEdition` logged `guide_id !== null`.
 *
 * All three now read `featured_cove_id`, or the folded rows directly. Nothing in
 * `app/` mentions either table.
 *
 * ## What goes, and what is deliberately kept
 *
 * `guides` and `guide_items` go. `daily_pick_sets.guide_id` and
 * `guide_topics.guide_id` go with them — both are FKs into a table that is
 * about to stop existing, and nothing has written either since the fold.
 *
 * **`folded_from_guide_id` stays.** It is the provenance record: which old guide
 * each Cove came from. It points at nothing now, and that is fine — its value is
 * as an answer to "where did this page come from", asked by a person reading a
 * row years later. Dropping it would delete the only evidence the fold happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Never before the fold has run.
         *
         * On a database where `GuideFold` has not executed, these tables still
         * hold the only copy of every guide — and dropping them would delete
         * published pages rather than tidy up after them. The fold runs from its
         * own migration, so in practice this cannot happen; the guard is here
         * because "in practice" is not a guarantee worth a corpus of articles.
         */
        if (Schema::hasTable('guides')) {
            $unfolded = DB::table('guides')
                ->whereNotExists(fn ($q) => $q
                    ->select(DB::raw(1))
                    ->from('daily_pick_sets')
                    ->whereColumn('daily_pick_sets.folded_from_guide_id', 'guides.id'))
                ->count();

            if ($unfolded > 0) {
                throw new RuntimeException(
                    "{$unfolded} guide(s) have no folded Cove. Run the fold before dropping the tables: "
                    .'these rows are the only copy of those pages.'
                );
            }
        }

        foreach ([['daily_pick_sets', 'guide_id'], ['guide_topics', 'guide_id']] as [$table, $column]) {
            if (! Schema::hasColumn($table, $column)) {
                continue;
            }

            /*
             * The constraint by name, then the column.
             *
             * Postgres refuses to drop a column while a foreign key naming it
             * stands, and `dropForeign(['guide_id'])` guesses the constraint
             * name from the table and column — which is the convention these
             * were created under. `IF EXISTS` because a database restored from a
             * dump taken after a manual tidy may not have it.
             */
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_{$column}_foreign");

            Schema::table($table, fn (Blueprint $t) => $t->dropColumn($column));
        }

        Schema::dropIfExists('guide_items');
        Schema::dropIfExists('guides');
    }

    /**
     * Forward-only, like every migration here.
     *
     * There is no honest `down()`: the rows were folded a release ago and the
     * originals have been unread since. Recreating empty tables would restore
     * the schema and none of the data, which is worse than refusing — a rollback
     * that appears to work and leaves a `CuratedRetriever` joining nothing is a
     * silently empty Discover surface.
     */
    public function down(): void
    {
        throw new RuntimeException(
            'Forward-only. The guides tables were folded into daily_pick_sets a release ago; '
            .'restoring the schema without the rows would leave every reader joining nothing.'
        );
    }
};

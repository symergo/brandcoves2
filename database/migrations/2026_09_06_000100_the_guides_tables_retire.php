<?php

declare(strict_types=1);

use App\Services\Content\GuideFold;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The second half of the guide fold: the old tables go.
 *
 * `2026_08_30_000100_a_guide_is_a_cove` copied every guide into
 * `daily_pick_sets` and every `guide_items` row into `daily_picks`, and left the
 * source tables standing on purpose — destroying the originals in the same
 * deploy that copies them is the failure that expand/contract exists to prevent.
 *
 * Nothing in `app/` reads either table now. The last three readers
 * (`CuratedRetriever` on the live Discover surface, `EditionController`'s
 * read-back, and a log line in `BuildDailyEdition`) were repointed at
 * `featured_cove_id` or at the folded rows.
 *
 * ## This one folds before it drops, and never refuses
 *
 * The first attempt at this migration guarded instead: it counted guides with no
 * folded Cove and threw if any remained. The guard was correct and it cost 55
 * minutes of downtime, because on this deployment the old containers are already
 * gone by the time migrations run — so a migration that throws is not a blocked
 * deploy, it is an outage.
 *
 * It found 65 leftovers, because the August fold had hit a precondition, done
 * nothing, and returned a report that read exactly like a successful fold of an
 * empty table. So this migration repairs the precondition rather than reporting
 * it: it runs the fold itself, immediately before dropping.
 *
 * ## What it moves, and what it lets go
 *
 * Only guides that have an article in them — `GuideFold::hasAnArticle()` carries
 * the evidence for that line. In short: on production all 61 published leftovers
 * had an empty `body_md` and a title assembled from one mined search word, some
 * of them in the wrong language for their own market ("The best hoofdtelefoons"
 * on `/en`). They were 404 for a week and nobody reported it. Restoring them
 * would publish 61 pages that were never worth serving; the four drafts, ~2,000
 * characters of hand-written advice each, are worth moving.
 *
 * What it abandons is logged with its slug, so the decision leaves a trace
 * rather than only a smaller table.
 *
 * ## What goes, and what is kept
 *
 * `guides` and `guide_items` go, and `daily_pick_sets.guide_id` /
 * `guide_topics.guide_id` go with them — both point into a table that is about
 * to stop existing, and nothing has written either since the fold.
 *
 * **`folded_from_guide_id` stays.** It records which old guide each Cove came
 * from. It points at nothing now, and that is the point: it is the only evidence
 * left that the fold happened at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('guides')) {
            $this->foldWhatIsWorthKeeping();
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
     * Move anything somebody actually wrote, and say what was left behind.
     *
     * Wrapped so that a failure here cannot take the deploy down with it. That
     * is a deliberate trade and it goes the way it does because of what is at
     * stake on each side: a fold that fails leaves a handful of draft articles
     * recoverable from the nightly dump, while a migration that throws stops
     * every container from starting. The first is a bad afternoon, the second is
     * the whole site.
     */
    private function foldWhatIsWorthKeeping(): void
    {
        try {
            $abandoned = DB::table('guides')
                ->whereRaw("coalesce(body_md, '') = ''")
                ->whereNotExists(fn ($q) => $q
                    ->select(DB::raw(1))
                    ->from('daily_pick_sets')
                    ->whereColumn('daily_pick_sets.folded_from_guide_id', 'guides.id'))
                ->get(['market', 'slug', 'status']);

            $report = app(GuideFold::class)->run(GuideFold::hasAnArticle());

            if ($report['did_nothing'] !== null) {
                // The exact condition that cost a week the first time. It is a
                // warning rather than a throw, but it is no longer silent.
                Log::warning('Guides retire: the fold did nothing', ['reason' => $report['did_nothing']]);

                return;
            }

            Log::info('Guides retire: folded what was left', [
                'folded' => $report['editions'],
                'picks' => $report['picks'],
                'already_had_a_cove' => $report['skipped'],
                'renamed' => $report['renamed'],
                'abandoned' => $abandoned
                    ->map(fn ($row) => "{$row->market}/{$row->slug} ({$row->status})")
                    ->all(),
            ]);
        } catch (Throwable $e) {
            Log::error('Guides retire: the fold failed; dropping anyway', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Forward-only, like every migration here.
     *
     * There is no honest `down()`: the rows were folded a release ago and the
     * originals have been unread since. Recreating empty tables would restore
     * the schema and none of the data, which is worse than refusing — a rollback
     * that appears to work and leaves readers joining nothing is a silently
     * empty surface.
     */
    public function down(): void
    {
        throw new RuntimeException(
            'Forward-only. The guides tables were folded into daily_pick_sets a release ago; '
            .'restoring the schema without the rows would leave every reader joining nothing.'
        );
    }
};

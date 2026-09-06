<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Content\GuideFold;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move any guide the fold left behind into the editorial table.
 *
 * The fold is a migration step and migrations run once. When the one on
 * 2026-08-30 silently did nothing on production — see `GuideFold::run()` — there
 * was no way to run it again short of editing the `migrations` table, and the
 * damage was invisible: `guides` still held every article, `/guides` reads
 * `daily_pick_sets` and served none of them, and the migration was recorded as
 * successful. 61 published buying guides across five markets were absent for a
 * week.
 *
 * So the move gets a front door. Idempotent, dry by default, and it reports what
 * it found rather than what it attempted.
 */
class FoldGuidesCommand extends Command
{
    protected $signature = 'bc:fold-guides
        {--write : Actually fold. Without this it only reports.}
        {--all : Fold every leftover guide, including the ones with no article in them.}';

    protected $description = 'Fold any leftover `guides` rows into daily_pick_sets, and say what is outstanding';

    public function handle(GuideFold $fold): int
    {
        if (! Schema::hasTable('guides')) {
            $this->info('No `guides` table on this database — the contract migration has already run.');

            return self::SUCCESS;
        }

        $outstanding = $this->outstanding();

        $this->table(
            ['status', 'has an article', 'guides with no folded Cove'],
            collect($outstanding)
                ->map(fn ($row) => [$row->status, $row->has_article ? 'yes' : 'no', $row->count])
                ->all(),
        );

        $toFold = $this->count($outstanding, written: true);
        $abandoned = $this->count($outstanding, written: false);

        if ($abandoned > 0) {
            /*
             * Named rather than folded. These are the templated shells the
             * broken fold left behind — a mined search token in a title and no
             * prose at all. `GuideFold::hasAnArticle()` carries the evidence.
             */
            $this->line("{$abandoned} guide(s) have no article in them and are left where they are. --all overrides that.");
        }

        if ($this->option('all')) {
            $toFold += $abandoned;
            $abandoned = 0;
        }

        if ($toFold === 0) {
            $this->info('Nothing left to fold.');

            return self::SUCCESS;
        }

        if (! $this->option('write')) {
            $this->newLine();
            $this->warn("{$toFold} guide(s) would be folded. Re-run with --write to do it.");
            $this->line('Published ones are pages a reader can reach today only if a Cove exists at their slug.');

            return self::SUCCESS;
        }

        $report = $fold->run($this->option('all') ? null : GuideFold::hasAnArticle());

        /*
         * The reason, when there is one.
         *
         * This is the whole point of the command: a fold that does nothing now
         * says why, where the migration's version returned an empty report that
         * read exactly like a successful fold of an empty table.
         */
        if ($report['did_nothing'] !== null) {
            $this->error('The fold did nothing: '.$report['did_nothing']);

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Folded %d guide(s) with %d pick(s); %d already had a Cove.',
            $report['editions'],
            $report['picks'],
            $report['skipped'],
        ));

        if ($report['renamed'] !== []) {
            // A slug the fold had to suffix because something else already held
            // it. Worth naming: those pages are reachable at an address nobody
            // linked to.
            $this->warn('Renamed to avoid a slug clash: '.implode(', ', $report['renamed']));
        }

        $left = $this->count($this->outstanding(), written: true);

        if (! $this->option('all') && $left > 0) {
            $this->error("{$left} guide(s) with an article still have no folded Cove. Something is refusing them.");

            return self::FAILURE;
        }

        $this->info('Every guide worth moving now has a Cove.');

        return self::SUCCESS;
    }

    /**
     * Guides with nothing folded from them, by status and by whether anybody
     * wrote them.
     *
     * The same question the contract migration asks, so "is it safe to drop the
     * tables" and "is anything missing from the site" have one answer rather
     * than two that can disagree.
     *
     * @return list<object>
     */
    private function outstanding(): array
    {
        return DB::table('guides')
            ->selectRaw("status, coalesce(body_md, '') <> '' as has_article, count(*) as count")
            ->whereNotExists(fn ($q) => $q
                ->select(DB::raw(1))
                ->from('daily_pick_sets')
                ->whereColumn('daily_pick_sets.folded_from_guide_id', 'guides.id'))
            ->groupBy('status', 'has_article')
            ->orderBy('status')
            ->get()
            ->all();
    }

    /** @param list<object> $rows */
    private function count(array $rows, bool $written): int
    {
        return array_sum(array_map(
            fn ($row) => (bool) $row->has_article === $written ? (int) $row->count : 0,
            $rows,
        ));
    }
}

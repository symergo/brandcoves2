<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Enums\CoveKind;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves every row in `guides` into the editorial table, once.
 *
 * ## Why this is a service and not twenty lines inside the migration
 *
 * It is the only part of the fold that can lose something. Column additions
 * either apply or fail loudly; a data move can succeed while quietly dropping a
 * paragraph, and nobody finds out until a reader opens a guide that used to have
 * an argument in it. So it has to be testable against real rows, and a migration
 * body cannot be — by the time a test has data to fold, the migration has run.
 *
 * ## Idempotent, deliberately
 *
 * `daily_pick_sets.folded_from_guide_id` records where each folded row came
 * from, so running this twice is a no-op rather than a second copy of every
 * guide on the site. That column is the map: it is what lets a test assert
 * "every guide has exactly one edition, and it kept its prose", and it is
 * dropped with `guides` itself once nothing reads either.
 *
 * It carries no foreign key. A constraint pointing at a table this change exists
 * to delete would have to be dropped before the drop anyway, and the column
 * outliving its target by a single migration is the whole point of
 * expand/contract.
 */
class GuideFold
{
    /**
     * Move every guide into the editorial table.
     *
     * Idempotent: a guide that already has a folded Cove is skipped, so this can
     * be re-run after a partial move or after one that did nothing at all.
     *
     * ## It used to be able to do nothing and say so to nobody
     *
     * The two preconditions below returned an **empty report**, which inside a
     * migration body is indistinguishable from a successful fold of an empty
     * table. On production that is exactly what happened: the migration recorded
     * itself as run, and 61 published buying guides stayed in `guides` while
     * `/guides` — which reads `daily_pick_sets` now — served only the advice
     * articles. For a week, and nothing anywhere said so.
     *
     * So the reason travels in the report. A caller that finds `did_nothing`
     * populated has been told, rather than having to infer it from a zero.
     *
     * ## Not every guide is worth moving
     *
     * `$only` narrows the set. It exists because the fold's own failure proved
     * something about the rows it was meant to save: see `hasAnArticle()`.
     * Passing nothing moves everything, which is what the tests assert and what
     * the original migration intended.
     *
     * @param  (Closure(Builder): void)|null  $only  narrows which guides are moved
     * @return array{editions: int, picks: int, skipped: int, renamed: list<string>, did_nothing: string|null}
     */
    public function run(?Closure $only = null): array
    {
        $report = ['editions' => 0, 'picks' => 0, 'skipped' => 0, 'renamed' => [], 'did_nothing' => null];

        if (! Schema::hasTable('guides')) {
            // Already contracted, or a database that never had one. Both are
            // fine and both mean there is nothing here to move.
            $report['did_nothing'] = 'There is no `guides` table on this database, so there is nothing to fold.';

            return $report;
        }

        if (! Schema::hasColumn('daily_pick_sets', 'folded_from_guide_id')) {
            /*
             * The condition that fired on production and cost a week.
             *
             * The column is added by the same migration that calls this, a
             * hundred lines earlier — so a false here means the schema this
             * process is reading is not the schema it just wrote, which is a
             * cached column listing rather than a missing column.
             */
            $report['did_nothing'] = 'daily_pick_sets.folded_from_guide_id is not visible to this process. '
                .'The column is added by the same migration that calls this, so if it exists in the database '
                .'this is a stale schema cache: clear it and run the fold again.';

            return $report;
        }

        $query = DB::table('guides')
            ->leftJoin('guide_topics', 'guide_topics.guide_id', '=', 'guides.id')
            ->orderBy('guides.id');

        if ($only !== null) {
            $only($query);
        }

        $guides = $query
            ->get([
                'guides.*',
                'guide_topics.origin as topic_origin',
                'guide_topics.season_from as topic_season_from',
                'guide_topics.season_to as topic_season_to',
                'guide_topics.id as topic_id',
            ]);

        foreach ($guides as $guide) {
            $guideId = (int) $guide->id;

            $existing = DB::table('daily_pick_sets')
                ->where('folded_from_guide_id', $guideId)
                ->value('id');

            if ($existing !== null) {
                $report['skipped']++;

                continue;
            }

            $editionId = $this->fold($guide, $report);
            $report['editions']++;
            $report['picks'] += $this->foldItems($guideId, $editionId);

            if ($guide->topic_id !== null) {
                DB::table('guide_topics')
                    ->where('id', $guide->topic_id)
                    ->update(['edition_id' => $editionId]);
            }

            /*
             * The Daily's "read this guide next" link, now a self-reference.
             *
             * Repointed per guide rather than in one pass at the end, so a run
             * that dies halfway leaves every edition it did fold fully wired
             * rather than a table of half-linked rows.
             */
            DB::table('daily_pick_sets')
                ->where('guide_id', $guideId)
                ->update(['featured_cove_id' => $editionId]);
        }

        return $report;
    }

    /**
     * Guides that have an article in them.
     *
     * ## Measured, not guessed
     *
     * When the fold silently did nothing on production it left 65 guides
     * behind, and reading them settled what they were worth. All 61 *published*
     * ones had `body_md` of length **zero** — a title built from one mined
     * search token over a list of products, and no prose at all. Several were
     * titled in the wrong language for their own market: `/en` carried "The best
     * hoofdtelefoons", `/be-fr` carried "Les meilleurs kamperen".
     *
     * The four drafts were the opposite: ~2,000 characters of hand-written
     * advice in three languages.
     *
     * So the fold's failure un-published 61 pages that should never have been
     * published, and restoring them would put a Dutch headline back on the
     * English market. This scope moves what somebody wrote and abandons what a
     * template emitted. The gap is total — 0 characters against 137 and up — so
     * it needs no threshold to argue about, only the presence of a body.
     */
    public static function hasAnArticle(): Closure
    {
        return static fn (Builder $query) => $query->whereRaw("coalesce(guides.body_md, '') <> ''");
    }

    /**
     * @param  array{editions: int, picks: int, skipped: int, renamed: list<string>}  $report
     */
    private function fold(object $guide, array &$report): int
    {
        /*
         * A guide that exists because of a season is planned as one from now on.
         *
         * The distinction was never on the guide itself — it lived on the topic
         * that commissioned it — so this is the one moment it can be recovered.
         * After the topic queue stops publishing, nothing else knows.
         */
        $kind = match (true) {
            $guide->topic_origin === 'seasonal' => CoveKind::Seasonal,
            $guide->kind === 'advice' => CoveKind::Advice,
            default => CoveKind::Guide,
        };

        $slug = $this->freeSlug((string) $guide->market, (string) $guide->slug);

        if ($slug !== $guide->slug) {
            $report['renamed'][] = $guide->market.'/'.$guide->slug.' → '.$slug;
        }

        return DB::table('daily_pick_sets')->insertGetId([
            'market' => $guide->market,
            'kind' => $kind->value,
            'drop_date' => null,
            'slug' => $slug,
            'theme_title' => $guide->title,
            'theme_blurb' => $guide->intro,
            // An output everywhere else, and here just a record of the slug this
            // row was folded under.
            'theme_slug' => $slug,
            // Not 'ai'. The prose was written by a model, but this column says
            // where *this row* came from, and the honest answer is a migration.
            'theme_source' => 'imported',
            'body' => $guide->body_md,
            'faq' => $guide->faq,
            'meta_description' => $guide->meta_description,
            'focus_keyphrase' => $guide->focus_keyphrase,
            'source_queries' => $guide->source_queries,
            'source_volume' => $guide->source_volume,
            'status' => $guide->status,
            'published_at' => $guide->published_at,
            'last_checked_at' => $guide->last_checked_at,
            'season_from' => $guide->topic_season_from,
            'season_to' => $guide->topic_season_to,
            'folded_from_guide_id' => $guide->id,
            'created_at' => $guide->created_at,
            'updated_at' => $guide->updated_at,
        ]);
    }

    /**
     * The shortlist, in one statement.
     *
     * `editorial_copy` becomes `blurb` — the same thing under two names, a
     * sentence about one product written by whoever wrote the article.
     * `surprise_score` stays null: a guide's shortlist was never scored for
     * surprise, and a zero would read as "scored, and boring".
     *
     * The join to `product_groups` is inner on purpose. `guide_items.group_id`
     * cascades, so a row here always has a group; if that ever stops being true
     * the item is not silently written with a broken slug.
     */
    private function foldItems(int $guideId, int $editionId): int
    {
        DB::insert(
            'INSERT INTO daily_picks '.
            '(set_id, group_id, rank, slug, blurb, verdict, unavailable, created_at, updated_at) '.
            "SELECT ?, gi.group_id, gi.rank, pg.slug || '-' || gi.group_id, ".
            'gi.editorial_copy, gi.verdict, gi.unavailable, gi.created_at, gi.updated_at '.
            'FROM guide_items gi JOIN product_groups pg ON pg.id = gi.group_id '.
            'WHERE gi.guide_id = ? ORDER BY gi.rank',
            [$editionId, $guideId],
        );

        // Counted from the destination rather than the source, so the number
        // reported is what actually landed.
        return DB::table('daily_picks')->where('set_id', $editionId)->count();
    }

    /**
     * A slug nothing in this market has taken.
     *
     * Suffixed rather than skipped: a guide whose slug is already a persona's is
     * a published page, and `ON CONFLICT DO NOTHING` would answer that by
     * deleting it. Numbered rather than assumed unique on the first try, because
     * `-guide` could itself already exist.
     */
    private function freeSlug(string $market, string $slug): string
    {
        $taken = fn (string $candidate): bool => DB::table('daily_pick_sets')
            ->where('market', $market)
            ->where('slug', $candidate)
            ->exists();

        if (! $taken($slug)) {
            return $slug;
        }

        $candidate = $slug.'-guide';
        $n = 2;

        while ($taken($candidate)) {
            $candidate = $slug.'-guide-'.$n++;
        }

        return $candidate;
    }
}

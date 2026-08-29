<?php

declare(strict_types=1);

use App\Enums\CoveKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A Daily Cove is addressed by what it is about, not by the day it fell on.
 *
 * `/be-nl/daily/2026-08-29` tells a reader nothing and tells a search engine
 * less. The page is "Vondsten voor thuiswerkers"; the date is when it happened.
 * Every other kind of Cove has been addressed by a slug since the fold, and the
 * Daily was the last one still wearing its filing system in public.
 *
 * ## The date does not go away
 *
 * It stays as data, and it is still what makes a Daily a Daily: one per market
 * per day, ordered by it, archived by it, and `/daily` with no segment still
 * resolves to today's. Only the URL changes.
 *
 * ## Set once
 *
 * `theme_slug` looks like the obvious source and is the wrong column: it is
 * regenerated on every rebuild, and a URL that changes when somebody presses
 * "rebuild" is not a URL. `slug` is assigned once, here or on first build, and
 * never rewritten — the same rule a persona has always had.
 *
 * ## The old URLs still work
 *
 * They are indexed and linked from three months of digest emails, so
 * `/daily/{date}` becomes a 301 to the named URL rather than a 404. See
 * `DailyCoveController`.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Every Cove has a slug; only a Daily also has a date.
         *
         * The previous rule made the two mutually exclusive, which was true
         * while a Daily was addressed by its date and is now exactly backwards.
         */
        DB::statement('ALTER TABLE daily_pick_sets DROP CONSTRAINT IF EXISTS daily_pick_sets_address_check');

        $this->backfill();

        DB::statement(
            'ALTER TABLE daily_pick_sets ADD CONSTRAINT daily_pick_sets_address_check CHECK ('.
            'slug IS NOT NULL AND ('.
            "(kind = 'daily' AND drop_date IS NOT NULL)".
            " OR (kind <> 'daily' AND drop_date IS NULL)))"
        );
    }

    /**
     * Name every edition that has not got one.
     *
     * From the **title**, because that is what the page is called in the
     * language it is read in — "Rond de tafel" gives `/daily/rond-de-tafel`.
     * `theme_slug` is the rotation's internal key and is English in every
     * market (`theme-board-games`), which is a filing reference wearing a URL.
     *
     * Falls back to it, and then to the date, only when a title produces nothing
     * sluggable: a URL that cannot be built is worse than an ugly one.
     */
    private function backfill(): void
    {
        $taken = DB::table('daily_pick_sets')
            ->whereNotNull('slug')
            ->get(['market', 'slug'])
            ->groupBy('market')
            ->map(fn ($rows) => $rows->pluck('slug')->flip()->all())
            ->all();

        DB::table('daily_pick_sets')
            ->where('kind', CoveKind::Daily->value)
            ->whereNull('slug')
            ->orderBy('id')
            ->get(['id', 'market', 'theme_title', 'theme_slug', 'drop_date'])
            ->each(function (object $row) use (&$taken): void {
                $base = Str::slug((string) $row->theme_title)
                    ?: Str::slug((string) $row->theme_slug)
                    ?: 'cove-'.$row->drop_date;

                $market = (string) $row->market;
                $slug = $base;
                $n = 2;

                /*
                 * A theme recurs — "moederdag" comes round every year — and the
                 * slug namespace is unique per market across every kind. So a
                 * collision here is the normal case, not the exception.
                 */
                while (isset($taken[$market][$slug])) {
                    $slug = $base.'-'.$n++;
                }

                $taken[$market][$slug] = true;

                DB::table('daily_pick_sets')->where('id', $row->id)->update(['slug' => $slug]);
            });
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE daily_pick_sets DROP CONSTRAINT IF EXISTS daily_pick_sets_address_check');

        DB::table('daily_pick_sets')->where('kind', CoveKind::Daily->value)->update(['slug' => null]);

        DB::statement(
            'ALTER TABLE daily_pick_sets ADD CONSTRAINT daily_pick_sets_address_check CHECK ('.
            "(kind = 'daily' AND drop_date IS NOT NULL AND slug IS NULL)".
            " OR (kind <> 'daily' AND drop_date IS NULL AND slug IS NOT NULL))"
        );
    }
};

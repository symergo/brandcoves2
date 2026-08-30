<?php

declare(strict_types=1);

use App\Enums\CoveKind;
use App\Services\Content\AdviceCoveSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Eight advice articles, in four markets, from three retired WordPress sites.
 *
 * `bstore.be`, `bstore.nl` and `webprice.eu` carry about 280 pages of Amazon
 * and online-shopping writing going back to 2016. This migration lands what is
 * worth keeping — rewritten from scratch, market by market — as `advice` Coves
 * at `/{market}/guides/{slug}`.
 *
 * The prose lives in `resources/content/advice-coves.php`, and the work is done
 * by {@see AdviceCoveSeeder} rather than by this file. A migration body cannot
 * be tested: by the time a test has Coves to overwrite, the migration has
 * already run. Same split, and same reason, as `GuideFold`.
 *
 * ## Why a migration and not just the command
 *
 * Both exist. `migrate` runs as a one-shot service before `app`, `queue` and
 * `scheduler` start, so this is what puts the articles on production on the
 * next deploy with nobody logging in to run anything. `bc:seed-advice-coves` is
 * for afterwards — edit the file, re-run, and only the rows nobody has touched
 * are refreshed.
 *
 * ## Schema
 *
 * None. This migration adds no column and changes no constraint; it moves
 * content into tables that already hold exactly this shape of row. It is listed
 * as a migration because that is this project's mechanism for "happens once,
 * on the way in, on every environment".
 *
 * ## Rolling back
 *
 * `down()` removes only rows that still carry `editorial_source = 'seed'` and
 * the plans minted for them. An article somebody has since edited is left
 * standing, because the alternative is a rollback that destroys editorial work
 * — and a rollback is exactly the moment nobody is watching for that.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Not in the test suite, and this is the one decision in this file
         * worth arguing about.
         *
         * `RefreshDatabase` migrates and then opens its transaction, so seeding
         * here puts 32 published Coves into the baseline of **every test in the
         * repository** before its first line runs. Measured, not guessed: it
         * failed 32 of them. Most were fixture arithmetic — `assertSame(1,
         * DailyPickSet::count())` becoming 33 — but `ContentPromotionTest` hit
         * a genuine unique violation on `cove_plans_market_slug_idx`, because
         * the envelope import has no answer for a plan slug that is already
         * taken.
         *
         * The alternative was to rewrite those 32 tests to tolerate the
         * content, which would bake this article set into the assumptions of
         * every unrelated test forever. This project already answers the same
         * question the same way one level up: shipped Cove prose is seeded by
         * `bc:seed-shop-coves`, a command, and not by a migration.
         *
         * So the migration is the *delivery* — it runs before `app` starts on
         * every deploy, which is what puts these on production with nobody
         * logging in — and the test suite keeps an empty editorial table, which
         * is what every test written before today assumes. The behaviour itself
         * is covered by `AdviceCoveSeederTest` against the same service.
         */
        if (app()->environment('testing')) {
            return;
        }

        $report = app(AdviceCoveSeeder::class)->run();

        /*
         * Say what happened. A deploy log that reads "migrated" tells you
         * nothing about whether 32 articles arrived or zero did, and the
         * interesting case — a slug already taken in that market — is silent
         * by construction.
         */
        foreach ($report['skipped'] as $line) {
            echo "  advice-coves skipped: {$line}\n";
        }

        echo sprintf(
            "  advice-coves: %d written, %d kept, %d skipped\n",
            count($report['written']),
            count($report['kept']),
            count($report['skipped']),
        );
    }

    public function down(): void
    {
        $ids = DB::table('daily_pick_sets')
            ->where('kind', CoveKind::Advice->value)
            ->where('editorial_source', AdviceCoveSeeder::SOURCE)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        /*
         * The plans first. `cove_plans.edition_id` is nulled on delete rather
         * than cascaded, so removing the editions alone would leave a planner
         * full of plans pointing at nothing — which reads as work waiting to be
         * done rather than as work that has been withdrawn.
         *
         * Only the minted records. A plan somebody approved is an editorial
         * decision about a page that will exist again the moment this migration
         * is re-run, and it is not this migration's to throw away.
         */
        DB::table('cove_plans')
            ->whereIn('edition_id', $ids)
            ->where('status', 'used')
            ->delete();

        /*
         * Then the editions. Any approved plan still pointing at one has its
         * `edition_id` nulled by the foreign key rather than being deleted with
         * it, which leaves the plan in the planner where a person can see it —
         * the correct outcome for a decision nobody withdrew.
         */
        DB::table('daily_pick_sets')->whereIn('id', $ids)->delete();
    }
};

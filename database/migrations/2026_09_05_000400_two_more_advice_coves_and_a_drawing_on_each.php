<?php

declare(strict_types=1);

use App\Services\Content\AdviceCoveSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Re-seed the advice articles: two new subjects, and a scene on all ten.
 *
 * `resources/content/advice-coves.php` gained `gift-returns` and
 * `parcel-never-arrived` in `be-nl`, `nl-nl` and `en`, and every topic gained a
 * `scene` — the drawing `/guides` now puts on each card, and the article on its
 * own page. Neither reaches a reader until something rewrites the rows.
 *
 * ## Why a second migration rather than editing the first
 *
 * `2026_09_03_000100_the_advice_coves_move_in` has already run everywhere,
 * including production. Migrations are forward-only here, so a file that has
 * run is a record of what happened on a day and not a script to keep current —
 * editing it would change nothing on any database that has it and would quietly
 * diverge a fresh `migrate` from a deployed one.
 *
 * So this is the same call again. That is safe by construction and is the
 * property the seeder was built around: matched on `(market, slug)`, refreshing
 * only rows whose `editorial_source` is still `seed`, stamping `published_at`
 * once so the shelf does not reshuffle, and reporting anything a person has
 * since edited as *kept* rather than overwriting it. Running it a third time
 * would be a no-op.
 *
 * The eight existing articles are rewritten in place with their scene added.
 * Their prose is unchanged, their URLs are unchanged, and their publication
 * dates are unchanged — a drawing is not a republication.
 *
 * ## Skipped in testing, for the reason the first one documents at length
 *
 * `RefreshDatabase` migrates and then opens its transaction, so seeding from a
 * migration puts every published article into the baseline of every test in the
 * repository — which failed 32 of them when the first one tried it. The
 * behaviour is covered by `AdviceCoveSeederTest` against the same service.
 *
 * ## Rolling back
 *
 * Nothing. `down()` is deliberately empty and is not an oversight: the previous
 * migration's `down()` already removes every seeded advice Cove, including the
 * two this adds, and a second one that deleted the new pair would then be
 * removing rows the rollback below it is about to remove again. There is no
 * intermediate state to return to — the alternative would be un-drawing eight
 * articles, which is not a state anything wants.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $report = app(AdviceCoveSeeder::class)->run();

        /*
         * Say what happened. `kept` is the line that matters on a re-seed: it
         * names every article a person edited in the panel, which this run
         * deliberately did **not** give a drawing to, and which somebody now has
         * to set by hand in the planner. Silence there would read as success.
         */
        foreach ([...$report['skipped'], ...$report['kept']] as $line) {
            echo "  advice-coves: {$line}\n";
        }

        echo sprintf(
            "  advice-coves: %d written, %d kept, %d skipped\n",
            count($report['written']),
            count($report['kept']),
            count($report['skipped']),
        );
    }

    public function down(): void {}
};

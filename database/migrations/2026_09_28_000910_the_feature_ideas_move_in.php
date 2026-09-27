<?php

declare(strict_types=1);

use App\Services\Contribute\FeatureIdeaSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Put the shipped feature ideas on the contribute page's board, so a deploy
 * of this release has a board with ideas on it without anybody remembering
 * to run a command (docs/features/contribute.md).
 *
 * The same call `bc:seed-feature-ideas` makes: idempotent, matched on
 * `seed_key`, never overwriting an idea somebody edited in the admin.
 *
 * ## Skipped in testing and on a fresh database
 *
 * In testing because `RefreshDatabase` migrates once and every test would
 * start with eleven ideas on the board (the advice Coves' migration records
 * that failing 32 tests). On a fresh, empty database because a migration that
 * runs today's content file against a later schema is the hazard the advice
 * Coves hit; there, run `bc:seed-feature-ideas` after `migrate`. Every
 * deployed database holds Dailies, so the guard is the same one.
 *
 * ## It cannot fail the deploy
 *
 * A failing migration is an outage here (Coolify stops the old containers
 * before `migrate` runs). The ideas are content, not schema, so an error is
 * reported and swallowed: the page works with an empty board, and the command
 * can be run by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        if (DB::table('daily_pick_sets')->doesntExist()) {
            echo "  feature-ideas: fresh database, skipped. Run bc:seed-feature-ideas after migrate.\n";

            return;
        }

        try {
            // Its own transaction, which inside the migration's is a savepoint:
            // on Postgres a failed statement aborts the whole transaction, so
            // without it the catch below would swallow the error and the
            // migration would still fail at commit.
            $report = DB::transaction(fn (): array => app(FeatureIdeaSeeder::class)->run());

            echo sprintf(
                "  feature-ideas: %d written, %d kept\n",
                count($report['written']),
                count($report['kept']),
            );
        } catch (Throwable $e) {
            echo '  feature-ideas: not seeded ('.$e->getMessage()."). Run bc:seed-feature-ideas.\n";
        }
    }

    public function down(): void {}
};

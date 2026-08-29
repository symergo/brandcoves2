<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A gift persona is an edition with no date.
 *
 * A persona is built exactly like a Daily Cove — the same finds, the same
 * editorial pass, the same picks with the same reactions — and differs in two
 * ways only: it is addressed by a permanent slug rather than by a date, and it
 * never expires, so it is not "today's" anything. That is a nullable column and
 * a kind, not a second table.
 *
 * `theme_slug` is not reused for the URL. It is regenerated on every build and
 * carries the plan id, so it is an output; a persona's address has to survive a
 * rebuild and a retitle, which makes it an input.
 *
 * ## The trap this migration creates
 *
 * Postgres sorts `ORDER BY drop_date DESC` **NULLS FIRST**. Every
 * `orderByDesc('drop_date')->first()` in the codebase — the home page's "today",
 * `DailyCoveController::findEdition()`, the archive strip — would therefore
 * return a *persona* as today's edition the moment one exists, with no error
 * anywhere. `DailyPickSet::scopeDaily()` is added in the same change and applied
 * at every one of those sites, and a feature test pins each surface.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_pick_sets', function (Blueprint $table) {
            $table->string('kind')->default('daily')->after('market');
            $table->string('slug')->nullable()->after('theme_slug');
            $table->date('drop_date')->nullable()->change();
        });

        DB::statement(
            "ALTER TABLE daily_pick_sets ADD CONSTRAINT daily_pick_sets_kind_check CHECK (kind IN ('daily', 'persona'))"
        );

        // A Daily is identified by its date and a persona by its slug; each
        // must have exactly the one that addresses it.
        DB::statement(<<<'SQL'
            ALTER TABLE daily_pick_sets ADD CONSTRAINT daily_pick_sets_address_check
            CHECK (
                (kind = 'daily' AND drop_date IS NOT NULL AND slug IS NULL)
                OR (kind = 'persona' AND drop_date IS NULL AND slug IS NOT NULL)
            )
        SQL);

        /*
         * The date uniqueness becomes partial.
         *
         * A plain unique (market, drop_date) permits exactly one NULL row per
         * market in Postgres — so the second persona in a market would fail to
         * insert, at 06:00, with a constraint violation and no other symptom.
         */
        /*
         * A constraint, not a bare index.
         *
         * `$table->unique()` in the original migration created a UNIQUE
         * *constraint*, and Postgres refuses to drop the index underneath one:
         * "cannot drop index ... because constraint ... requires it". The
         * constraint has to go, and the index goes with it.
         */
        DB::statement('ALTER TABLE daily_pick_sets DROP CONSTRAINT IF EXISTS daily_pick_sets_market_drop_date_unique');
        /*
         * The constraint first, and only then the index.
         *
         * `$table->unique(...)` creates a UNIQUE **constraint**, and Postgres
         * owns the backing index on its behalf: `DROP INDEX` on it fails with
         * "cannot drop index ... because constraint ... requires it", which
         * aborts this migration and therefore every test run. Both statements
         * are `IF EXISTS`, so this is correct whether the name belongs to a
         * constraint or to a bare index.
         */
        DB::statement('ALTER TABLE daily_pick_sets DROP CONSTRAINT IF EXISTS daily_pick_sets_market_drop_date_unique');
        DB::statement('DROP INDEX IF EXISTS daily_pick_sets_market_drop_date_unique');

        DB::statement(
            'CREATE UNIQUE INDEX daily_pick_sets_market_date_idx ON daily_pick_sets (market, drop_date) '.
            'WHERE drop_date IS NOT NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX daily_pick_sets_market_slug_idx ON daily_pick_sets (market, slug) '.
            'WHERE slug IS NOT NULL'
        );
    }

    public function down(): void
    {
        $personas = DB::table('daily_pick_sets')->where('kind', 'persona')->count();

        if ($personas > 0) {
            // Refuse rather than corrupt. Narrowing drop_date back to NOT NULL
            // would fail anyway; the tempting fix is to stamp a date on them,
            // which would publish every persona as some morning's Daily Cove.
            throw new RuntimeException(
                "Cannot roll back: {$personas} persona edition(s) exist. Remove them deliberately first."
            );
        }

        DB::statement('DROP INDEX IF EXISTS daily_pick_sets_market_slug_idx');
        DB::statement('DROP INDEX IF EXISTS daily_pick_sets_market_date_idx');
        DB::statement('ALTER TABLE daily_pick_sets DROP CONSTRAINT IF EXISTS daily_pick_sets_address_check');
        DB::statement('ALTER TABLE daily_pick_sets DROP CONSTRAINT IF EXISTS daily_pick_sets_kind_check');

        Schema::table('daily_pick_sets', function (Blueprint $table) {
            $table->date('drop_date')->nullable(false)->change();
            $table->dropColumn(['kind', 'slug']);
            $table->unique(['market', 'drop_date']);
        });
    }
};

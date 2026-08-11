<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A third way a guide topic can arrive: a retailer's bestseller chart.
 *
 * `TopicMiner` reads 30 days of our own searches, on the stated premise that a
 * site's own search log is "the only demand signal that is both real and
 * unavailable to competitors". That is true, and it has a cold-start problem it
 * cannot solve: a site with no traffic has no log, so the topic queue on a new
 * market is empty and stays empty until traffic arrives — which is partly what
 * the guides were meant to attract.
 *
 * The seasonal calendar (see 2026_08_08_000800) solved the blind spot in *time*.
 * This solves the one at the *start*: a bestseller chart is somebody else's
 * demand measurement, available on day one, and a category with forty charting
 * products is a category worth a buying guide whether or not anyone has searched
 * here for it yet.
 *
 * `chart_entries` is a plain count rather than a score, so an editor reads
 * "3 searches, 38 charting products" and can judge it. Defaulted to 0, so every
 * existing row keeps its meaning with no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guide_topics', function (Blueprint $table) {
            // How many products from this topic's chart category are currently
            // charting. Demand evidence that is not ours.
            $table->integer('chart_entries')->default(0)->after('search_volume');
        });

        /*
         * The origin CHECK has to be replaced rather than extended — there is no
         * ALTER ... ADD VALUE for a check constraint. Dropped and recreated in
         * one migration, which is safe here precisely because it IS a check
         * constraint and not a native Postgres enum: this runs inside a
         * transaction, where altering an enum could not.
         */
        DB::statement('ALTER TABLE guide_topics DROP CONSTRAINT IF EXISTS guide_topics_origin_check');
        DB::statement("ALTER TABLE guide_topics ADD CONSTRAINT guide_topics_origin_check CHECK (origin IN ('search', 'seasonal', 'chart'))");
    }

    public function down(): void
    {
        // Forward-only in practice, but a rollback must not leave rows that
        // violate the constraint it is about to restore.
        DB::statement("UPDATE guide_topics SET origin = 'search' WHERE origin = 'chart'");
        DB::statement('ALTER TABLE guide_topics DROP CONSTRAINT IF EXISTS guide_topics_origin_check');
        DB::statement("ALTER TABLE guide_topics ADD CONSTRAINT guide_topics_origin_check CHECK (origin IN ('search', 'seasonal'))");

        Schema::table('guide_topics', function (Blueprint $table) {
            $table->dropColumn('chart_entries');
        });
    }
};

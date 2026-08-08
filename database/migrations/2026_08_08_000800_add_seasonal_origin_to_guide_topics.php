<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Let a Cove topic come from the calendar as well as from the search log.
 *
 * `TopicMiner` reads 30 days of our own searches, which is the right primary
 * signal — it is real demand and no competitor has it. It also has one structural
 * blind spot: **it cannot know about a season before the season arrives.**
 *
 * Barbecue searches peak in June. A miner reading June's log commissions the
 * barbecue Cove in July, publishes it in August, and it earns its first real
 * traffic the following May. Halloween is worse, because the whole window is
 * three weeks long: by the time the demand is in the log, the demand is over.
 *
 * So seasonal topics are seeded from a calendar with a window that opens *before*
 * the season, and `ripest()` prefers an in-season one over a higher-scoring
 * evergreen topic. Out of season they are simply invisible, which is why the
 * window is stored rather than inferred.
 *
 * `origin` defaults to 'search' so every existing row keeps its meaning without a
 * backfill, and the CHECK constraint is a string check rather than a native
 * Postgres enum — altering one of those cannot run inside a transaction, which
 * makes every future value a deploy hazard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guide_topics', function (Blueprint $table) {
            $table->string('origin')->default('search')->after('topic');

            // MM-DD, so a window recurs every year. Nullable: an evergreen topic
            // mined from the log has no season.
            $table->string('season_from', 5)->nullable()->after('origin');
            $table->string('season_to', 5)->nullable()->after('season_from');
        });

        DB::statement("ALTER TABLE guide_topics ADD CONSTRAINT guide_topics_origin_check CHECK (origin IN ('search', 'seasonal'))");

        // The selector `ripest()` uses for the seasonal branch.
        DB::statement('CREATE INDEX guide_topics_seasonal_idx ON guide_topics (market, origin, status) WHERE season_from IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS guide_topics_seasonal_idx');
        DB::statement('ALTER TABLE guide_topics DROP CONSTRAINT IF EXISTS guide_topics_origin_check');

        Schema::table('guide_topics', function (Blueprint $table) {
            $table->dropColumn(['origin', 'season_from', 'season_to']);
        });
    }
};

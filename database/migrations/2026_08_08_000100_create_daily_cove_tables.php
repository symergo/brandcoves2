<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Daily Cove: daily picks and buying guides merged into one edition, with a
 * price-guessing game at the front of it.
 *
 * Expand-only. `daily_pick_sets` keeps its name and every existing column — it
 * is already the edition table in all but title, and renaming it would buy a
 * nicer word at the cost of a rewrite that cannot roll back.
 *
 * See docs/features/daily-cove.md for why the three beats belong on one page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_pick_sets', function (Blueprint $table) {
            // Beat 3. Nullable: a day with no ripe topic still publishes picks
            // and a puzzle rather than nothing at all.
            $table->foreignId('guide_id')->nullable()->after('theme_source')
                ->constrained('guides')->nullOnDelete();

            /*
             * Beat 1 — the guess.
             *
             * The answer is frozen onto the edition rather than read live from
             * the group. A price that moves between the guess and the reveal
             * would mean scoring someone against a number that no longer
             * exists, and the whole game rests on the answer being fair.
             *
             * COMPLIANCE: only sources that permit retained pricing can be the
             * subject of the guess. A source that requires a live re-fetch
             * cannot have its price frozen here at all.
             * See docs/features/amazon-compliance.md.
             */
            $table->foreignId('challenge_group_id')->nullable()->after('guide_id')
                ->constrained('product_groups')->nullOnDelete();
            $table->integer('challenge_price')->nullable()->after('challenge_group_id');
            $table->text('challenge_reveal')->nullable()->after('challenge_price');
        });

        /**
         * One row per identity per edition.
         *
         * `played_on` is a plain date column rather than something derived from
         * created_at, because the streak query groups on it and date_trunc over
         * a timestamptz is not IMMUTABLE — the same reason price_history has its
         * own captured_on.
         */
        Schema::create('challenge_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('set_id')->constrained('daily_pick_sets')->cascadeOnDelete();

            // Same two-identity shape as wishlists: playable before signup,
            // because asking for an account before the first guess loses people.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->uuid('anon_id')->nullable();

            $table->string('market');
            $table->date('played_on');

            // Cents, in the order they were guessed. The share grid is rendered
            // from the bands, so the raw guesses never have to leave the server.
            $table->jsonb('guesses')->default(DB::raw("'[]'::jsonb"));
            $table->jsonb('bands')->default(DB::raw("'[]'::jsonb"));
            $table->boolean('solved')->default(false);
            $table->smallInteger('attempts')->default(0);
            $table->timestamps();

            $table->foreign('anon_id')->references('id')->on('anonymous_identities')->cascadeOnDelete();
            $table->index(['user_id', 'played_on']);
            $table->index(['anon_id', 'played_on']);
        });

        // A player has exactly one attempt row per edition. Two would mean two
        // sets of tries at the same puzzle, which is the one thing a daily
        // game cannot allow.
        DB::statement('CREATE UNIQUE INDEX challenge_attempts_user_set_idx ON challenge_attempts (set_id, user_id) WHERE user_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX challenge_attempts_anon_set_idx ON challenge_attempts (set_id, anon_id) WHERE anon_id IS NOT NULL');

        DB::statement('ALTER TABLE challenge_attempts ADD CONSTRAINT challenge_attempts_one_player CHECK (num_nonnulls(user_id, anon_id) = 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('challenge_attempts');

        Schema::table('daily_pick_sets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('guide_id');
            $table->dropConstrainedForeignId('challenge_group_id');
            $table->dropColumn(['challenge_price', 'challenge_reveal']);
        });
    }
};

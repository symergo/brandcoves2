<?php

declare(strict_types=1);

use App\Enums\Market;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "How well do you know them?"
 *
 * Four products, one of them really on their list. Five rounds, a score, a
 * shareable grid — the loop the Daily Cove already proved on this codebase.
 *
 * It also answers the hardest problem in the whole feature: **nobody fills in a
 * wishlist**, because a list that only helps other people is a chore. A list your
 * friends compete on is a reason to build one.
 *
 * A quiz is not a list, so it does not live in `wishlists`. It points at one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('list_quizzes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('wishlist_id');
            $table->string('market');
            $table->uuid('share_token')->unique();

            /*
             * The rounds are generated once and stored.
             *
             * Two people comparing scores must have answered the same questions,
             * which is also what turns a posted result into a conversation rather
             * than a broadcast. Regenerating per player would make every score
             * incomparable and the share pointless.
             */
            $table->jsonb('rounds');

            $table->timestamps();

            $table->foreign('wishlist_id')->references('id')->on('wishlists')->cascadeOnDelete();
            $table->index('wishlist_id');
        });

        Schema::create('list_quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('quiz_id');

            // Playable signed-out, like the daily challenge: asking someone to
            // sign up before their first guess loses them.
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->uuid('anon_id')->nullable();

            $table->jsonb('answers')->default(DB::raw("'[]'::jsonb"));
            $table->smallInteger('score')->default(0);
            $table->date('played_on');
            $table->timestamps();

            $table->foreign('quiz_id')->references('id')->on('list_quizzes')->cascadeOnDelete();
            $table->foreign('anon_id')->references('id')->on('anonymous_identities')->cascadeOnDelete();
            $table->index(['quiz_id', 'played_on']);
        });

        $markets = implode(', ', array_map(fn (string $m) => "'".$m."'", Market::values()));
        DB::statement("ALTER TABLE list_quizzes ADD CONSTRAINT list_quizzes_market_check CHECK (market IN ($markets))");

        // A player is somebody, exactly as with a list owner. An attempt owned by
        // nobody can never be shown back to the person who made it.
        DB::statement('ALTER TABLE list_quiz_attempts ADD CONSTRAINT list_quiz_attempts_one_player CHECK (num_nonnulls(user_id, anon_id) = 1)');

        // One score per player per quiz: replaying until you get five out of five
        // is not a score anybody would want to post.
        DB::statement('CREATE UNIQUE INDEX list_quiz_attempts_user_idx ON list_quiz_attempts (quiz_id, user_id) WHERE user_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX list_quiz_attempts_anon_idx ON list_quiz_attempts (quiz_id, anon_id) WHERE anon_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('list_quiz_attempts');
        Schema::dropIfExists('list_quizzes');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The follow graph.
 *
 * Deliberately built from shared links rather than imported from a social
 * network. Facebook's `user_friends` returns only friends who have also
 * authorised this same app — approximately nobody, for a new site — and Google's
 * contacts scopes are restricted, requiring an annual third-party security
 * assessment and the processing of personal data belonging to people who never
 * visited here.
 *
 * What actually connects people is the link that already travels through a group
 * chat, plus an account created at the far end of it. These two tables are that
 * edge, made durable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('follower_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('followed_id')->constrained('users')->cascadeOnDelete();

            // Where the edge came from, so the acquisition loop can be measured
            // without asking people.
            $table->string('source')->default('manual');
            $table->timestamps();

            $table->unique(['follower_id', 'followed_id']);
            $table->index('followed_id');
        });

        Schema::create('user_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blocker_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blocked_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['blocker_id', 'blocked_id']);
            $table->index('blocked_id');
        });

        // Following yourself is not a thing, and a self-edge would put your own
        // lists in your friends' tab.
        DB::statement('ALTER TABLE user_follows ADD CONSTRAINT user_follows_not_self CHECK (follower_id <> followed_id)');
        DB::statement('ALTER TABLE user_blocks ADD CONSTRAINT user_blocks_not_self CHECK (blocker_id <> blocked_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('user_blocks');
        Schema::dropIfExists('user_follows');
    }
};

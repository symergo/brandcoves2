<?php

declare(strict_types=1);

use App\Enums\Market;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two ways This or that reaches past one person on one screen
 * (docs/features/taste-together.md, docs/features/gift-profile-card.md).
 *
 * - `taste_invites`: a "help me find out what Anna likes" link a giver makes
 *   for one of their people. Whoever holds the link plays This or that about
 *   that person, no account needed.
 * - `taste_runs`: one row per person who played through such a link. Only
 *   the choices (product ids and what was pressed), never a profile: the
 *   combined profile is worked out again from every run's choices, so nothing
 *   a browser sends is taken as a conclusion. `participant_hash` is a
 *   one-way hash of the player's cookie identity, salted with the invite, so
 *   the same person playing twice replaces their own run rather than counting
 *   twice, and no hash can be matched against any other table.
 * - `gift_profile_cards`: a gift profile somebody made about themselves and chose to
 *   share. The profile only (interests, budget, what to leave out), never the
 *   choices it came from. `name` is whatever the maker typed, or nothing.
 *   `owner_key_hash` is the hash of a key only the maker's browser holds, so
 *   an anonymous maker can still take the card down.
 *
 * All three are new tables, so nothing here locks an existing one. The market
 * columns are a string with a CHECK, as everywhere (CLAUDE.md, conventions).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taste_invites', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('recipient_id')->constrained('recipients')->cascadeOnDelete();
            $table->string('token', 16)->unique();
            $table->string('market', 8);
            // Set when the giver stops the link. The answers stay until pruned.
            $table->timestamp('revoked_at')->nullable();
            // The last time the giver added the combined result to the person.
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['recipient_id', 'created_at']);
        });

        Schema::create('taste_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('taste_invite_id')->constrained('taste_invites')->cascadeOnDelete();
            $table->string('participant_hash', 64);
            $table->jsonb('choices');
            $table->unsignedSmallInteger('answered')->default(0);
            $table->timestamps();

            $table->unique(['taste_invite_id', 'participant_hash']);
        });

        Schema::create('gift_profile_cards', function (Blueprint $table): void {
            $table->id();
            $table->string('token', 16)->unique();
            $table->string('market', 8);
            $table->string('name', 40)->nullable();
            $table->jsonb('profile');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('owner_key_hash', 64);
            // Refreshed at most once a day when somebody opens it; the pruner
            // removes a card nobody has opened for a year.
            $table->timestamp('last_opened_at')->nullable();
            $table->timestamps();

            $table->index('last_opened_at');
        });

        $markets = implode(', ', array_map(fn (Market $m) => "'{$m->value}'", Market::cases()));

        DB::statement("ALTER TABLE taste_invites ADD CONSTRAINT taste_invites_market_check CHECK (market IN ({$markets}))");
        DB::statement("ALTER TABLE gift_profile_cards ADD CONSTRAINT gift_profile_cards_market_check CHECK (market IN ({$markets}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_profile_cards');
        Schema::dropIfExists('taste_runs');
        Schema::dropIfExists('taste_invites');
    }
};

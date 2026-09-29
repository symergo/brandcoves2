<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Mijn smaak": a person's own gift taste, kept on their account (owner,
 * 2026-09-29). Until now a taste lived only on somebody else's saved person
 * (`recipients`), or on a card a person handed out (`gift_profile_cards`);
 * there was nothing a person could keep and change about themselves.
 *
 * A table of its own rather than columns on `users`: the account is sign-in,
 * this is gifting, and one row per person keeps the account row lean.
 *
 * No budget, on the owner's word: what somebody spends is the giver's
 * decision, asked in their own search. No CHECK on `vibe` or `age_band`,
 * as on `recipients`: both are validated against the enum and the fixed
 * bands at the write, and a new value must not need a migration.
 *
 * Deleting the account deletes the taste with it (GDPR).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_tastes', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->jsonb('interests')->default('[]');
            $table->string('vibe', 40)->nullable();
            $table->jsonb('preferences')->default('[]');
            $table->jsonb('values')->default('[]');
            $table->jsonb('avoid')->default('[]');
            $table->string('age_band', 10)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_tastes');
    }
};

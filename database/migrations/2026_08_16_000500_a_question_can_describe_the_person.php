<?php

declare(strict_types=1);

use App\Enums\Vibe;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Optional structure on a question: what they are into, how it should feel.
 *
 * A free-text question is the point of the board — "she has everything, help" is
 * exactly what search cannot take — but the answers are noticeably better when
 * the asker has said *coffee, practical, under €40*, and most people will tick
 * that if the ticking is free.
 *
 * ## The same vocabulary as the Gift Finder, deliberately
 *
 * `interests` holds `Interest` enum values and `vibe` a `Vibe`, which means an
 * answerer's product search can be seeded from a question with no translation
 * layer, and the two surfaces cannot drift into two different ideas of what
 * "cooking" means. It also means the board's structured half is already
 * localised: both enums render through `label()`.
 *
 * ## Everything here is nullable, and stays that way
 *
 * The question is the required part. Somebody who types one sentence and
 * presses Ask must get a question on the board — the structure is an
 * accelerator for the people who want it, never a form to complete.
 *
 * `interests` is jsonb rather than a join table for the same reason
 * `recipients.interests` is: it is a short list read whole, never queried
 * across, and a table would buy nothing but a join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('community_questions', function (Blueprint $table) {
            $table->jsonb('interests')->nullable();
            $table->string('vibe')->nullable();
            $table->jsonb('values')->nullable();

            // Who it is for, in the two words that change an answer most.
            $table->string('age_band', 20)->nullable();
            $table->string('occasion', 40)->nullable();
        });

        // Same enum-ish convention as everywhere else: a string plus a CHECK,
        // because altering a native PG enum cannot run inside a transaction.
        DB::statement(
            'ALTER TABLE community_questions ADD CONSTRAINT community_questions_vibe_check CHECK (vibe IS NULL OR vibe IN ('
            .implode(', ', array_map(fn (string $v) => "'".$v."'", Vibe::values()))
            .'))'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE community_questions DROP CONSTRAINT IF EXISTS community_questions_vibe_check');

        Schema::table('community_questions', function (Blueprint $table) {
            $table->dropColumn(['interests', 'vibe', 'values', 'age_band', 'occasion']);
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the editor wants said, as opposed to what the editor wants shown.
 *
 * A plan already had three text fields and none of them was this one:
 *
 *   `editorial`        — finished prose. Wins outright and skips the model, so
 *                        it is the answer to "I will write it myself", not to
 *                        "write it like this".
 *   `note`             — a note to whoever reads the plan later. Never reaches
 *                        the model, deliberately: it is where you say "the
 *                        client asked for this", which is nobody's business but
 *                        ours.
 *   `items[].note`     — why one product is on the list. Per product, and so
 *                        no place for anything about the article as a whole.
 *
 * The gap is the direction a person gives before the writing starts — "keep it
 * short", "lean on the nostalgia, not the tech", "do not mention Christmas, it
 * runs in October". Without somewhere to put it, an editor who wanted that had
 * only one option: write the whole article by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cove_plans', function (Blueprint $table) {
            $table->text('build_instructions')->nullable()->after('editorial');
        });
    }

    public function down(): void
    {
        Schema::table('cove_plans', function (Blueprint $table) {
            $table->dropColumn('build_instructions');
        });
    }
};

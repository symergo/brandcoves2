<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Long-form copy for an edition.
 *
 * Stored with its link tokens UNRESOLVED — `[[brand:Sony]]`, not an anchor.
 * Resolving at render rather than at write time means the links follow the
 * market the page is being read in, and a product that goes out of stock next
 * month degrades to plain text on its own instead of leaving a dead anchor
 * baked into a row nobody will revisit.
 *
 * See App\Services\Guides\CoveMarkup for the token contract.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_pick_sets', function (Blueprint $table) {
            // A few paragraphs. The theme_blurb stays what it is — a single
            // line for cards and meta descriptions — because a 600-word
            // editorial does not belong in a <meta> tag.
            $table->text('editorial')->nullable()->after('theme_blurb');

            // Whether the prose came from the model or nowhere. Same reason
            // theme_source exists: a run of 'none' is a signal, not a setting.
            $table->string('editorial_source')->nullable()->after('editorial');
        });
    }

    public function down(): void
    {
        Schema::table('daily_pick_sets', function (Blueprint $table) {
            $table->dropColumn(['editorial', 'editorial_source']);
        });
    }
};

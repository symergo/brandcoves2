<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The style question, on the person it was asked about.
 *
 * `recipients` already remembers the vibe, the values and the age band the
 * wizard was given; style is the axis those could not express — "modern or
 * vintage" is not a stronger or weaker version of "useful or beautiful"
 * (owner's call, 2026-09-14). A list rather than a single value, because
 * someone who likes vintage *and* colourful things has one taste, not two.
 *
 * Nullable with no backfill: every person saved before today simply has not
 * been asked, and a null is exactly that. The engine scores an unasked
 * question neutral rather than badly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipients', function (Blueprint $table) {
            $table->jsonb('styles')->nullable()->after('vibe');
        });
    }

    public function down(): void
    {
        Schema::table('recipients', function (Blueprint $table) {
            $table->dropColumn('styles');
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Where the dial was left. Persisted so returning to /discover
            // resumes where the person was rather than resetting to Search —
            // a control you have to re-set on every visit is one people stop
            // moving.
            $table->string('last_mode')->nullable();

            /*
             * The user's own surprise dial, 0..1.
             *
             * Nullable rather than defaulted to 0.5, so "never touched it" and
             * "deliberately set it to the middle" stay distinguishable. The
             * first is a candidate for a nudge; the second is a decision.
             */
            $table->float('surprise_dial')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['last_mode', 'surprise_dial']);
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prose written by a human (or by Claude through the editorial API), attached to
 * the plan rather than to the edition.
 *
 * The edition already has an `editorial` column, but it is an *output* — the
 * builder rewrites it on every rebuild, and a rebuild is routine. Copy typed by
 * an author has to survive that, so it lives on the intention and the builder
 * copies it down. Written here, a rebuild reproduces the same article; written
 * on the edition, the next rebuild silently replaces it with a generated one.
 *
 * Stored with its link tokens UNRESOLVED, exactly as the AI path stores them —
 * `[[product:1234|the odd one]]`, never an anchor. See CoveMarkup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cove_plans', function (Blueprint $table) {
            $table->text('editorial')->nullable()->after('blurb');
        });
    }

    public function down(): void
    {
        Schema::table('cove_plans', function (Blueprint $table) {
            $table->dropColumn('editorial');
        });
    }
};

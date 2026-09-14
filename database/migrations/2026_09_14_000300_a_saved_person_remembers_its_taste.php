<?php

declare(strict_types=1);

use App\Enums\Preference;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which way a saved person's taste goes, on the person it was asked about.
 *
 * `recipients` already remembers the vibe, the values and the age band the
 * wizard was given. This is the rest of a taste: the poles of
 * {@see Preference} they lean towards — modern or vintage, cosy or
 * sleek, everyday or luxurious. A list, because a taste is several of those
 * choices and never both ends of one.
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
            $table->jsonb('preferences')->nullable()->after('vibe');
        });
    }

    public function down(): void
    {
        Schema::table('recipients', function (Blueprint $table) {
            $table->dropColumn('preferences');
        });
    }
};

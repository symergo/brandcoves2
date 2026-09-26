<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Cove plan can say who it is for rather than which words to search
 * (roadmap step 4, part 4; docs/features/editorial-api.md).
 *
 * `brief` is a gift brief (TasteBrief::toArray(): recipient, interests,
 * occasion, budget, …). When a plan has one, the builder fills the slots the
 * curator left open from the suggestion engine with it, instead of searching
 * the plan's `queries`. "The keen cook" becomes *interests: cooking*.
 *
 * Nullable with no default: a catalogue-only change in Postgres, no rewrite,
 * no lock worth the name on a table of a few thousand plans. Every existing
 * plan reads null and builds exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cove_plans', function (Blueprint $table) {
            $table->jsonb('brief')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cove_plans', function (Blueprint $table) {
            $table->dropColumn('brief');
        });
    }
};

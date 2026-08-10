<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two kinds of article, one table.
 *
 * A **buying** guide is a ranked shortlist: "the five best X, and the one
 * actually worth it". Its substance is the products, and the prose is
 * presentation — which is why `GuideBuilder` refuses to publish one with fewer
 * than five.
 *
 * An **advice** article has no shortlist at all. "How to tell a real review
 * from a paid one", "what a good returns policy looks like", "how Amazon's
 * price history actually works". Its substance *is* the prose, and demanding
 * products would either block it or pad it with things the writing is not
 * about.
 *
 * One table rather than two because everything else is identical — slug, market,
 * status, meta, FAQ, freshness, the same URL space, the same sitemap entry. The
 * only rule that differs is how many items are required, and a separate table
 * would duplicate a dozen columns to express one integer.
 *
 * Defaulting to `buying` keeps every existing row correct: they all have
 * shortlists, because until now nothing else could exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guides', function (Blueprint $table) {
            $table->string('kind')->default('buying')->after('title');
        });

        // String plus a CHECK, never a native PG enum: altering one cannot run
        // inside a transaction, which makes every future value a deploy hazard.
        DB::statement(
            "ALTER TABLE guides ADD CONSTRAINT guides_kind_check CHECK (kind IN ('buying', 'advice'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE guides DROP CONSTRAINT IF EXISTS guides_kind_check');

        Schema::table('guides', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};

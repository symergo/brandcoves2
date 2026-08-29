<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two kinds of Cove, and how much of one the engine may choose.
 *
 * **kind** — a gift persona ("the cottagecore herbalist", "the dad who has
 * everything") is a Cove with no date. Not a new subsystem: it is planned in
 * the same table, curated with the same screen, built by the same builder and
 * written by the same prompt. The only differences are that it has no drop date
 * and that it lives at a permanent slug instead of a date. A separate table
 * would duplicate a dozen columns to express that.
 *
 * The CHECK is load-bearing rather than tidy. `CovePlan::approvedFor()` matches
 * on (market, drop_date), so a persona that quietly acquired a date would be
 * picked up by the 06:00 build and published as that morning's Daily Cove —
 * with no symptom anywhere until a reader saw it.
 *
 * **pick_mode** — what the builder is allowed to add to the curated list.
 *
 *   open   → the curated products lead and the engine tops the edition up to
 *            picks.per_day. The old pinned-products behaviour.
 *   locked → the curated products ARE the edition, in the curator's order, and
 *            the engine adds nothing.
 *
 * Both are real editorial modes and neither is a better default than the other:
 * a hand-built feature day wants `locked`, an ordinary Tuesday with three good
 * ideas in it wants `open`. `open` is the default because it is what every
 * existing row already does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cove_plans', function (Blueprint $table) {
            $table->string('kind')->default('daily')->after('market');
            $table->string('pick_mode')->default('open')->after('status');

            // A persona's permanent URL. Null for a Daily, which is addressed
            // by its date.
            $table->string('slug')->nullable()->after('title');
        });

        // String plus a CHECK, never a native PG enum: altering one cannot run
        // inside a transaction, which makes every future value a deploy hazard.
        DB::statement(
            "ALTER TABLE cove_plans ADD CONSTRAINT cove_plans_kind_check CHECK (kind IN ('daily', 'persona'))"
        );

        DB::statement(
            "ALTER TABLE cove_plans ADD CONSTRAINT cove_plans_pick_mode_check CHECK (pick_mode IN ('open', 'locked'))"
        );

        DB::statement(
            'ALTER TABLE cove_plans ADD CONSTRAINT cove_plans_persona_is_undated_check '.
            "CHECK (kind <> 'persona' OR drop_date IS NULL)"
        );

        /*
         * One persona per slug per market.
         *
         * Partial, because every Daily leaves it null and a plain unique index
         * would allow exactly one of those per market. Same reasoning as the
         * existing cove_plans_market_date_idx.
         */
        DB::statement(
            'CREATE UNIQUE INDEX cove_plans_market_slug_idx ON cove_plans (market, slug) '.
            'WHERE slug IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS cove_plans_market_slug_idx');
        DB::statement('ALTER TABLE cove_plans DROP CONSTRAINT IF EXISTS cove_plans_persona_is_undated_check');
        DB::statement('ALTER TABLE cove_plans DROP CONSTRAINT IF EXISTS cove_plans_pick_mode_check');
        DB::statement('ALTER TABLE cove_plans DROP CONSTRAINT IF EXISTS cove_plans_kind_check');

        Schema::table('cove_plans', function (Blueprint $table) {
            $table->dropColumn(['kind', 'pick_mode', 'slug']);
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chipping in is a fact about the present, never about one line on a list.
 *
 * Yesterday's `2026_08_29_000300` made `item_id` nullable so a **group** list
 * could pool on the list while a `mine` list went on pooling per item. Seeing
 * it rendered settled the question the other way: on a wish list of six things
 * that meant an "I'm in" under every card — the secondary action on each,
 * repeated six times, next to the claim button that is the actual one. Nobody
 * chips in towards *an item on a wishlist*; they go in together on a present,
 * and a group list is what that is.
 *
 * So contributions are a group-list mechanism, at list level, and `item_id`
 * goes rather than lingering as a column nothing writes. A nullable field with
 * no writer is the drift this codebase keeps finding: it reads as a capability
 * to whoever meets it next.
 *
 * ## Safe to drop outright
 *
 * `gift_pledges` was empty when this shipped: the write path reached a UI only
 * on 2026-08-16, dev held no rows, and the deploy was confirmed safe on that
 * basis before it went out.
 *
 * **What it would have had to do otherwise, and deliberately does not:** fold
 * each per-item pledge into its list's pot, and reconcile the collision that
 * creates when one person pledged against two items of the same list — the new
 * unique index is per person per list, so two such rows would abort the
 * migration in the middle of a deploy rather than merging quietly.
 *
 * That is the part to re-read before replaying this anywhere with data in it.
 * Written down because "it was empty" is a fact about one moment, and a
 * migration outlives the moment it was written in.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS gift_pledges_user_idx');
        DB::statement('DROP INDEX IF EXISTS gift_pledges_anon_idx');
        DB::statement('ALTER TABLE gift_pledges DROP CONSTRAINT IF EXISTS gift_pledges_item_matches_list');

        Schema::table('gift_pledges', function (Blueprint $table) {
            $table->dropConstrainedForeignId('item_id');
        });

        /*
         * Back to a plain partial unique per person per list — no
         * `NULLS NOT DISTINCT` needed, because the column whose null carried
         * the meaning is gone. One pledge each; changing your mind edits it.
         */
        DB::statement('CREATE UNIQUE INDEX gift_pledges_user_idx ON gift_pledges (wishlist_id, user_id) WHERE user_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX gift_pledges_anon_idx ON gift_pledges (wishlist_id, anon_id) WHERE anon_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS gift_pledges_user_idx');
        DB::statement('DROP INDEX IF EXISTS gift_pledges_anon_idx');

        Schema::table('gift_pledges', function (Blueprint $table) {
            $table->foreignId('item_id')->nullable()->constrained('wishlist_items')->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX gift_pledges_user_idx
                ON gift_pledges (wishlist_id, item_id, user_id)
                NULLS NOT DISTINCT
             WHERE user_id IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX gift_pledges_anon_idx
                ON gift_pledges (wishlist_id, item_id, anon_id)
                NULLS NOT DISTINCT
             WHERE anon_id IS NOT NULL
        SQL);
    }
};

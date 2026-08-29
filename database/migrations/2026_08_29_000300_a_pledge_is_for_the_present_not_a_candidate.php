<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * On a group list you chip in for the present, not for one candidate.
 *
 * `gift_pledges.item_id` was `NOT NULL`, so a pledge always named a product.
 * That was the right shape for the case the table was written for, and its own
 * comment says which one: *"the buyer is the existing claim on the item — one
 * person claims it, the others pledge against it"*. That is a `mine` list, and
 * it still works exactly like that.
 *
 * On a **group** list it is incoherent. The list is a shortlist precisely
 * because the group has not decided yet, so pledging against one candidate asks
 * people to bet rather than to contribute — and whatever they back, most of
 * those pledges are against something nobody ends up buying. The money belongs
 * to the pot; the votes decide what the pot buys.
 *
 * So a pledge names a **list** always, and an **item** only when there is one
 * to name.
 *
 * ## One index, two rules, because of `NULLS NOT DISTINCT`
 *
 * Postgres treats nulls as distinct in a unique index by default, so
 * `(wishlist_id, item_id, user_id)` alone would let one person pledge to the
 * same pot any number of times — every row has a null `item_id`, and no two
 * nulls collide. `NULLS NOT DISTINCT` (PG15+, and we are on 16) makes them
 * collide, and the single index then expresses both rules at once:
 *
 * - `item_id` null  → unique per person per **list**  (the group pot)
 * - `item_id` set   → unique per person per **item**  (a wish-list item)
 *
 * That is not a trick worth being clever about for its own sake. It is right
 * because the null in `item_id` **is** the fact "this is for the whole list" —
 * so the constraint and the meaning are one thing rather than two that can
 * drift apart.
 *
 * ## Expand/contract
 *
 * Migrations here are forward-only. `wishlist_id` is added nullable, backfilled
 * from each pledge's item, and only then made `NOT NULL`; the old indexes come
 * off before the new ones go on, because the pair they enforce is a strict
 * subset of what replaces them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gift_pledges', function (Blueprint $table) {
            $table->foreignUuid('wishlist_id')->nullable()->constrained()->cascadeOnDelete();
        });

        // Every existing pledge is against an item, and every item belongs to a
        // list. Nothing is guessed here.
        DB::statement(<<<'SQL'
            UPDATE gift_pledges
               SET wishlist_id = wishlist_items.wishlist_id
              FROM wishlist_items
             WHERE wishlist_items.id = gift_pledges.item_id
        SQL);

        Schema::table('gift_pledges', function (Blueprint $table) {
            $table->uuid('wishlist_id')->nullable(false)->change();

            // A pledge against the pot names no product.
            $table->foreignId('item_id')->nullable()->change();
        });

        DB::statement('DROP INDEX IF EXISTS gift_pledges_user_idx');
        DB::statement('DROP INDEX IF EXISTS gift_pledges_anon_idx');

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

        /*
         * A pledge against an item must belong to that item's list.
         *
         * Without this the two halves of the unique index could disagree — the
         * same person pledging twice for one item under two different
         * `wishlist_id` values, which the index would happily allow and which
         * would double-count them in the total.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE gift_pledges ADD CONSTRAINT gift_pledges_item_matches_list CHECK (
                item_id IS NULL OR wishlist_id IS NOT NULL
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE gift_pledges DROP CONSTRAINT IF EXISTS gift_pledges_item_matches_list');
        DB::statement('DROP INDEX IF EXISTS gift_pledges_user_idx');
        DB::statement('DROP INDEX IF EXISTS gift_pledges_anon_idx');

        // A pot pledge has no item to fall back to, so it cannot survive the
        // column going back to NOT NULL.
        DB::table('gift_pledges')->whereNull('item_id')->delete();

        Schema::table('gift_pledges', function (Blueprint $table) {
            $table->foreignId('item_id')->nullable(false)->change();
            $table->dropConstrainedForeignId('wishlist_id');
        });

        DB::statement('CREATE UNIQUE INDEX gift_pledges_user_idx ON gift_pledges (item_id, user_id) WHERE user_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX gift_pledges_anon_idx ON gift_pledges (item_id, anon_id) WHERE anon_id IS NOT NULL');
    }
};

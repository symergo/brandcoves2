<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Visible to my people" on a wish list (owner's request, 2026-09-26).
 *
 * `wishlists.visible_to_friends`: the owner's friends on GiftCoves may open
 * this wish list, claim from it and pick from it when they make a gift list
 * for the owner. Read only on a `mine` list with an account behind it; see
 * `Wishlist::isVisibleToFriends()` and docs/features/wish-list-for-my-people.md.
 *
 * ## False for every existing list
 *
 * Every list that exists today was made private or shared by somebody who
 * never saw this option, and switching it on for them would put those lists in
 * front of people they did not choose. So the column defaults to false and no
 * row is backfilled. New wish lists are made with it on, in code (`ListMaker`,
 * `DefaultList`), where the choice is shown to the owner on the list itself.
 *
 * A plain boolean rather than a nullable "never asked": a nullable setting
 * whose default lives in code is the bug friends.md describes (the raw column
 * sent to one page and the effective value to another). Here the stored value
 * is the answer.
 *
 * ## Safe on production
 *
 * `ADD COLUMN ... NOT NULL DEFAULT false` is a catalogue-only change in
 * Postgres 11 and later: no table rewrite, a brief lock. `IF NOT EXISTS` makes
 * it safe to run twice, because a failing migration is an outage here (Coolify
 * stops the old containers before `migrate` runs).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE wishlists ADD COLUMN IF NOT EXISTS visible_to_friends boolean NOT NULL DEFAULT false');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE wishlists DROP COLUMN IF EXISTS visible_to_friends');
    }
};

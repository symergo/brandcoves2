<?php

declare(strict_types=1);

use App\Enums\ClaimVisibility;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Claiming on a list about somebody else, and who gets to see it.
 *
 * `ListKind::allowsClaiming()` was `mine`-only, so "help me choose and don't
 * double up" — the reason `for_someone` exists at all, in its own docblock —
 * had no mechanism behind it. Two siblings buying for a parent could share a
 * list and had no way to say which of them was getting what.
 *
 * ## Two columns, on two tables, for two different questions
 *
 * `wishlists.claim_visibility` is the owner's choice about their own list, and
 * is read **only** for a `for_someone` list: a `mine` list hides claims from
 * its owner whatever this says (invariant #4 is not a preference), and a
 * `group` list has no claiming at all. See {@see ClaimVisibility}.
 *
 * `wishlist_items.claimed_by_name` is a fact about one claim, recorded at the
 * moment it was made. It is written **only** while the list says `named`, and
 * never backfilled by a later change of setting — a name shown to other people
 * is a consent decision, and nobody consented to it before the switch was
 * thrown.
 *
 * ## Not `$hidden`, unlike `claimed_by_hash`
 *
 * The hash is `$hidden` on the model so it cannot leak through a `toArray()`.
 * This column is the opposite kind of thing: it exists to be shown, to the
 * people the owner chose to show it to. What keeps it safe is that
 * `ClaimView` is the only reader and it asks the kind and the setting first —
 * the same discipline as `ContributionView` and its breakdown.
 *
 * Default `anonymous`, which is the weakest disclosure that still coordinates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wishlists', function (Blueprint $table) {
            $table->string('claim_visibility', 20)->default('anonymous');
        });

        /*
         * String plus a CHECK, never a native Postgres enum: altering one
         * cannot run inside a transaction, which makes every future value a
         * deploy hazard. Values written out rather than read from the enum —
         * a migration that calls application code describes a different schema
         * every time that code changes. `EventType` already demonstrated the
         * consequence; see 2026_08_29_000100.
         */
        DB::statement(
            'ALTER TABLE wishlists ADD CONSTRAINT wishlists_claim_visibility_check '
            ."CHECK (claim_visibility IN ('anonymous', 'named', 'hidden_from_owner'))"
        );

        Schema::table('wishlist_items', function (Blueprint $table) {
            // Long enough for a name somebody types, short enough that it is
            // not a free-text channel. `gift_pledges.display_name` is the
            // precedent and it is the same act: a promise made to people.
            $table->string('claimed_by_name', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('wishlist_items', function (Blueprint $table) {
            $table->dropColumn('claimed_by_name');
        });

        DB::statement('ALTER TABLE wishlists DROP CONSTRAINT IF EXISTS wishlists_claim_visibility_check');

        Schema::table('wishlists', function (Blueprint $table) {
            $table->dropColumn('claim_visibility');
        });
    }
};

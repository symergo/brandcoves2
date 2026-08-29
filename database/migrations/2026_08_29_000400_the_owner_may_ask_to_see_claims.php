<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The owner decides whether they see what has been claimed.
 *
 * ## Invariant #4 becomes a default rather than an absolute
 *
 * "Claim state never reaches the list owner" was written for the case it is
 * still right about: a wish list exists so its owner is surprised, and nothing
 * should tell them by accident. What it did not allow for is the owner who
 * would simply rather know — a shared household list, a registry somebody is
 * co-ordinating for themselves, or anyone who finds the surprise less useful
 * than the coordination.
 *
 * So the rule is now: **hidden unless its owner has explicitly asked to see
 * it.** Never inferred, never a side effect of another setting, and never the
 * default. Everything downstream — `ClaimView`, `progress`, the delivery-address
 * gate — keeps going through `shouldHideClaimsFrom()`, which is still the one
 * place that decides.
 *
 * ## Two questions, two columns
 *
 * `claim_visibility` was carrying both of these and could not hold them at
 * once. Once the owner may opt in, a `mine` list needs to say *"show me claims,
 * and let the others see each other's names"* — and a single three-valued
 * enum in which one value meant "hidden from the owner" has no way to express
 * it. `hidden_from_owner` was a value of the wrong column.
 *
 * | Column | Question |
 * |---|---|
 * | `owner_sees_claims` | may **I** see what has been claimed off my list? |
 * | `claim_visibility` | do the people buying see **each other's** names? |
 *
 * They are independent, and conflating them is how the third option ended up
 * meaning something different depending on the kind of list.
 *
 * ## Nullable, because "not chosen" is not "no"
 *
 * A null means the owner has never been asked, and `Wishlist::ownerSeesClaims()`
 * falls back to what the kind implies: a wish list hides (the surprise is
 * theirs), a gift list about somebody else shows (the owner is a co-giver, and
 * the recipient is not on the list at all). Storing the default instead would
 * make every list assert a preference nobody expressed, and a later change to
 * what the kind implies would silently not apply to any of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wishlists', function (Blueprint $table) {
            $table->boolean('owner_sees_claims')->nullable();
        });

        /*
         * The one value that was really about this column.
         *
         * `hidden_from_owner` said "the others coordinate; I see nothing",
         * which is exactly `owner_sees_claims = false`. Moved rather than
         * dropped: somebody chose it, and it is the only setting here whose
         * whole point is to withhold something.
         */
        DB::table('wishlists')
            ->where('claim_visibility', 'hidden_from_owner')
            ->update(['owner_sees_claims' => false, 'claim_visibility' => 'anonymous']);

        DB::statement('ALTER TABLE wishlists DROP CONSTRAINT IF EXISTS wishlists_claim_visibility_check');

        DB::statement(
            'ALTER TABLE wishlists ADD CONSTRAINT wishlists_claim_visibility_check '
            ."CHECK (claim_visibility IN ('anonymous', 'named'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE wishlists DROP CONSTRAINT IF EXISTS wishlists_claim_visibility_check');

        DB::table('wishlists')
            ->where('owner_sees_claims', false)
            ->update(['claim_visibility' => 'hidden_from_owner']);

        DB::statement(
            'ALTER TABLE wishlists ADD CONSTRAINT wishlists_claim_visibility_check '
            ."CHECK (claim_visibility IN ('anonymous', 'named', 'hidden_from_owner'))"
        );

        Schema::table('wishlists', function (Blueprint $table) {
            $table->dropColumn('owner_sees_claims');
        });
    }
};

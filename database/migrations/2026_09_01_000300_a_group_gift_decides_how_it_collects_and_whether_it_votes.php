<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things a group gift was deciding for everybody, now the organiser's.
 *
 * ## `pledge_amount` — everyone names their own, or everyone pays the same
 *
 * The pot only ever worked one way: each person types what they are putting in.
 * That is right for "chip in what you can" and wrong for the commonest group
 * gift there is — twelve colleagues, €10 each, done. Asking twelve people to
 * type the same number is twelve chances to get a different one, and the
 * organiser then chases the two who put in €5.
 *
 * Null is "everyone names their own", which is what every existing pot does and
 * what a new one does until somebody says otherwise. An integer is the standard
 * share **in cents** (invariant #7), and the member's form stops asking: they
 * are in for that, or they are not in.
 *
 * Deliberately not a second boolean beside an amount. "Is there a standard
 * share" and "what is it" are one fact, and two columns would let them disagree
 * — a `true` with no amount is a form that cannot be filled in.
 *
 * ## `voting_enabled` — do the members choose the present?
 *
 * Voting has been on for every group list since it shipped, decided by
 * `ListKind::allowsVoting()`. That is a good default and a bad rule: half of
 * these lists are "we already know what we're buying, here it is, chip in", and
 * on those the vote buttons under each candidate invite a decision that has
 * been made — and a shortlist that reorders itself by tally while people are
 * reading it.
 *
 * Nullable, like `link_can_add` and `owner_sees_claims`: null means the
 * organiser has never been asked and the kind still answers, so no existing
 * list asserts a preference nobody expressed.
 *
 * Existing votes are not deleted when it is switched off. They are somebody's
 * opinion and the switch is not a judgement on it — turning it back on shows
 * the tally again, exactly as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wishlists', function (Blueprint $table) {
            $table->integer('pledge_amount')->nullable();
            $table->boolean('voting_enabled')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('wishlists', function (Blueprint $table) {
            $table->dropColumn(['pledge_amount', 'voting_enabled']);
        });
    }
};

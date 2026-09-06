<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Share with friends" — a row per person you actually chose.
 *
 * ## What this replaces, and why
 *
 * There was a `wishlists.show_to_friends` switch for a day: one boolean meaning
 * *everybody I am connected to sees this*. It never shipped, and the reason it
 * was pulled is worth keeping. The risk was real and it was silent — a
 * friendship is made by opening any share link, so the set of people that
 * switch published to was one somebody had never chosen and could not see. A
 * single tap on a box that read "my friends can see this" put a list in front
 * of a group whose membership had accumulated by accident.
 *
 * A boolean cannot express consent to an audience. This table can: one row per
 * person, created by picking a name, removable one at a time.
 *
 * ## Sharing is an invitation, and there are two of them
 *
 * A list reaches somebody's friends page if it was **shared with them** (this
 * table) or if they **opened its link**. Both are invitations — you picked
 * them, or you sent them the link and they followed it — and both are acts by
 * the owner rather than a standing setting. Nothing is published to a person
 * who has done neither.
 *
 * That is also what makes a group gift work without a rule of its own. Its
 * audience was always "the people who were sent the link", which is the second
 * case; it needs no switch, and none is offered.
 *
 * ## Not a permission
 *
 * Same discipline as `list_opens` and `friendships`, which this sits beside.
 * Reaching a list is its share token plus `visibility != private`, decided by
 * `SharedListController`. Nothing reads this table to answer who may open
 * anything: a share row makes a list *findable*, and un-sharing takes it off a
 * page rather than taking a link away. Turning sharing off on the list is what
 * does that, and it still does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wishlist_shares', function (Blueprint $table): void {
            $table->id();

            // Cascade on both sides: the row means nothing without both ends,
            // and a deleted list or account must leave no half-edges behind.
            $table->foreignUuid('wishlist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            // Sharing the same list with the same person twice is a nudge, not
            // a second grant. The upsert in ListSharer relies on this.
            $table->unique(['wishlist_id', 'user_id']);

            // The friends page reads it this way round: "what has been shared
            // with me", once per render.
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlist_shares');
    }
};

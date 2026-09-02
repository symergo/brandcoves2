<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Can everyone see who is chipping in?" — the organiser's choice, on a group
 * gift only.
 *
 * Until now a group pot said one number and one count to everybody but the
 * organiser: €140 from six people, and nothing about which six. That is the
 * right default and it was the only setting, which made it a rule — and it is
 * not one. Six colleagues buying a leaving present mostly want to know whether
 * the other five are actually in, and a pot that will not say is a pot somebody
 * has to chase by message.
 *
 * ## Names, never amounts
 *
 * This adds *who*, and nothing else. Amounts stay the organiser's alone
 * whatever this says, because a visible ladder is social pressure on whoever
 * put in least — the reasoning `ContributionView` has carried since it was
 * written, and the half of the privacy rule that is not a preference. "Anna,
 * Ben and four others are in" is coordination; "Anna €50, Ben €5" is a
 * comparison nobody asked to be entered into.
 *
 * ## Nullable, for the same reason `link_can_add` and `owner_sees_claims` are
 *
 * Null means the organiser has never been asked, and `Wishlist::
 * pledgersVisible()` answers false — the behaviour every existing pot already
 * has. Storing `false` instead would make every list assert a preference nobody
 * expressed, and would make a later change to the default silently skip all of
 * them.
 *
 * ## Group lists only
 *
 * A `mine` or `for_someone` list has no pot: `ListKind::allowsContributions()`
 * is the gate, and `ContributionView` returns null before this column is ever
 * read. The column is on `wishlists` rather than on a group-only table because
 * there is no group-only table — the kind is a column too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wishlists', function (Blueprint $table) {
            $table->boolean('pledgers_visible')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('wishlists', function (Blueprint $table) {
            $table->dropColumn('pledgers_visible');
        });
    }
};

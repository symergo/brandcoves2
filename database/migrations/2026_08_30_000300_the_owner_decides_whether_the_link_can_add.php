<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Can the people I sent this to put things on it?" — the owner's choice.
 *
 * It was decided by kind: a `for_someone` or `group` list took additions
 * straight on, a `mine` list sent them to the owner's approval queue. Sound
 * defaults, and the wrong shape for a question that turns on how well somebody
 * knows the people holding the link rather than on what the list is about. A
 * family gift list wants additions; a wish list shared with forty colleagues
 * does not; and the kind cannot tell those apart.
 *
 * Now that sharing *is* the link — one button, the same rights for everybody
 * who has it — this is the setting that says what those rights are. It is the
 * only one, which is the point: the viewer/editor select it replaced asked the
 * question once per person, and asking it once per list is both less work and
 * a truer description of what a link can express.
 *
 * ## Nullable, for the same reason `owner_sees_claims` is
 *
 * Null means the owner has never been asked, and
 * `Wishlist::linkCanAdd()` falls back to what the kind implies. Storing the
 * default instead would make every existing list assert a preference nobody
 * expressed, and a later change to what a kind implies would silently skip all
 * of them.
 *
 * ## What "no" means
 *
 * Not a refusal — the approval queue. `SuggestionController` already holds
 * additions as pending for the owner to accept or dismiss, which is what a
 * `mine` list has always done, and turning this off simply routes there. A
 * helper is never told their contribution was rejected by a setting; it goes
 * where the owner can see it.
 *
 * A hand-written item stays pending whatever this says. That is free text
 * arriving through a link that can be forwarded anywhere, and the queue is the
 * moderation control for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wishlists', function (Blueprint $table) {
            $table->boolean('link_can_add')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('wishlists', function (Blueprint $table) {
            $table->dropColumn('link_can_add');
        });
    }
};

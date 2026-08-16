<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Somebody drops out of a group, and the draw is repaired around them.
 *
 * ## Soft removal, and deliberately not `SoftDeletes`
 *
 * Two concrete reasons, both about this table specifically.
 *
 * `secret_santa_members.assigned_member_id` is an **encrypted text** column
 * with no foreign key — it cannot have one, because the value is ciphertext. So
 * a hard `DELETE` leaves whoever was assigned to the leaver pointing at an id
 * that no longer resolves: `SecretSantaController::mine()` does a `find()` and
 * null-coalesces, so their page would quietly stop naming anybody, with no
 * error anywhere and nothing in the logs. A soft removal keeps the row
 * resolvable while the repair runs and afterwards.
 *
 * And Laravel's `SoftDeletes` installs a **global scope**, which would silently
 * rewrite every existing query on this model — including `notify()` and the
 * roster — in a migration that is supposed to add a column. The precedent in
 * this codebase is the explicit pair: `Wishlist::items()` filters and
 * `allItems()` does not. `SecretSantaGroup::members()` now does the same.
 *
 * ## What is deliberately *not* here
 *
 * No new `SantaStatus`. The enum answers "what may happen next", and a group
 * with somebody removed is still simply Drawn — joining stays closed, and a
 * third value would be recording history in a column that decides behaviour.
 *
 * No `previous_assignments`. Year-on-year reuse wants last year's pairings, and
 * storing them as plain JSON would put the whole game in the clear and make the
 * encryption on `assigned_member_id` decorative — which is the exact defect
 * secret-santa.md records v1 having. That feature stays not built until it can
 * be done with an encrypted column.
 *
 * The `(group_id, email)` unique index is left alone: a removed member's
 * address stays taken, which is correct, because re-joining after a draw is
 * already refused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('secret_santa_members', function (Blueprint $table) {
            // When they left. Null for everybody who is still in.
            $table->timestampTz('removed_at')->nullable();

            // When their pairing was last swapped. Not used for behaviour —
            // this is so an organiser asking "did I already redraw them?" has
            // an answer, and so a repeated redraw is visible in support.
            $table->timestampTz('redrawn_at')->nullable();
        });

        /*
         * Partial, because every query that matters asks for the people still
         * in the group. A plain index on `removed_at` would be mostly nulls and
         * would not help the roster read at all.
         */
        DB::statement(
            'CREATE INDEX secret_santa_members_active_idx ON secret_santa_members (group_id) WHERE removed_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS secret_santa_members_active_idx');

        Schema::table('secret_santa_members', function (Blueprint $table) {
            $table->dropColumn(['removed_at', 'redrawn_at']);
        });
    }
};

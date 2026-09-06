<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Friends: the people whose lists you hold, and who hold yours.
 *
 * Sharing has been a link since invitations were dropped, which made it
 * frictionless and left both ends anonymous to each other. Somebody sends you
 * their wedding registry, you sign in to claim something off it, and neither of
 * you has any record that the other exists — so the next occasion starts from a
 * blank page and a hunt through a chat history for a URL.
 *
 * ## Two rows, not one ordered pair
 *
 * A friendship is symmetric, and it could be stored once with the lower id
 * first. Two rows instead, because every read is "who are *my* friends" and an
 * ordered pair turns that into a union of two queries against two columns —
 * which is the kind of thing that gets written correctly once and then wrongly
 * in the second place that needs it. The cost is remembering to write and
 * delete both, which lives in `App\Services\Social\Friends` and nowhere else.
 *
 * ## Not a permission
 *
 * Same discipline as `list_opens`, which this sits beside: access to a list is
 * its token plus its visibility, decided by `SharedListController`. Nothing
 * reads this table to decide whether somebody may look at anything, and a
 * friendship left behind after sharing is turned off grants nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('friendships', function (Blueprint $table): void {
            $table->id();

            // Cascade on both sides: a deleted account leaves no half-edges
            // behind, and the row means nothing without both people.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('friend_id')->constrained('users')->cascadeOnDelete();

            /*
             * How the two met, so the page can say it.
             *
             * "You opened their list" is a very different sentence from "they
             * opened yours", and a friend list that cannot explain why a name
             * is on it reads as a leak even when it is not. A string with a
             * CHECK rather than a native enum, per the project convention:
             * altering a PG enum cannot run inside a transaction.
             */
            $table->string('source', 32)->default('shared_list');

            $table->timestamps();

            // One edge per direction per pair.
            $table->unique(['user_id', 'friend_id']);
            // "Who has me?" — for the cascade and for a future notification.
            $table->index('friend_id');
        });

        // Nobody is their own friend. Cheap to state, and the alternative is a
        // self-edge on every page that lists people.
        DB::statement('ALTER TABLE friendships ADD CONSTRAINT friendships_not_self CHECK (user_id <> friend_id)');

        DB::statement(
            "ALTER TABLE friendships ADD CONSTRAINT friendships_source_check
             CHECK (source IN ('shared_list', 'invited'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('friendships');
    }
};

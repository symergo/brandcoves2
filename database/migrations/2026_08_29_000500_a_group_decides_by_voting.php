<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 of the list taxonomy: the group decides which present to buy.
 *
 * `ListKind::allowsVoting()` has existed since group lists shipped and nothing
 * ever asked it. Without this a group list is a shortlist with no way to choose
 * from it — the money pools fine, and the decision happens in the group chat,
 * which is the half of the problem the feature exists to solve.
 *
 * ## A direct mirror of `gift_pledges`
 *
 * Same dual identity, same partial uniques, same reasoning: a member of an
 * office group joins by link and never signs up, so a vote has to be able to
 * belong to an anonymous cookie identity. `Owner::attributes()` and
 * `Owner::scope()` take column names precisely so both tables can use
 * `user_id`/`anon_id` while `wishlists` uses `owner_user_id`/`owner_anon_id`.
 *
 * ## Approval voting, one row per person per item
 *
 * Any member may vote for any candidate. Not "pick one favourite", which forces
 * a decision the group has not made yet — the shortlist exists *because* nobody
 * has decided. Not yes/no/maybe either: that is more state per item than five
 * candidates need, and it invites an argument about what "maybe" counts for.
 *
 * The uniqueness is in the **database**, not in an `updateOrCreate`. That is a
 * read-then-write, and two taps on a phone that has not finished the first
 * request is the ordinary case rather than an exotic one — the same reasoning
 * as the claim being a conditional UPDATE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('list_item_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('wishlist_items')->cascadeOnDelete();

            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->uuid('anon_id')->nullable();

            $table->timestamps();

            $table->foreign('anon_id')->references('id')->on('anonymous_identities')->cascadeOnDelete();
            $table->index('item_id');
        });

        // Exactly one owner per row, as everywhere an identity can be either.
        DB::statement('ALTER TABLE list_item_votes ADD CONSTRAINT list_item_votes_one_voter CHECK (num_nonnulls(user_id, anon_id) = 1)');

        // One vote per person per item. Pressing again takes it back rather
        // than adding a second — enforced here so a double tap cannot.
        DB::statement('CREATE UNIQUE INDEX list_item_votes_user_idx ON list_item_votes (item_id, user_id) WHERE user_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX list_item_votes_anon_idx ON list_item_votes (item_id, anon_id) WHERE anon_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('list_item_votes');
    }
};

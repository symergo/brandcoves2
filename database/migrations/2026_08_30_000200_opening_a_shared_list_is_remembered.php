<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Following a `/l/{token}` link leaves a trace, so the list can be found again.
 *
 * The open half of Phase 2 in `list-taxonomy.md`, and what finally forced it:
 * sharing is a **link** now rather than a list of email addresses, and
 * `ListAccess::scope()` unioned owned lists with *invited* ones only. Take the
 * invitations away and Shared Lists — a whole nav entry — is permanently empty,
 * because opening a link recorded nothing.
 *
 * That was already the bug for anybody who was sent a link rather than an
 * invitation, which is most people: the list vanished the moment the message
 * did. It just did not have a nav entry pointing at the hole.
 *
 * ## Not `wishlist_collaborators`
 *
 * That table's `user_id` is `NOT NULL`, and the people this is for are
 * frequently signed out — claiming needs no account by design, and requiring
 * one to *see a list somebody sent you* would be worse. The dual identity here
 * is the one `gift_pledges` and `list_item_votes` already use.
 *
 * The semantics differ too, and that is the better reason. A collaborator was
 * *granted* something by name; this records that somebody *arrived*. Overloading
 * one table with both would make "who did the owner invite" unanswerable.
 *
 * ## Two timestamps
 *
 * `first_opened_at` never moves, so "when did this list come into my world" has
 * an answer. `last_opened_at` is what the index sorts by, because a list read
 * this morning belongs above one opened once in March.
 *
 * Deliberately **not** a view counter. A count per person per list is analytics
 * about somebody reading a gift list, which is exactly the sort of thing this
 * product does not collect — and on a `mine` list it would edge towards telling
 * an owner how often each person looked, which is claim state by another route.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('list_opens', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('wishlist_id')->constrained()->cascadeOnDelete();

            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->uuid('anon_id')->nullable();

            $table->timestampTz('first_opened_at');
            $table->timestampTz('last_opened_at');

            $table->foreign('anon_id')->references('id')->on('anonymous_identities')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE list_opens ADD CONSTRAINT list_opens_one_reader CHECK (num_nonnulls(user_id, anon_id) = 1)');

        /*
         * One row per person per list, and **not** a pair of partial indexes.
         *
         * The write is an upsert, so a reader refreshing the page does not
         * accumulate rows — and Postgres cannot infer `ON CONFLICT` from a
         * partial index unless the statement repeats the index's `WHERE`, which
         * Eloquent's `upsert()` does not emit. Two partials looked right,
         * matched the shape used by `gift_pledges`, and 500'd on every shared
         * list the moment they were written against.
         *
         * `NULLS NOT DISTINCT` (PG15+, and we are on 16) makes one plain index
         * do it: exactly one of the two identity columns is ever set, and with
         * nulls colliding the triple is unique per person per list either way.
         */
        DB::statement('CREATE UNIQUE INDEX list_opens_reader_idx ON list_opens (wishlist_id, user_id, anon_id) NULLS NOT DISTINCT');

        // "Which lists have I opened, most recent first" — the Shared Lists
        // query, which is the only reason this table exists.
        DB::statement('CREATE INDEX list_opens_recent_user_idx ON list_opens (user_id, last_opened_at DESC) WHERE user_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('list_opens');
    }
};

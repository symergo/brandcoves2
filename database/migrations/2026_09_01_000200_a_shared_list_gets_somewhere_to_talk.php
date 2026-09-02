<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A discussion board on a shared list.
 *
 * Everything the people around a list needed to say to each other happened
 * somewhere else — the group chat the link was pasted into. So the page that
 * knows who has claimed what, what the pot stands at and what is still
 * unspoken-for could not answer "shall we go halves on the coat?", and the
 * conversation that decides the buying ran in a window with none of the facts
 * in it.
 *
 * A short thread beside the list, in a rail. Not comments *on items* — that was
 * considered and dropped for the reason the per-item pledge was: a list is six
 * cards, and a thread under each turns a page you scan into six pages you read.
 * One board per list is what the conversation actually is.
 *
 * ## Who may read it
 *
 * `Wishlist::shouldHideClaimsFrom()`, the same gate as everything else that
 * would spoil a surprise — see invariant #4 and `App\Services\Wishlist\Board`.
 * A board is free text written by co-givers, and "I've got the scarf, someone
 * take the boots" is claim state in prose. On a wish list whose owner has not
 * asked to see claims, the owner therefore does not see the board at all; on a
 * list about somebody else the owner is a co-giver and reads it like anybody.
 *
 * ## Dual identity, like every other row a visitor writes
 *
 * `user_id` xor `anon_id`, a CHECK enforcing exactly one, both cascading — the
 * shape `gift_pledges` and `list_item_votes` already use, because a list works
 * before signup and the typical author is an anonymous cookie identity.
 *
 * `display_name` is typed per message rather than read from the account, for
 * the reason the pledge does it: the people on a shared list know each other by
 * first name, and half of them have no account to take a name from.
 *
 * ## No screening, deliberately
 *
 * `Community\PostScreen` holds anything with a link or a phone number in it,
 * which is right on a public board answered by strangers and wrong here: this
 * is four people who were sent a link by a friend, and "call me on 06…" is the
 * ordinary case rather than the abuse. Deletion is the moderation control — the
 * author may take their own message back, and the list's owner may remove any
 * of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('list_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('wishlist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('anon_id')->nullable()->constrained('anonymous_identities')->cascadeOnDelete();
            $table->string('display_name');
            $table->text('body');
            $table->timestamps();

            // The board is read in one query, oldest first, per list.
            $table->index(['wishlist_id', 'id']);
        });

        DB::statement(
            'ALTER TABLE list_messages ADD CONSTRAINT list_messages_one_author
             CHECK (num_nonnulls(user_id, anon_id) = 1)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('list_messages');
    }
};

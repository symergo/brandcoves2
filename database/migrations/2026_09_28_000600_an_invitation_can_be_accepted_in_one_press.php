<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The button in an invitation email signs a new invitee straight in
 * (owner, 2026-09-27). See docs/features/friend-invite-mail.md, "Accepting
 * in one press".
 *
 * `friend_invite_tokens`: one row per invitation email that went out. Only a
 * sha256 of the token is stored, as for `login_tokens`, so a database leak
 * does not hand out live sign-in buttons. `email` is the invited address,
 * lower-cased: it is who the account is created for. Its own table rather
 * than a column on `friend_invites`, because an email also goes to addresses
 * that already have an account (which have no `friend_invites` row, and must
 * get the same email), and because `friend_invites` rows are deleted the
 * moment the person signs in any other way.
 *
 * Deleted once used or expired (`giftcoves.invites.accept_days`, 14) by
 * bc:prune-personal-data.
 *
 * ## Safe on production
 *
 * A new table only, nothing existing is touched. Guarded with hasTable,
 * because a failing migration is an outage here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('friend_invite_tokens')) {
            return;
        }

        Schema::create('friend_invite_tokens', function (Blueprint $table): void {
            $table->id();
            // Gone with the member: an invitation from nobody accepts nothing.
            $table->foreignId('inviter_id')->constrained('users')->cascadeOnDelete();
            $table->string('email', 254);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // What the pruner looks for.
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('friend_invite_tokens');
    }
};

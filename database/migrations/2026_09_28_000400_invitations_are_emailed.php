<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inviting somebody by email sends them an email (owner's request,
 * 2026-09-26). See docs/features/friend-invite-mail.md.
 *
 * Three new tables. None of them holds an email address: each holds a keyed
 * hash of the lower-cased address (App\Services\Social\InviteMailer::hash),
 * which is enough to answer "have we seen this address before?" and not
 * enough to write to anybody.
 *
 * ## `friend_invite_mails` — every invitation a member made
 *
 * One row per address a member invited, whether an email went out or not
 * (`status`). It answers the two limits: how many addresses this member
 * invited in the last day, and whether they already invited this one in the
 * last thirty. Kept 90 days (bc:prune-personal-data).
 *
 * `status` is a string with a CHECK, not a Postgres enum, as everywhere here:
 * - `sent`: the email was queued;
 * - `suppressed`: the address asked for no invitations, so nothing was sent;
 * - `sender_muted`: enough people called this member's invitations spam that
 *   theirs are no longer emailed.
 * The member is told the same thing in all three cases.
 *
 * ## `invite_suppressions` — "send me no invitations"
 *
 * One row per address that pressed "this is spam". No invitation email from
 * anybody reaches it again, until the same person presses undo.
 *
 * ## `invite_complaints` — against whom
 *
 * One row per (member, address): the same person pressing twice is one
 * complaint. After `giftcoves.invites.complaint_limit` of them the member's
 * invitations stop being emailed, and admins see them at /admin,
 * Community > Invitation complaints. Kept a year.
 *
 * ## Safe on production
 *
 * New tables only, nothing existing is touched. Guarded with hasTable, because
 * a failing migration is an outage here (Coolify stops the old containers
 * before `migrate` runs).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('friend_invite_mails')) {
            Schema::create('friend_invite_mails', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('inviter_id')->constrained('users')->cascadeOnDelete();
                $table->string('email_hash', 64);
                $table->string('status', 16);
                $table->timestamp('created_at')->useCurrent();

                // "How many today" and "this one already?" are both per member.
                $table->index(['inviter_id', 'created_at']);
                $table->index(['inviter_id', 'email_hash']);
            });

            DB::statement(
                "ALTER TABLE friend_invite_mails ADD CONSTRAINT friend_invite_mails_status_check
                 CHECK (status IN ('sent', 'suppressed', 'sender_muted'))"
            );
        }

        if (! Schema::hasTable('invite_suppressions')) {
            Schema::create('invite_suppressions', function (Blueprint $table): void {
                $table->id();
                $table->string('email_hash', 64)->unique();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (! Schema::hasTable('invite_complaints')) {
            Schema::create('invite_complaints', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('inviter_id')->constrained('users')->cascadeOnDelete();
                $table->string('email_hash', 64);
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['inviter_id', 'email_hash']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invite_complaints');
        Schema::dropIfExists('invite_suppressions');
        Schema::dropIfExists('friend_invite_mails');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Nodig uit op GiftCoves" on a saved person (owner's request, 2026-09-27).
 * See docs/features/my-people.md and friend-invite-mail.md.
 *
 * An invitation to an address with no account waits in `friend_invites` until
 * that person signs in. When the member sent it from a saved person (Mama, a
 * `recipients` row), the friendship made at that sign-in must land on that
 * same person, or Mama and her new account show up as two people on My
 * people. `recipient_id` remembers which saved person the invitation was for.
 *
 * Nullable, because every invitation made so far and every one made from the
 * plain "Nodig uit" form names nobody. `nullOnDelete`: deleting the saved
 * person before they sign in leaves the invitation standing, as it would have
 * been without one.
 *
 * ## Safe on production
 *
 * One nullable column and a foreign key on a small table, no backfill and no
 * default to write. Guarded with hasColumn, because a failing migration is an
 * outage here (Coolify stops the old containers before `migrate` runs).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('friend_invites', 'recipient_id')) {
            return;
        }

        Schema::table('friend_invites', function (Blueprint $table): void {
            $table->foreignUuid('recipient_id')->nullable()->constrained('recipients')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('friend_invites', 'recipient_id')) {
            return;
        }

        Schema::table('friend_invites', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('recipient_id');
        });
    }
};

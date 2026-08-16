<?php

declare(strict_types=1);

use App\Enums\CollaboratorRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An invitation to help choose, that survives the person not having an account.
 *
 * `WishlistCollaboratorController::store()` looked a `User` up by email and did
 * **nothing** when there was no match — while returning "If they have an
 * account, they can see this list now." The owner was told something happened
 * when nothing had. That is most of the time, too: the person you want help
 * from is exactly the person who has not signed up yet.
 *
 * A row here is the promise. It is redeemed when the address signs in, at which
 * point it becomes an ordinary `wishlist_collaborators` row and this one is
 * marked claimed.
 *
 * ## The response must stay identical either way
 *
 * `WishlistCollaboratorTest::inviting_does_not_reveal_whether_an_address_has_an
 * _account` locks this in, and it is the reason both branches now write a row
 * and send a mail rather than only one of them doing so. Otherwise the form is
 * a way to test which of your friends use the site.
 *
 * ## Email is stored, and that is a deliberate cost
 *
 * We hold an address for somebody who has not signed up. It is the minimum the
 * feature can work with, it is deleted when the list is, and
 * `bc:prune-personal-data` takes the stale ones — the alternative is a token
 * with no idea who it was for, which cannot be re-sent and cannot be shown back
 * to the owner as "you invited these people".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('list_invitations', function (Blueprint $table) {
            $table->id();

            $table->foreignUuid('wishlist_id')->constrained('wishlists')->cascadeOnDelete();

            // Who sent it, so a claim can be refused if they have since lost the
            // list, and so the mail can say who is asking.
            $table->foreignId('invited_by_user_id')->constrained('users')->cascadeOnDelete();

            // Lowercased on write. The lookup is case-insensitive and storing
            // the typed casing would mean two invitations for one person.
            $table->string('email', 190);

            $table->string('role')->default(CollaboratorRole::Viewer->value);

            // The capability. Single-use: `claimed_at` closes it.
            $table->uuid('token')->unique();

            $table->timestampTz('expires_at');
            $table->timestampTz('claimed_at')->nullable();

            $table->timestamps();

            // "Have I already invited this address to this list?" — one open
            // invitation per address per list, re-sent rather than duplicated.
            $table->unique(['wishlist_id', 'email']);

            // The claim path: every address this person was invited under.
            $table->index(['email', 'claimed_at']);
        });

        DB::statement(
            'ALTER TABLE list_invitations ADD CONSTRAINT list_invitations_role_check CHECK (role IN ('
            .implode(', ', array_map(fn (string $r) => "'".$r."'", CollaboratorRole::values()))
            .'))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('list_invitations');
    }
};

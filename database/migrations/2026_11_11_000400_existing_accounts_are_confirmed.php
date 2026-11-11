<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every account that exists today counts as confirmed.
 *
 * From this release an unconfirmed address (`email_verified_at` null) gets no
 * mail of its own, no remember cookie and no waiting invitations: that is the
 * state of an account made by typing a new address into the sign-in form. See
 * docs/features/auth.md, "Unconfirmed accounts".
 *
 * Until now an account could only be made by pressing a mailed link or through
 * Google, both of which prove the address, so a null here is an older row from
 * before the column was filled, not an unproven one. Without this they would
 * silently stop getting price alerts and reminders.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        // Not reversible: which rows were null is not kept, and nothing needs it.
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Machine credentials for the editorial API.
 *
 * The reason this is not "log in as an admin": the panel authenticates a person
 * with a password and a session, and a session is exactly the wrong shape for
 * an automated writer. A token is revocable on its own, carries only the
 * abilities it was minted with, and leaves a `last_used_at` trail that says
 * whether the thing on the other end is still running.
 *
 * Only the hash is stored, as with LoginToken: a database leak hands an
 * attacker a list of names and timestamps, not a working key to the content.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();

            // What this key is for, in a sentence a human will read in six
            // months when deciding whether it is safe to revoke.
            $table->string('name');

            $table->string('token_hash', 64)->unique();

            /*
             * Abilities, not roles.
             *
             * The interesting distinction here is write-vs-publish: a token that
             * may draft a Cove but not approve one is the normal case, and it is
             * only expressible if the two are separate strings. A role called
             * "editor" would collapse them the first time someone needed the
             * safer variant.
             */
            $table->jsonb('abilities')->default(DB::raw("'[]'::jsonb"));

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Written on use, and deliberately not on every request — see
            // ApiToken::touchUsage() for why once a minute is enough.
            $table->timestampTz('last_used_at')->nullable();

            // Null means no expiry. Allowed, because the alternative is a key
            // that silently stops working at 03:00 and a daily column that
            // quietly stops appearing.
            $table->timestampTz('expires_at')->nullable();

            // Revocation is a timestamp rather than a delete: knowing a key
            // existed and when it was killed is the first thing anyone wants
            // during an incident.
            $table->timestampTz('revoked_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};

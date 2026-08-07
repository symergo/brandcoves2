<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Magic-link login tokens.
 *
 * A dedicated table rather than Laravel's signed URLs, because a signed URL is
 * replayable for its whole lifetime. A login link ends up in an inbox, in
 * forwarded mail, and in the access logs of every proxy it passes through —
 * it has to die on first use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            // SHA-256 of the token. The plaintext only ever exists in the email,
            // so a database leak does not hand over live login links.
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            // Recorded to make a stolen-link investigation possible: a
            // consumption from a different network than the request is a signal.
            $table->string('requested_ip', 45)->nullable();
            $table->timestamps();

            $table->index(['email', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_tokens');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sign-in is passwordless, so the account starts with neither a name nor a
 * password — only a verified email address.
 *
 * Laravel's default users table requires both. A name is something we may learn
 * later (from Google, or because the person tells us); a password is something
 * we never want at all. This site holds gift lists and email addresses, not
 * payment details, and a stored password is a liability people reuse elsewhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carry a name from the sign-in form to the account it creates.
 *
 * A magic link is the entire registration flow here, so there is no other
 * moment to ask. Without it every account starts nameless, and a wishlist
 * shared with friends cannot say whose it is — which is how a shared list
 * ended up announcing that it belonged to "Saved items".
 *
 * On the token rather than in the session: the link is often opened in a
 * different browser from the one that asked for it, and a session cannot
 * follow somebody from their laptop to their phone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_tokens', function (Blueprint $table) {
            $table->string('name')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('login_tokens', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};

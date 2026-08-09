<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "I've bought it, it's on its way."
 *
 * Distinct from a claim: claiming stops two people buying the same thing, and
 * this says the buying actually happened. Between the two sits the case that
 * matters — an item claimed weeks ago by somebody who then forgot, which reads
 * as covered and is not.
 *
 * Carries the same secret as `claimed_by_hash` and is therefore `$hidden`
 * alongside it. A "sent" flag visible to the list owner spoils the surprise
 * just as completely as the claim did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wishlist_items', function (Blueprint $table) {
            $table->timestampTz('marked_sent_at')->nullable()->after('claimed_at');
        });
    }

    public function down(): void
    {
        Schema::table('wishlist_items', function (Blueprint $table) {
            $table->dropColumn('marked_sent_at');
        });
    }
};

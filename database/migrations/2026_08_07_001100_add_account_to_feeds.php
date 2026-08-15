<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which affiliate account a feed belongs to.
 *
 * An advertiser can only be reached through the publisher account that is
 * actually joined to them. Vanden Borre sits under a different Awin account
 * from Coolblue and Krefel, and its feeds are invisible to the primary
 * account's API key — not "Not Joined", simply absent from the list.
 *
 * So the credential is a property of the FEED, not of the connector. One global
 * token can only ever see one account's advertisers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feeds', function (Blueprint $table) {
            // Keys into config('giftcoves.connectors.awin.accounts').
            $table->string('account')->default('default')->after('source');
            $table->index(['source', 'account']);
        });
    }

    public function down(): void
    {
        Schema::table('feeds', function (Blueprint $table) {
            $table->dropIndex(['source', 'account']);
            $table->dropColumn('account');
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The name the list's owner gave an item (owner, 2026-11-07: "allow everywhere
 * to change the name of an item on a list").
 *
 * A column of its own rather than a rewrite of `snapshot_title`: on a product
 * from the catalogue the snapshot is the record of what the shop said when it
 * was saved, and price history is read against it (WishlistItemController::
 * update). Null means "the product's own name", which is every existing row.
 * Additive and nullable, so it is safe ahead of the code that reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wishlist_items', function (Blueprint $table) {
            $table->string('custom_title', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('wishlist_items', function (Blueprint $table) {
            $table->dropColumn('custom_title');
        });
    }
};

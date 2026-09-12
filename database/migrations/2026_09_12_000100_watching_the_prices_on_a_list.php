<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Watching the prices on a whole list.
 *
 * Three nullable columns, all additive, so a rollback meets nothing it cannot
 * read and the deploy migration cannot fail on existing rows.
 *
 * - `wishlists.price_watch_percent`: the drop, in whole percent, the owner
 *   wants to hear about. Null is off. The allowed values live in
 *   App\Services\Alerts\ListPriceWatch, not in a CHECK, because they are a
 *   product choice that will move, and moving a CHECK is a deploy hazard.
 * - `wishlist_items.watch_reference_price`: cents (invariant #7). The price a
 *   drop is measured against; it moves to the current price every time a
 *   change is reported. Null on a seeded item means "had no trackable price",
 *   which is what later becomes "available again".
 * - `wishlist_items.watch_seeded_at`: when the reference was first taken. An
 *   unseeded item is seeded silently, never reported, so switching the watch
 *   on does not mail a drop that happened last month.
 *
 * See docs/features/list-price-watch.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wishlists', function (Blueprint $table): void {
            $table->smallInteger('price_watch_percent')->nullable();
        });

        Schema::table('wishlist_items', function (Blueprint $table): void {
            $table->integer('watch_reference_price')->nullable();
            $table->timestampTz('watch_seeded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('wishlist_items', function (Blueprint $table): void {
            $table->dropColumn(['watch_reference_price', 'watch_seeded_at']);
        });

        Schema::table('wishlists', function (Blueprint $table): void {
            $table->dropColumn('price_watch_percent');
        });
    }
};

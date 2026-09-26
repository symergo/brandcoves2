<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A hand-written item can carry a barcode, and remembers whether its link has
 * been read.
 *
 * Roadmap step 1 ("anything goes in", docs/strategy.md): a link somebody pastes
 * is now looked up — in our own catalogue, through a connector, or by reading
 * the shop's page in a queued job — instead of being kept as a bare link. See
 * docs/features/pasted-links.md.
 *
 * - `link_status` says where that lookup is. Null for every item that never had
 *   a link to read, which is every row written before this migration, so no
 *   backfill: an old manual item is simply one nobody asked us to read.
 * - `gtin` is the barcode of something scanned that we did not know yet. Kept
 *   so the item can join its product the day a shop starts selling it.
 *
 * Expand-only: both nullable, nothing old reads them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wishlist_items', function (Blueprint $table) {
            $table->string('link_status', 16)->nullable();
            $table->string('gtin', 13)->nullable();
        });

        // String + CHECK, never a native enum (see CLAUDE.md conventions).
        DB::statement(<<<'SQL'
            ALTER TABLE wishlist_items ADD CONSTRAINT wishlist_items_link_status_check
            CHECK (link_status IS NULL OR link_status IN ('pending', 'read', 'linked', 'skipped', 'failed'))
        SQL);

        // The nightly pass looks for manual items with a barcode and no product.
        DB::statement(<<<'SQL'
            CREATE INDEX wishlist_items_waiting_gtin_idx ON wishlist_items (gtin)
            WHERE gtin IS NOT NULL AND group_id IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS wishlist_items_waiting_gtin_idx');

        Schema::table('wishlist_items', function (Blueprint $table) {
            $table->dropColumn(['link_status', 'gtin']);
        });
    }
};

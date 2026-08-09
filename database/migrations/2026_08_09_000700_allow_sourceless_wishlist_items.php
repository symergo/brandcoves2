<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let a list hold a product we do not have a stored group for.
 *
 * The column was always nullable and the unique index was always partial
 * (`WHERE group_id IS NOT NULL`) — the schema anticipated this from the start.
 * Only the controller insisted on a stored `ProductGroup`, which meant a live
 * bol result or an Amazon product could be searched for and then not saved.
 *
 * The identity of such an item is `(source, external_id)`, and it needs the same
 * "once per list" guarantee the grouped items have, or a double-tap adds it
 * twice.
 *
 * Amazon additionally may not be mirrored (invariant #6), so its rows carry the
 * ASIN and nothing else: no title, no image, no price. See
 * `WishlistItem::rendersLive()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wishlist_items', function (Blueprint $table) {
            $table->string('source')->nullable()->after('group_id');
            $table->string('external_id')->nullable()->after('source');
        });

        // Mirrors `wishlist_items_list_group_idx` for the ungrouped case.
        DB::statement('CREATE UNIQUE INDEX wishlist_items_list_source_idx ON wishlist_items (wishlist_id, source, external_id) WHERE group_id IS NULL AND source IS NOT NULL');

        /*
         * An item must be *something*: either a group we hold, or an external
         * product we can identify well enough to re-fetch. A row that is neither
         * is an entry nobody can render and nobody can remove on purpose.
         *
         * Widened one case in 2026_08_09_001000 to also allow a free-text wish —
         * see that migration for why this version was too strict.
         */
        DB::statement('ALTER TABLE wishlist_items ADD CONSTRAINT wishlist_items_identifiable CHECK (group_id IS NOT NULL OR (source IS NOT NULL AND external_id IS NOT NULL))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE wishlist_items DROP CONSTRAINT IF EXISTS wishlist_items_identifiable');
        DB::statement('DROP INDEX IF EXISTS wishlist_items_list_source_idx');

        Schema::table('wishlist_items', function (Blueprint $table) {
            $table->dropColumn(['source', 'external_id']);
        });
    }
};

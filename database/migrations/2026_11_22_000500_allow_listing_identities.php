<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A third kind of product identity: one marketplace listing (2026-11-22).
 *
 * eBay's search results carry neither a barcode nor a brand, so they got no identity and never
 * showed in search until their item detail was fetched in the background. The owner wanted them
 * visible straight after the live search, so an eBay offer without either now becomes a product of
 * its own, keyed on its listing (`listing:ebay:{item id}`), and is merged into the real product once
 * its detail gives one (FetchEbayItemDetails, GroupMerger). See docs/features/ebay-connector.md.
 *
 * The values are written out, not read from IdentityKind: a migration must keep meaning what it
 * meant when it ran. Added NOT VALID and validated separately, so the check of six million offers
 * does not hold writes on `products` (VALIDATE only takes a lock that lets them through).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        foreach (['products' => "identity_kind IS NULL OR identity_kind IN ('ean', 'title', 'listing')", 'product_groups' => "identity_kind IN ('ean', 'title', 'listing')"] as $table => $check) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_identity_kind_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_identity_kind_check CHECK ({$check}) NOT VALID");
            DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$table}_identity_kind_check");
        }
    }

    public function down(): void
    {
        // Forward-only in practice: listing identities would violate the narrower check.
        foreach (['products' => "identity_kind IS NULL OR identity_kind IN ('ean', 'title')", 'product_groups' => "identity_kind IN ('ean', 'title')"] as $table => $check) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_identity_kind_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_identity_kind_check CHECK ({$check}) NOT VALID");
        }
    }
};

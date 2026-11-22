<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The 1.3 GB index of whole shop URLs goes (2026-11-22).
 *
 * It served one lookup, a pasted link from a feed shop matched on its path, and was
 * not used once between 2026-10-25 and 2026-11-22. Shops with a product id in their
 * links are now found by that id (previous migration); the few small shops without one
 * keep the path comparison, which reads only that shop's offers. Dropped CONCURRENTLY,
 * so reads and writes go on meanwhile; hence no transaction.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS products_deep_link_prefix_idx');
    }

    public function down(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS products_deep_link_prefix_idx ON products'
            ." (merchant_deep_link text_pattern_ops) WHERE status = 'active' AND group_id IS NOT NULL AND octet_length(merchant_deep_link) < 2000");
    }
};

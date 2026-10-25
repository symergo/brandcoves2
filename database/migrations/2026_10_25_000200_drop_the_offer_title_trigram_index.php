<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drop the trigram index on `products.title` (2026-10-25).
 *
 * It had grown to 1,075 MB, the second-largest index in the database, and only
 * the admin offer search read it. That search now finds products by title
 * through `product_groups_title_trgm_idx` and their offers by `group_id`; see
 * the comment in ProductsTable. Every other title search on the site already
 * runs on `product_groups`.
 *
 * Every insert and title change on an offer had to update this index too, so
 * ingestion also gets a little cheaper.
 *
 * CONCURRENTLY, so no write waits on the drop, which is why this cannot run in
 * a transaction. IF EXISTS, so a re-run is harmless: a failing migration is an
 * outage, because Coolify stops the old containers before `migrate` runs.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS products_title_trgm_idx');
    }

    public function down(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS products_title_trgm_idx ON products USING GIN (title gin_trgm_ops)');
    }
};

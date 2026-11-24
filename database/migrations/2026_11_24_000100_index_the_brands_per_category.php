<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An index for the "other brands" links: brands per category (2026-11-24).
 *
 * OtherBrandLinks::brandsIn() counts the in-stock products per (category, brand).
 * It used the category rail index, which holds every in-stock product in the
 * category, branded or not, so the table rows had to be read to find the brand.
 * For books in nl-nl that is 348,652 products, of which 9,538 carry a brand:
 * 132,000 pages (about 1 GB) read, 0.57 s with them in memory and over the
 * statement timeout without. On 2026-11-24 that timed out eight times in two
 * hours and took gift-idea pages down with it.
 *
 * Only branded, in-stock products, with the brand in the index itself, so the
 * count reads the index and the rows it names, never the unbranded mass.
 * Built CONCURRENTLY, so the table stays readable and writable meanwhile; hence no
 * transaction. IF NOT EXISTS because production got it by hand first: a deploy
 * runs migrations while the site is down, and this build takes a while.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS product_groups_brands_per_category_idx'
            .' ON product_groups (market, category, brand)'
            .' WHERE worth_showing = true AND in_stock = true AND brand IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS product_groups_brands_per_category_idx');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Let Postgres analyse `product_groups` again (2026-11-13).
 *
 * `bc_brand_key()` called `bc_unaccent()` without its schema. A session of the
 * app has `public` on its search path, so every query worked. Autovacuum does
 * not: it analyses a table with an empty search path, evaluates the expression
 * of `product_groups_market_brand_key_idx` for its statistics, and failed with
 * "function bc_unaccent(text) does not exist", once a minute, giving up on the
 * whole table each time. The planner was left with the statistics from before
 * the index existed, on the table every search and gift-idea page reads.
 *
 * Same body, with the schema named. The function's results do not change, so
 * the index built on it stays valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->define('public.bc_unaccent');

        // Do now what autovacuum has not been able to do since 2026-10-25.
        DB::statement('ANALYZE product_groups');
    }

    public function down(): void
    {
        $this->define('bc_unaccent');
    }

    private function define(string $unaccent): void
    {
        DB::statement(<<<SQL
            CREATE OR REPLACE FUNCTION bc_brand_key(text)
            RETURNS text
            LANGUAGE sql
            IMMUTABLE PARALLEL SAFE
            AS \$\$ SELECT regexp_replace(lower({$unaccent}(coalesce(\$1, ''))), '[^a-z0-9]+', '', 'g') \$\$
        SQL);
    }
};

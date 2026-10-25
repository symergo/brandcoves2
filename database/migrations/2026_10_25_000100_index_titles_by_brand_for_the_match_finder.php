<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Let the match finder compare titles within one brand (2026-10-25).
 *
 * MatchFinder::bySimilarTitle() asks, for each new product, "which products of
 * the same brand in the same market have a title like this one?". It used the
 * trigram index on `product_groups.title`, which covers every market and every
 * brand: Postgres found every similar title on the whole site and then threw
 * away the ones from another brand or market. On production that query
 * averaged 24.5 minutes a run over 267 runs, 109 hours of database time, for
 * about 3,000 lookups a night.
 *
 * Now the brand is found first, through this index, and similarity is worked
 * out only against that brand's products: a median of 46 titles, Samsung's
 * 6,031 at the most. On a copy of production, 300 lookups went from 14.8 s to
 * 0.48 s with the same pairs found. A GIN index of market, brand and trigrams
 * together (btree_gin) was tried first and only got to 5 s, because the
 * trigram lists of common words are long whatever the brand.
 *
 * The brand key needs a function marked IMMUTABLE to be indexed, and
 * `unaccent()` is not marked so, hence `bc_brand_key()` over `bc_unaccent()`,
 * the wrapper the search vector already uses. Partial on the products the
 * finder compares (not merged away, with offers).
 *
 * Built CONCURRENTLY, so nothing is locked while it builds; see
 * 2026_09_28_001200_indexes_for_the_slow_queries for the invalid-leftover
 * guard this repeats.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const NAME = 'product_groups_market_brand_key_idx';

    public function up(): void
    {
        // Accents off, lower case, letters and digits only: "Audio-Technica"
        // and "Audio Technica" give one key. MatchFinder::BRAND_KEY calls this.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION bc_brand_key(text)
            RETURNS text
            LANGUAGE sql
            IMMUTABLE PARALLEL SAFE
            AS $$ SELECT regexp_replace(lower(bc_unaccent(coalesce($1, ''))), '[^a-z0-9]+', '', 'g') $$
        SQL);

        $invalid = DB::scalar(
            'SELECT NOT i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid'
            .' WHERE c.relname = ? AND pg_table_is_visible(c.oid)',
            [self::NAME],
        );

        if ($invalid === true) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::NAME);
        }

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::NAME
            .' ON product_groups (market, bc_brand_key(brand))'
            .' WHERE merged_into_id IS NULL AND offer_count > 0');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::NAME);
        DB::statement('DROP FUNCTION IF EXISTS bc_brand_key(text)');
    }
};

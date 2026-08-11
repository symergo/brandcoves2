<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Descriptions become searchable, at weight D.
 *
 * `products.description` has been written on every ingest since the catalogue
 * existed and read by nothing: not the product page, not the card, and — the
 * part that cost us results — not the search index. Every fact a merchant states
 * about a product but leaves out of the title was unfindable. In the test
 * fixture alone, "ruisonderdrukking" appears in two descriptions and no title.
 *
 * ## Why D, and why that is enough on its own
 *
 * Postgres' default weight multipliers are A=1.0, B=0.4, C=0.2, D=0.1. A term in
 * the description is therefore worth a tenth of the same term in the title,
 * which is the correct ratio: a description mentioning "bluetooth" is weak
 * evidence next to a title that leads with it.
 *
 * Ranking does not actually read those weights — `SearchService::orderByRelevance()`
 * sorts on `word_similarity()` against the GROUP's title, because the vector
 * lives on offers and ranking on it would mean a correlated subquery per row.
 * That works in our favour here rather than against it: a product matched only
 * through its description scores near zero on title similarity and lands at the
 * back of the result set by construction. Description matches are a fallback
 * tier, which is exactly what they should be.
 *
 * What does change is recall. A common word in a description ("draadloos",
 * "garantie") now matches products it did not before, so result counts go up and
 * the tail of a long result set is weaker than it used to be. That is the trade
 * being made deliberately, not a side effect.
 *
 * ## Why the column is dropped and re-added
 *
 * `search_vector` is a STORED generated column. Postgres 16 has no way to alter
 * a generation expression — `ALTER COLUMN ... SET EXPRESSION` arrived in 17 —
 * so the column has to go and come back. That is not a workaround with a cost
 * attached; it IS the backfill. Re-adding the column recomputes the vector for
 * every existing row, which a `CREATE OR REPLACE FUNCTION` on its own would
 * never do.
 *
 * The cost is an ACCESS EXCLUSIVE lock on `products` for a full table rewrite
 * plus a GIN rebuild — searches block for the duration. At the catalogue sizes
 * this runs against (tens of thousands of offers per market) that is seconds,
 * and `migrate` is already a one-shot service that runs before the new app
 * starts. Past a few million offers it stops being acceptable and this wants
 * revisiting as a second column plus `CREATE INDEX CONCURRENTLY` and a switch.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * A five-argument overload rather than a replacement: the existing
         * four-argument function cannot be dropped while the column still
         * depends on it, so both exist for the few statements in between.
         *
         * The description is truncated to 2000 characters. That is not a guess —
         * it is the cap BolConnector::description() already applies at its own
         * boundary, and matching it here keeps one verbose Awin advertiser from
         * contributing ten times more index than bol does for the same product.
         * Applying it in SQL rather than at each connector also covers the rows
         * already in the table.
         */
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION bc_search_vector(
                market text,
                title text,
                brand text,
                category text,
                description text
            )
            RETURNS tsvector
            LANGUAGE sql
            IMMUTABLE PARALLEL SAFE
            AS $$
                SELECT setweight(to_tsvector(bc_text_config(market), bc_unaccent(coalesce(title, ''))), 'A')
                    || setweight(to_tsvector(bc_text_config(market), bc_unaccent(coalesce(brand, ''))), 'B')
                    || setweight(to_tsvector(bc_text_config(market), bc_unaccent(coalesce(category, ''))), 'C')
                    || setweight(to_tsvector(bc_text_config(market), bc_unaccent(left(coalesce(description, ''), 2000))), 'D')
            $$
        SQL);

        DB::statement('DROP INDEX IF EXISTS products_search_vector_idx');
        DB::statement('ALTER TABLE products DROP COLUMN IF EXISTS search_vector');

        // The rewrite. Every row's vector is recomputed here.
        DB::statement(<<<'SQL'
            ALTER TABLE products
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (bc_search_vector(market, title, brand, merchant_category, description)) STORED
        SQL);

        DB::statement('CREATE INDEX products_search_vector_idx ON products USING GIN (search_vector)');

        // Nothing depends on the four-argument version now. Left in place it
        // would be an overload that resolves on a four-argument call, so a
        // future caller could silently get an index-inconsistent vector back.
        DB::statement('DROP FUNCTION IF EXISTS bc_search_vector(text, text, text, text)');
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION bc_search_vector(
                market text,
                title text,
                brand text,
                category text
            )
            RETURNS tsvector
            LANGUAGE sql
            IMMUTABLE PARALLEL SAFE
            AS $$
                SELECT setweight(to_tsvector(bc_text_config(market), bc_unaccent(coalesce(title, ''))), 'A')
                    || setweight(to_tsvector(bc_text_config(market), bc_unaccent(coalesce(brand, ''))), 'B')
                    || setweight(to_tsvector(bc_text_config(market), bc_unaccent(coalesce(category, ''))), 'C')
            $$
        SQL);

        DB::statement('DROP INDEX IF EXISTS products_search_vector_idx');
        DB::statement('ALTER TABLE products DROP COLUMN IF EXISTS search_vector');

        DB::statement(<<<'SQL'
            ALTER TABLE products
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (bc_search_vector(market, title, brand, merchant_category)) STORED
        SQL);

        DB::statement('CREATE INDEX products_search_vector_idx ON products USING GIN (search_vector)');

        DB::statement('DROP FUNCTION IF EXISTS bc_search_vector(text, text, text, text, text)');
    }
};

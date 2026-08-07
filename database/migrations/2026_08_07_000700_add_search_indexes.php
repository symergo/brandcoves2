<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Full-text search, in Postgres. No Elasticsearch.
 *
 * Two mechanisms, because they fail differently:
 *   - tsvector + GIN : ranked term matching, stemmed per language.
 *   - pg_trgm        : typo tolerance ("bleutooth"), and the similarity
 *                      clustering that turns raw queries into guide topics.
 */
return new class extends Migration
{
    public function up(): void
    {
        // unaccent() is declared STABLE, not IMMUTABLE, because it depends on a
        // dictionary that could in principle be changed. Postgres therefore
        // refuses it in a generated column or an index. Pinning the dictionary
        // explicitly makes the call genuinely immutable, which is the standard
        // workaround — without it, "creme" cannot match "crème".
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION bc_unaccent(text)
            RETURNS text
            LANGUAGE sql
            IMMUTABLE STRICT PARALLEL SAFE
            AS $$ SELECT public.unaccent('public.unaccent'::regdictionary, $1) $$
        SQL);

        // Stemming is language-specific: a Dutch stemmer will not reduce
        // "chaussures" to "chaussure". The market decides the dictionary.
        // Defined before bc_search_vector, which calls it — Postgres validates
        // SQL function bodies at creation time.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION bc_text_config(market text)
            RETURNS regconfig
            LANGUAGE sql
            IMMUTABLE STRICT PARALLEL SAFE
            AS $$
                SELECT CASE market
                    WHEN 'be-nl' THEN 'dutch'
                    WHEN 'nl-nl' THEN 'dutch'
                    WHEN 'be-fr' THEN 'french'
                    WHEN 'es'    THEN 'spanish'
                    ELSE 'english'
                END::regconfig
            $$
        SQL);

        // Weights: title A, brand B, category C — a term in the title should
        // always outrank the same term buried in a merchant's category string.
        //
        // NOTE: this function is baked into a stored generated column. Changing
        // it later does NOT rewrite existing rows; that needs an explicit
        // backfill migration.
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

        // A stored generated column, so the vector is computed once at write
        // time rather than on every search. Offers carry per-merchant titles and
        // categories, so indexing offers gives better recall than indexing the
        // group's single denormalised title.
        DB::statement(<<<'SQL'
            ALTER TABLE products
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (bc_search_vector(market, title, brand, merchant_category)) STORED
        SQL);

        DB::statement('CREATE INDEX products_search_vector_idx ON products USING GIN (search_vector)');

        // Trigram indexes for fuzzy matching. GIN over GiST: slower to build,
        // considerably faster to query, and this table is written by a batch job
        // and read by every visitor.
        //
        // IMPORTANT: query these with the word_similarity operator `<%`, NOT `%`.
        // `%` uses similarity(), which compares WHOLE strings — measured against
        // a real product title, "blutooth koptelefon" vs "Draadloze Bluetooth
        // Koptelefoon met ruisonderdrukking" scores 0.298, just under the 0.3
        // default, so the typo finds nothing. word_similarity() asks whether the
        // query matches some run of words *inside* the title and scores 0.696.
        // The same gin_trgm_ops index serves both operators.
        DB::statement('CREATE INDEX products_title_trgm_idx ON products USING GIN (title gin_trgm_ops)');
        DB::statement('CREATE INDEX product_groups_title_trgm_idx ON product_groups USING GIN (title gin_trgm_ops)');

        // Clusters near-duplicate queries into guide topics: "bluetooth speaker",
        // "bluetooth speakers", "blutooth speaker" are one topic, not three.
        DB::statement('CREATE INDEX search_log_query_trgm_idx ON search_log USING GIN (query gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS search_log_query_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS product_groups_title_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS products_title_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS products_search_vector_idx');
        DB::statement('ALTER TABLE products DROP COLUMN IF EXISTS search_vector');
        DB::statement('DROP FUNCTION IF EXISTS bc_search_vector(text, text, text, text)');
        DB::statement('DROP FUNCTION IF EXISTS bc_text_config(text)');
        DB::statement('DROP FUNCTION IF EXISTS bc_unaccent(text)');
    }
};

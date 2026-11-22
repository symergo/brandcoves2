<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A pasted shop link is found by the shop's product id in it (2026-11-22).
 *
 * `bc_shop_product_id(url)` reads the id from a stored deep link with the same patterns
 * as App\Services\PageReading\ShopProductId, which reads it from the pasted link; the two
 * are tested against each other (ShopProductIdTest). The index holds that id for active,
 * grouped offers that have one: about ten characters a row against 80 to 130 for the whole
 * URL that `products_deep_link_prefix_idx` held (1.3 GB, never used in four weeks), which
 * the next migration drops.
 *
 * Built CONCURRENTLY so the four-million-row table is not locked; hence no transaction. A
 * concurrent build that failed leaves an invalid index that IF NOT EXISTS would skip for
 * ever, so a leftover is dropped first (as in 2026_09_28_001200).
 *
 * Changing a pattern later means a new migration: replace the function, then rebuild the
 * index (REINDEX CONCURRENTLY), since stored values were computed with the old one.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEX = 'products_shop_product_id_idx';

    public function up(): void
    {
        /*
         * PL/pgSQL, not a SQL function. A SQL function with a sub-select is not inlined,
         * and Postgres set it up again for every row: 80 s over 713,000 offers locally,
         * which would have been about 12 minutes on production and 0.1 ms added to every
         * offer an ingestion writes (twice: the index's condition and its value). This one
         * keeps its plan between calls: 0.9 s for the same rows. The host comes from plain
         * string functions and a regular expression runs only for the shop whose host
         * matched, so bol's offers, most of the table, cost almost nothing. `r`, the rest
         * after the host, is what ShopProductId matches in PHP.
         */
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION bc_shop_product_id(url text) RETURNS text
            LANGUAGE plpgsql IMMUTABLE STRICT PARALLEL SAFE AS $fn$
            DECLARE
                p int := position('://' IN url);
                rest text;
                h text;
                r text;
            BEGIN
                IF p = 0 THEN
                    RETURN NULL;
                END IF;

                rest := substr(url, p + 3);
                h := lower(split_part(split_part(split_part(split_part(rest, '/', 1), '?', 1), '#', 1), ':', 1));
                IF h LIKE 'www.%' THEN
                    h := substr(h, 5);
                END IF;
                r := substr(rest, length(split_part(split_part(split_part(rest, '/', 1), '?', 1), '#', 1)) + 1);

                IF h IN ('coolblue.be', 'coolblue.nl') OR h LIKE '%.coolblue.be' OR h LIKE '%.coolblue.nl' THEN
                    RETURN lower(substring(r from '/(?:product|produit)/([0-9]+)'));
                ELSIF h = 'fnac.be' OR h LIKE '%.fnac.be' THEN
                    RETURN lower(substring(r from '/((?:a|mp)[0-9]{4,})(?:[/?#]|$)'));
                ELSIF h = 'shop.action.com' OR h LIKE '%.shop.action.com' THEN
                    RETURN lower(substring(r from '/p/([0-9]{8,14})(?:[/?#]|$)'));
                ELSIF h = 'dreamland.be' OR h LIKE '%.dreamland.be' THEN
                    RETURN lower(substring(r from '/([0-9]{6,}(?:_[0-9]+)?)/?(?:[?#]|$)'));
                ELSIF h = 'large.be' OR h LIKE '%.large.be' THEN
                    RETURN lower(substring(r from '/([0-9A-Za-z]+)\.html'));
                ELSIF h = 'vandenborre.be' OR h LIKE '%.vandenborre.be' THEN
                    RETURN lower(substring(r from '/([^/?#]+)/?(?:[?#]|$)'));
                ELSIF h = 'krefel.be' OR h LIKE '%.krefel.be' THEN
                    RETURN lower(substring(r from '/p/([0-9A-Za-z]+)(?:[-/?#]|$)'));
                END IF;

                RETURN NULL;
            END
            $fn$
            SQL);

        $invalid = DB::scalar(
            'SELECT NOT i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid'
            .' WHERE c.relname = ? AND pg_table_is_visible(c.oid)',
            [self::INDEX],
        );

        if ($invalid === true) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
        }

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::INDEX
            .' ON products (bc_shop_product_id(merchant_deep_link))'
            ." WHERE status = 'active' AND group_id IS NOT NULL AND bc_shop_product_id(merchant_deep_link) IS NOT NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
        DB::statement('DROP FUNCTION IF EXISTS bc_shop_product_id(text)');
    }
};

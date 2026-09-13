<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A hand-written title beside the feed title.
 *
 * `product_groups.title` is the merchant's string, and three things depend on
 * it being exactly that: relevance ordering sorts on it
 * (`SearchService::orderByRelevance()`), the grouper rewrites it from the best
 * offer on every run (`ProductGrouper`, the `display` CTE), and the slug and
 * identity key are derived from it. Rewriting it in place to read as a gift
 * would break all three, and be undone by the next regrouping anyway.
 *
 * So the gift-friendly title is a sibling column that nothing automated
 * writes. Null means "not written yet", and the model falls back to the
 * mechanical cleaner. Nullable, no default: adding it is instant in Postgres.
 *
 * It is searched, though, alongside the feed title (owner's call): a visitor
 * who reads "Hario handmolen" on a card and types it into the box must find
 * the product, and the feed title says "KOFFIEMOLEN HANDMATIG". So the group
 * carries a generated tsvector of the written title, stemmed per market like
 * the offers' vector, with a GIN index, and a trigram index for typos — the
 * same two mechanisms the feed title has, each answerable by an index, so
 * the search union stays four indexed SELECTs rather than a scan (see
 * SearchService::applyTextMatch()). See docs/features/display-titles.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_groups', function (Blueprint $table): void {
            $table->text('display_title')->nullable()->after('title');
        });

        // Stemmed per market like products.search_vector, through the same
        // immutable helpers, so "handmolens" finds "handmolen". Empty vector
        // for the rows with no written title, which is nearly all of them; a
        // GIN index over empty vectors costs nothing to probe.
        DB::statement(<<<'SQL'
            ALTER TABLE product_groups
            ADD COLUMN display_vector tsvector
            GENERATED ALWAYS AS (to_tsvector(bc_text_config(market), bc_unaccent(coalesce(display_title, '')))) STORED
        SQL);
        DB::statement('CREATE INDEX product_groups_display_vector_idx ON product_groups USING GIN (display_vector)');
        DB::statement('CREATE INDEX product_groups_display_title_trgm_idx ON product_groups USING GIN (display_title gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS product_groups_display_title_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS product_groups_display_vector_idx');
        DB::statement('ALTER TABLE product_groups DROP COLUMN IF EXISTS display_vector');

        Schema::table('product_groups', function (Blueprint $table): void {
            $table->dropColumn('display_title');
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Make `brand_stats` able to back a brand page.
 *
 * The table was created in Phase 0 and never written to. Rather than add a
 * second table, it becomes the thing a brand page reads: one row per (market,
 * brand), refreshed nightly, holding every number the page's copy asserts.
 *
 * WHY A STORED SLUG
 *
 * A brand page is `/{market}/brand/{slug}`, so a request has to get from
 * "sony-music" back to whatever the feed actually called the brand. Computing
 * `Str::slug()` over the whole table per request is a scan, and computing it in
 * SQL means reimplementing transliteration in Postgres — `Str::slug` folds
 * "Kärcher" to "karcher" and `lower(replace(...))` does not.
 *
 * So the slug is written once, by the same pass that writes the counts, using
 * the same PHP function the links are generated with. That guarantees the link
 * and the lookup can never disagree, which is the failure that produces a 404
 * on a page you are linking to from every search result.
 *
 * Unique on (market, slug), NOT globally: "Bosch" is a different row in each
 * market because its catalogue, prices and merchants differ per market, exactly
 * as product identity does.
 *
 * Collisions are possible in principle — two brand spellings folding to one
 * slug. The unique index turns that into a visible failure during the refresh
 * rather than a page that silently shows the wrong brand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_stats', function (Blueprint $table) {
            // Nullable, so the column can land before the backfill runs. The
            // refresher fills it; expand/contract, per the deployment rules.
            $table->string('slug')->nullable()->after('brand');

            // Cents, like everywhere else.
            $table->integer('min_price')->nullable();
            $table->integer('max_price')->nullable();

            // What the page's "N of these are reduced" clause reads. Stored
            // rather than counted per request, because the clause appears above
            // the fold on a page that has to render in one query.
            $table->integer('discounted_count')->default(0);
            $table->integer('in_stock_count')->default(0);

            // The single largest discount currently on offer for the brand, as a
            // percentage off the 30-day median. Drives the strongest sentence
            // the page can honestly write.
            $table->smallInteger('best_discount_percent')->nullable();

            // Denormalised so the copy can name a shop without a second query.
            $table->foreignId('top_merchant_id')->nullable()->constrained('merchants')->nullOnDelete();
            $table->string('top_category')->nullable();
        });

        // Backfill for rows that already exist. In practice there are none —
        // nothing has ever written this table — but a migration that assumes an
        // empty table is a migration that breaks the first time it is wrong.
        foreach (DB::table('brand_stats')->select('id', 'brand')->cursor() as $row) {
            DB::table('brand_stats')
                ->where('id', $row->id)
                ->update(['slug' => Str::slug((string) $row->brand)]);
        }

        // Partial: a row whose slug has not been computed yet must not block
        // another market's row from claiming the same one.
        DB::statement('CREATE UNIQUE INDEX brand_stats_market_slug_idx ON brand_stats (market, slug) WHERE slug IS NOT NULL');

        // The brand index page orders by product_count within a market.
        DB::statement('CREATE INDEX brand_stats_market_count_idx ON brand_stats (market, product_count DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS brand_stats_market_slug_idx');
        DB::statement('DROP INDEX IF EXISTS brand_stats_market_count_idx');

        Schema::table('brand_stats', function (Blueprint $table) {
            $table->dropConstrainedForeignId('top_merchant_id');
            $table->dropColumn([
                'slug',
                'min_price',
                'max_price',
                'discounted_count',
                'in_stock_count',
                'best_discount_percent',
                'top_category',
            ]);
        });
    }
};

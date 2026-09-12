<?php

declare(strict_types=1);

use App\Enums\Source;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Three prices instead of a price history.
 *
 * `price_history` held one row per offer per day: 5.5 million rows after a
 * month on 385,000 offers, 80% of them repeating the previous day's price, all
 * of it to feed one number, the 30-day median behind the discount badge. The
 * owner chose not to keep a history at all (2026-09-12). An offer now carries
 * three prices: the one it was first seen at, the one it has, and the one it
 * had before its last change. The group quotes the previous price of the offer
 * it links to, and a discount is measured against that, the way the price-drop
 * mails already put it: "was €189, now €149".
 *
 * Order of operations matters for a deploy that kills the old containers
 * before this runs: the new columns are filled from the history before the
 * history goes, and the group column is renamed and refilled in the same
 * migration so the app never reads a median under the new name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->integer('first_price')->nullable();
            $table->integer('previous_price')->nullable();
            $table->timestampTz('price_changed_at')->nullable();
        });

        if (Schema::hasTable('price_history')) {
            /*
             * One pass over the history, not two: the first price is the
             * earliest sample, the previous price the latest sample that
             * differs from today's. Two DISTINCT ON passes took 55 s on a
             * 300k-row development table; this runs during the deploy, while
             * the site is down, so it is written to read the table once.
             *
             * When the price changed is not knowable from a daily series, so
             * price_changed_at stays null for backfilled rows and is set by the
             * next real change.
             */
            DB::statement(<<<'SQL'
                UPDATE products p
                SET first_price = f.first_price,
                    previous_price = f.previous_price
                FROM (
                    SELECT h.product_id,
                           (array_agg(h.price ORDER BY h.captured_on ASC))[1] AS first_price,
                           (array_agg(h.price ORDER BY h.captured_on DESC)
                               FILTER (WHERE h.price IS DISTINCT FROM cur.price))[1] AS previous_price
                    FROM price_history h
                    JOIN products cur ON cur.id = h.product_id
                    GROUP BY h.product_id
                ) f
                WHERE f.product_id = p.id
            SQL);
        }

        // Where an offer has no history at all, the first price is today's.
        DB::statement('UPDATE products SET first_price = price WHERE first_price IS NULL AND price IS NOT NULL');

        // The group column keeps its slot and changes its meaning, so every
        // value in it is replaced: the previous price of the offer the group
        // links to, from trackable sources only (docs/features/amazon-compliance.md).
        Schema::table('product_groups', function (Blueprint $table): void {
            $table->renameColumn('median_price', 'previous_price');
        });

        DB::statement('UPDATE product_groups SET previous_price = NULL');

        $trackable = implode(', ', array_map(
            fn (Source $source): string => "'{$source->value}'",
            array_filter(Source::cases(), fn (Source $source): bool => $source->allowsPriceTracking()),
        ));

        DB::statement(<<<SQL
            UPDATE product_groups g
            SET previous_price = p.previous_price
            FROM products p
            WHERE p.id = g.best_offer_id
              AND p.source IN ({$trackable})
        SQL);

        Schema::dropIfExists('price_history');
    }

    public function down(): void
    {
        // Forward-only, like every migration here: the history is gone and
        // cannot be rebuilt. The columns can be walked back.
        Schema::table('product_groups', function (Blueprint $table): void {
            $table->renameColumn('previous_price', 'median_price');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['first_price', 'previous_price', 'price_changed_at']);
        });
    }
};

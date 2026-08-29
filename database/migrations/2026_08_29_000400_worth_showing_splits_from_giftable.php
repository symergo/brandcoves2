<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One boolean was answering two different questions.
 *
 * `giftable` encodes the gift engine's rule that anything over €500 is "a
 * decision rather than a suggestion" — sound for a gift finder, and wrong
 * everywhere else. The same flag gated the Cove, the deals column and
 * `/surprise`, where an expensive unusual object is precisely what people came
 * to look at. Measured on the dev catalogue before this ran, `too_expensive`
 * was the single largest rejection bucket: 9,040 rows out of 18,631, all of
 * them removed from the editorial surfaces for a reason that does not apply
 * there.
 *
 * So the price ceiling stays on `giftable` and comes off `worth_showing`.
 * Everything else — consumables, fitment, bulk, no price, too cheap — excludes
 * a row from both, because a printer cartridge is no more interesting than it
 * is giftable.
 *
 * Nullable, like `giftable`: a row that has never been classified is unknown,
 * not rejected. Both readers test `=== false` / `= true` rather than treating
 * null as either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_groups', function (Blueprint $table): void {
            $table->boolean('worth_showing')->nullable()->after('giftable_reason');
        });

        /*
         * Backfill from the reason already on the row.
         *
         * A string match, which the classifier itself would never do — but this
         * runs once against rows whose verdict was written by the old code, and
         * the alternative is 136,000 rows sitting null until the next scheduled
         * pass. `ClassifyGiftability` owns the column from here.
         */
        DB::statement(<<<'SQL'
            UPDATE product_groups
            SET worth_showing = (giftable = true OR giftable_reason LIKE 'too_expensive%')
            WHERE giftable IS NOT NULL
        SQL);

        // The editorial surfaces' retrieval predicate, mirroring
        // product_groups_giftable_idx in column order.
        DB::statement('CREATE INDEX product_groups_worth_showing_idx ON product_groups (market, in_stock, min_price) WHERE worth_showing = true');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS product_groups_worth_showing_idx');

        Schema::table('product_groups', function (Blueprint $table): void {
            $table->dropColumn('worth_showing');
        });
    }
};

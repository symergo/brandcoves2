<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * What a brand actually makes, in this market.
 *
 * `top_category` answers "mostly what?" and nothing else, so every sentence a
 * brand page could write about the brand itself came out as one word. The copy
 * filled the gap with the only other material it had — prices, medians, shop
 * counts — and a reader who arrived wanting to know what Kärcher is got three
 * paragraphs about how discounts are measured.
 *
 * The spread is the fact that answers it: a brand appearing in pressure washers,
 * vacuums and garden tools is legible from those three words in a way no price
 * range is. Stored rather than queried per request, because it renders above the
 * fold on a page that has to come back in one query.
 *
 * Nullable with a default, so the column lands before the refresher fills it —
 * expand/contract, per the deployment rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_stats', function (Blueprint $table) {
            // `[{"category": "Koptelefoons", "count": 84}, …]`, most first, a
            // handful at most. An array rather than three columns because the
            // number worth naming differs per brand and a fourth column would
            // be another migration.
            $table->jsonb('categories')->default(DB::raw("'[]'::jsonb"));
        });
    }

    public function down(): void
    {
        Schema::table('brand_stats', function (Blueprint $table) {
            $table->dropColumn('categories');
        });
    }
};

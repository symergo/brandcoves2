<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Amazon DECISION, stored once per ASIN and shared across every locale.
 *
 * Deliberately not a catalogue. `Source::allowsCatalogueStorage()` is false for
 * Amazon, and the columns here reflect that: no price, no availability, no
 * description, no image. Those are fetched live at render and a failed fetch
 * hides the item rather than showing something stale.
 *
 * What IS stored is the reasoning — which ASIN we chose and why it scored as it
 * did. That is ours, it costs real compute, and re-deriving it on every page
 * view would be absurd.
 *
 * ## Why there is no market column
 *
 * An ASIN is the same physical product on every storefront, so giftability and
 * serendipity are properties of the product rather than of the locale. Scoring
 * per market would spend five times the compute to produce five answers that
 * *should* be identical — and would not be, because the classifier reads the
 * title and the title is translated. One row, one verdict.
 *
 * Price and description are the opposite: per storefront, and never here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amazon_products', function (Blueprint $table) {
            $table->id();

            // The identity. Amazon's own, stable across every locale.
            $table->string('asin', 20)->unique();

            /*
             * Enough text to classify with, and no more.
             *
             * A title is needed to run the giftability rules at all, so it is
             * kept as the INPUT TO A DECISION rather than as catalogue content:
             * it is never rendered. What a visitor sees is re-fetched live.
             */
            $table->text('classified_title')->nullable();
            $table->string('classified_locale')->nullable();
            $table->string('brand')->nullable();
            $table->string('category')->nullable();

            // The verdicts, computed once and reused everywhere.
            $table->boolean('giftable')->nullable();
            $table->string('giftable_reason')->nullable();
            $table->float('surprise_score')->nullable();
            $table->jsonb('surprise_breakdown')->nullable();

            /*
             * Which storefronts carried it when we last looked.
             *
             * A hint for the locale selector, not a source of truth — stock
             * changes hourly and this is refreshed on a schedule. The UI still
             * fetches live before showing a price, so a stale entry costs one
             * empty tab rather than a wrong number.
             */
            $table->jsonb('seen_in_locales')->default(DB::raw("'[]'::jsonb"));

            // Ties an ASIN to the physical product we already know about, when
            // a shared GTIN makes that possible. Null is the common case:
            // Amazon does not publish barcodes for most listings.
            $table->string('identity_key')->nullable();

            $table->timestampTz('classified_at')->nullable();
            $table->timestampTz('first_seen_at')->useCurrent();
            $table->timestamps();

            $table->index('identity_key');
            $table->index(['giftable', 'surprise_score']);
        });

        // The same guarantee the catalogue has: an ASIN is one product.
        DB::statement("ALTER TABLE amazon_products ADD CONSTRAINT amazon_products_asin_format CHECK (asin ~ '^[A-Z0-9]{10}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('amazon_products');
    }
};

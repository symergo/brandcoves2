<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What eBay's item detail said about a listing: brand, barcode, part number (2026-11-22).
 *
 * eBay's search results carry none of the three, so a listing without them gets no product
 * identity and never becomes a searchable product (Spain on 2026-11-22: 520 offers, 0
 * products). The detail endpoint has them, at one request per listing against a quota of
 * 5,000 a day, so each listing is asked once and the answer kept here: a listing found
 * again in a later search takes its details from this table instead of losing them to a
 * search result that has none. A row with all three empty is kept too; it means "asked,
 * nothing there" and stops the listing being asked again.
 *
 * Keyed on eBay's item id alone: a listing is the same in every marketplace. `fetched_at`
 * is what the daily cap counts. See App\Services\Connectors\Ebay\EbayItemDetails.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ebay_item_details', function (Blueprint $table): void {
            $table->string('external_id')->primary();
            $table->string('brand')->nullable();
            $table->string('gtin')->nullable();
            $table->string('mpn')->nullable();
            $table->timestampTz('fetched_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ebay_item_details');
    }
};

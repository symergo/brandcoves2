<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which Amazon product (ASIN) a barcode is, per Amazon storefront (owner, 2026-11-23).
 *
 * For the "Bekijk op Amazon" link on product pages and search cards: a direct link to
 * the same product on amazon.com.be or amazon.nl, without a price. The ASIN and the
 * date we asked, nothing else: no title, picture, price or availability, so nothing
 * here is Amazon's catalogue (invariant 6, docs/features/amazon-links.md). `asin`
 * null means "asked, Amazon has no such product", which is remembered so the
 * question is not paid for again until it is `recheck_days` old.
 *
 * New table, nothing reads it until the code that writes it is deployed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amazon_matches', function (Blueprint $table) {
            $table->id();
            // The storefront host without www: amazon.com.be, amazon.nl.
            $table->string('storefront', 32);
            // The barcode as product_groups.identity_key holds it (GTIN-13/14).
            $table->string('gtin', 14);
            $table->string('asin', 10)->nullable();
            $table->timestampTz('checked_at');

            $table->unique(['storefront', 'gtin']);
            // The daily cap counts today's lookups.
            $table->index('checked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amazon_matches');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an Amazon search for a term returned, per storefront and language (owner,
 * 2026-11-23: "save the amazon products to database, without price").
 *
 * The products also go to `amazon_products` (ASIN, title, picture address); this
 * keeps the term's answer as shown, in order, with the titles in the language asked
 * (amazon.com.be answers Dutch for be-nl and French for be-fr), so the "Ook op
 * Amazon" section survives a cache flush and a term is paid for once per
 * `search_cache_days`. No price anywhere (invariant 6, docs/features/amazon-links.md).
 *
 * New table: nothing reads it until the code that writes it is deployed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amazon_searches', function (Blueprint $table) {
            $table->id();
            $table->string('storefront', 32);
            // The language Amazon was asked in: nl_BE, fr_BE, nl_NL.
            $table->string('language', 8);
            // Lower case, single spaces: the form AmazonSearch asks with.
            $table->string('term', 200);
            // [{asin, title, image}], best first. Empty when Amazon found nothing.
            $table->jsonb('items');
            $table->timestampTz('searched_at');

            $table->unique(['storefront', 'language', 'term']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amazon_searches');
    }
};

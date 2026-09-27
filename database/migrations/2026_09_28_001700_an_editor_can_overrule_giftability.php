<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An editor's verdict on whether a product is a gift, kept apart from the
 * rules' (docs/features/giftability.md, "An editor's verdict").
 *
 * `giftable` is rewritten by every classification pass, so a verdict written
 * there would last until the next one. This column is what the pass reads
 * back and lets win. Null means nobody said, which is nearly every row.
 *
 * A nullable column with no default is a catalogue-only change in Postgres:
 * no table rewrite, so the deploy is safe on the 450,000-row table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_groups', function (Blueprint $table) {
            $table->boolean('giftable_override')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('product_groups', function (Blueprint $table) {
            $table->dropColumn('giftable_override');
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When a product's gift tags were last judged (docs/features/gift-tags.md,
 * "The tagging queue").
 *
 * The admin's tagging queue needs "has anybody looked at this yet", and the
 * tags cannot say it: a product judged a gift that no tag fits keeps an empty
 * set, and would wait in the queue for ever. Set by every write to
 * `POST /products/tags`.
 *
 * The backfill touches only the products that already carry tags, a few
 * thousand rows, so the deploy stays quick on the 450,000-row table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_groups', function (Blueprint $table) {
            $table->timestampTz('gift_tags_at')->nullable();
        });

        DB::statement("UPDATE product_groups SET gift_tags_at = updated_at WHERE gift_tags <> '[]'::jsonb");
    }

    public function down(): void
    {
        Schema::table('product_groups', function (Blueprint $table) {
            $table->dropColumn('gift_tags_at');
        });
    }
};

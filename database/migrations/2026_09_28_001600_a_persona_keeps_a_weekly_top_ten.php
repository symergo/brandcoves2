<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A persona's top 10 for the week (docs/features/persona-top-ten.md).
 *
 * One row per persona per week: the products in order, and nothing else. No
 * counts are stored, on purpose: part of the ranking is how many wish lists
 * hold a product, and that aggregate must not outlive a list its owner
 * deletes (see App\Services\Cove\EntityRails). A new table, so nothing
 * existing is rewritten and the deploy is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('persona_top_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('set_id')->constrained('daily_pick_sets')->cascadeOnDelete();
            // The Monday the list is for.
            $table->date('week');
            // Product group ids, best first.
            $table->jsonb('group_ids');
            $table->timestamps();

            $table->unique(['set_id', 'week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('persona_top_lists');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved Coves: a bookmark from a person to a published Cove, shown under My
 * Coves (docs/features/saved-coves.md).
 *
 * A bookmark, not a copy: the Cove stays ours and changes when we edit it.
 * "Make it my list" is the copy, a separate act. Saving needs an account, so
 * there is no anonymous owner column, no retention window and nothing for
 * `bc:prune-personal-data`: deleting the account deletes the rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_coves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('set_id')->constrained('daily_pick_sets')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'set_id']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_coves');
    }
};

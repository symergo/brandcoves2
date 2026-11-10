<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What `bc:post-instagram` has posted, so nothing is posted twice (owner,
 * 2026-11-10: "a routine to post to instagram").
 *
 * One row per Cove: the Daily Cove of a date or a published guide. The unique
 * key on the Cove is the whole guarantee: a second run, an overlapping run or
 * a retry after a timeout finds the row and skips. A failed attempt is a row
 * too (`status = failed`, the reason in `error`), retried until
 * `giftcoves.instagram.max_attempts`, then left for a person to read.
 *
 * Deleting a Cove deletes its row; the post on Instagram stays, as it would
 * after any other edit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instagram_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_pick_set_id')->unique()->constrained('daily_pick_sets')->cascadeOnDelete();
            $table->string('market', 10);
            $table->string('status', 10);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('media_id', 64)->nullable();
            $table->text('caption')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_posts');
    }
};

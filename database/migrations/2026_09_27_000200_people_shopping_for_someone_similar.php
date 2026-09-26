<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What people shopping for someone like this picked (docs/features/crowd-picks.md).
 *
 * One row per product per *kind of person*: "a father", "cooking", or the two
 * together, "a father who likes cooking". A row exists only when at least
 * `giftcoves.list_signals.min_owners` different people keep the product on a
 * list for that kind of person, and `owners` says how many. Rebuilt nightly by
 * CountListSignals, from counts only: no list, owner or word anybody wrote is
 * in it.
 *
 * `context` is one gift tag, or two joined by `+` in byte order
 * (`interest:cooking+recipient:father`), so a brief looks its contexts up by
 * equality on the primary key and needs no other index.
 *
 * A new table, so nothing large is rewritten and nothing is locked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crowd_picks', function (Blueprint $table) {
            $table->string('market', 8);
            $table->text('context');
            $table->foreignId('group_id')->constrained('product_groups')->cascadeOnDelete();
            // Distinct people (a user or an anonymous identity), never lists.
            $table->unsignedInteger('owners');
            $table->primary(['market', 'context', 'group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crowd_picks');
    }
};

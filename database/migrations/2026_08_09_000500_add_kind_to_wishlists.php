<?php

declare(strict_types=1);

use App\Enums\ListKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replace `is_gift_list` with an explicit `kind`.
 *
 * The boolean sat beside a nullable `recipient_id` and answered an overlapping
 * question, so the two could disagree. Claiming ended up gated on *visibility*
 * instead, which made every shared list claimable — including a list whose
 * subject is a person who never asked for any of it.
 *
 * Expand/contract: this migration only adds and backfills. Dropping
 * `is_gift_list` is a later contract step, so a rollback never meets a schema
 * it cannot read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wishlists', function (Blueprint $table) {
            $table->string('kind')->default(ListKind::Mine->value);
        });

        /*
         * The recipient decides it, not the old boolean.
         *
         * `is_gift_list` meant "supports claiming", which people set on their
         * own birthday list *and* on private research. `recipient_id` is the
         * unambiguous fact: a list bound to a person is about that person.
         */
        DB::statement(sprintf(
            "UPDATE wishlists SET kind = CASE WHEN recipient_id IS NULL THEN '%s' ELSE '%s' END",
            ListKind::Mine->value,
            ListKind::ForSomeone->value,
        ));

        DB::statement(
            'ALTER TABLE wishlists ADD CONSTRAINT wishlists_kind_check CHECK (kind IN ('
            .implode(', ', array_map(fn (string $v) => "'".$v."'", ListKind::values()))
            .'))'
        );

        // Every lens that renders a list asks "may this be claimed?" before it
        // renders anything, so the column is read on the same path as the owner
        // lookup.
        Schema::table('wishlists', function (Blueprint $table) {
            $table->index(['kind', 'visibility']);
        });

        // Left in place deliberately for one deploy. Nothing reads it after
        // this migration; the drop is the contract step.
        Schema::table('wishlists', function (Blueprint $table) {
            $table->boolean('is_gift_list')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE wishlists DROP CONSTRAINT IF EXISTS wishlists_kind_check');

        Schema::table('wishlists', function (Blueprint $table) {
            $table->dropIndex(['kind', 'visibility']);
            $table->dropColumn('kind');
        });
    }
};

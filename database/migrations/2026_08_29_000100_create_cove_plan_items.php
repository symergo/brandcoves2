<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The curated shortlist a Cove is written around.
 *
 * `cove_plans.pinned_group_ids` held the same idea as a jsonb array of integers,
 * and an array of scalars is the wrong container the moment the list needs to
 * carry anything about each entry. Three facts belong per item and none of them
 * fit: the **order** a curator chose, the **note** telling the writer what to
 * say about it, and the **provenance** of a pick that may not be mirrored at
 * all. Turning the array into an array of objects would fix none of it — it
 * cannot be indexed, joined or constrained, and every reader would be parsing
 * JSON to find out whether entry three is a group or an ASIN.
 *
 * `guide_items` is the same shape for the same reason, and this deliberately
 * mirrors it.
 *
 * Expand/contract: `pinned_group_ids` stays for now and is backfilled from
 * here. Dropping it is a later migration, so a rollback never meets a schema
 * the previous image cannot read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cove_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('cove_plans')->cascadeOnDelete();

            /*
             * The ordinary case: a product in our own catalogue.
             *
             * Cascade, like `guide_items` — and deliberately unlike
             * `daily_picks`, which nulls. A pick is a record of what was
             * published and has to survive its product disappearing; an item is
             * an instruction for a build that has not happened yet, and an
             * instruction naming nothing is not worth keeping. Nulling here
             * would also fight the CHECK below, which SET NULL would violate on
             * its way through.
             */
            $table->foreignId('group_id')->nullable()
                ->constrained('product_groups')->cascadeOnDelete();

            /*
             * The other case: a source whose catalogue may not be mirrored.
             *
             * For Amazon we may store the DECISION — which ASIN, and why — and
             * nothing a visitor reads. Title, price, image and availability are
             * re-fetched live at render. Same rule, same columns, as
             * `daily_picks.amazon_asin`. See invariant 6.
             */
            $table->string('source')->nullable();
            $table->string('external_id')->nullable();

            $table->smallInteger('rank');

            /*
             * What the curator wants said about this product.
             *
             * The point of the whole feature: the shortlist is chosen first and
             * the article is written around it, so the writer needs the reason
             * this thing is on the list. Never rendered to a reader — it is
             * input to the prose, not prose.
             */
            $table->text('note')->nullable();

            // A short "best for X". Same idea as guide_items.verdict.
            $table->string('verdict')->nullable();

            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /*
             * One card per product.
             *
             * Rank is deliberately NOT unique: a unique (plan_id, rank) turns
             * every drag-reorder into a two-phase update dance around the
             * index, for an ordering that only has to be stable, not gapless.
             */
            $table->unique(['plan_id', 'group_id']);
            $table->index(['plan_id', 'rank']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE cove_plan_items ADD CONSTRAINT cove_plan_items_identity_check
            CHECK (group_id IS NOT NULL OR (source IS NOT NULL AND external_id IS NOT NULL))
        SQL);

        /*
         * Backfill, preserving the order the array was written in.
         *
         * `WITH ORDINALITY` is the whole reason this is one statement: the
         * array's position *is* the curator's ranking, and reading the ids out
         * without it would silently reorder every plan anyone had already
         * pinned.
         */
        DB::statement(<<<'SQL'
            INSERT INTO cove_plan_items (plan_id, group_id, rank, created_at, updated_at)
            SELECT p.id, g.value::int, g.ordinality, now(), now()
            FROM cove_plans p
            CROSS JOIN LATERAL jsonb_array_elements_text(p.pinned_group_ids) WITH ORDINALITY AS g(value, ordinality)
            JOIN product_groups pg ON pg.id = g.value::int
            ON CONFLICT (plan_id, group_id) DO NOTHING
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('cove_plan_items');
    }
};

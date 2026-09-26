<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What people's lists say about products, counted (roadmap step 4, engine F
 * and G in docs/strategy.md; docs/features/list-signals.md).
 *
 * - `product_groups.crowd_tags`: gift tags a product earned from the lists it
 *   is on ("for a father", "cooking", "birthday"), only once enough different
 *   people agree. Beside the editors' `gift_tags`, never mixed into them:
 *   people's lists never change what an editor wrote.
 * - `product_links`: products people keep on their own wish lists together
 *   ("people who want this also want…"), counted in distinct people.
 *
 * Both are rebuilt nightly by CountListSignals from counts only; neither holds
 * a list, an owner or a claim.
 *
 * ## Safe on the largest table
 *
 * `ADD COLUMN … DEFAULT '[]' NOT NULL` is a catalogue-only change in Postgres
 * 11+: no rewrite of product_groups (0.7 GB). The GIN index is built
 * CONCURRENTLY, outside a transaction, so it never locks writes; a failing
 * migration takes the site down (Coolify stops the old containers first), and
 * a long lock would look the same from outside.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("ALTER TABLE product_groups ADD COLUMN IF NOT EXISTS crowd_tags jsonb NOT NULL DEFAULT '[]'::jsonb");
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS product_groups_crowd_tags_gin ON product_groups USING gin (crowd_tags)');

        Schema::create('product_links', function (Blueprint $table) {
            // Always stored with group_a < group_b; read both ways.
            $table->foreignId('group_a')->constrained('product_groups')->cascadeOnDelete();
            $table->foreignId('group_b')->constrained('product_groups')->cascadeOnDelete();
            $table->string('market', 8);
            // Distinct people keeping both on one of their own lists.
            $table->unsignedInteger('owners');
            $table->primary(['group_a', 'group_b']);
            $table->index(['group_b', 'owners']);
        });

        DB::statement('ALTER TABLE product_links ADD CONSTRAINT product_links_ordered CHECK (group_a < group_b)');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_links');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS product_groups_crowd_tags_gin');
        DB::statement('ALTER TABLE product_groups DROP COLUMN IF EXISTS crowd_tags');
    }
};

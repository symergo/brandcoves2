<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Interest tags worked out from a product's category, kept apart from the
 * tags somebody judged (owner, 2026-11-15: "implement automatic tagging where
 * possible"). See docs/features/gift-tags.md, "Automatic tags".
 *
 * A column of its own, like `crowd_tags`: an editor's tag is a decision, this
 * is an inference from other people's decisions, and the engine weighs the
 * two differently. Written only by App\Services\Gift\AutoTagger.
 *
 * Adding a column with a constant default does not rewrite the table in
 * Postgres 16, and the index is built CONCURRENTLY so a deploy does not hold
 * writes on a table of millions of rows; hence no transaction.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("ALTER TABLE product_groups ADD COLUMN IF NOT EXISTS auto_tags jsonb NOT NULL DEFAULT '[]'::jsonb");
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS product_groups_auto_tags_gin ON product_groups USING gin (auto_tags)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS product_groups_auto_tags_gin');
        DB::statement('ALTER TABLE product_groups DROP COLUMN IF EXISTS auto_tags');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gift tags on a product: who it is for, what they are into, which occasion.
 *
 * A jsonb array of `<vocabulary>:<value>` strings from a closed vocabulary
 * (App\Services\Gift\GiftTags), written by editors over the API for the few
 * hundred products per market that sit on an editorial surface. Empty for
 * everything else, which is nearly everything, and an empty array is what
 * the engine treats as "untagged".
 *
 * A GIN index with the default jsonb operator class, because the engine asks
 * `jsonb_exists_any(gift_tags, ARRAY[...])` — "does this product carry any of
 * these tags" — and that is the `?|` operator, which the default class
 * indexes and `jsonb_path_ops` does not. The engine spells it as the function
 * rather than the operator only because a bare `?` is a placeholder to PDO.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE product_groups ADD COLUMN gift_tags jsonb NOT NULL DEFAULT '[]'::jsonb");
        DB::statement('CREATE INDEX product_groups_gift_tags_idx ON product_groups USING GIN (gift_tags)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS product_groups_gift_tags_idx');
        DB::statement('ALTER TABLE product_groups DROP COLUMN IF EXISTS gift_tags');
    }
};

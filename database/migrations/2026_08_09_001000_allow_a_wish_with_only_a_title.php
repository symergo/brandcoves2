<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Let a wishlist item be a plain wish.
 *
 * `wishlist_items_identifiable` required `group_id`, or a `source` and an
 * `external_id`. The intent behind it is right: a row that identifies nothing is
 * an entry nobody can render and nobody can deliberately remove.
 *
 * It was one case too strict. A wishlist has always been able to hold a wish
 * that is not a product we stock, written as free text in `snapshot_title` —
 * "a nice scarf, dark green". That is renderable, it is claimable, and it is the
 * whole point of asking a recipient what they would actually like. The
 * constraint rejected it, and three claim tests that predate this schema failed
 * on insert.
 *
 * So the rule becomes: an item must be a group we hold, an external product we
 * can re-fetch, **or** something a person wrote down. A row with none of the
 * three is still rejected.
 *
 * A new migration rather than an edit to the one that added the constraint,
 * because that one has already been applied. Migrations here are forward-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE wishlist_items DROP CONSTRAINT IF EXISTS wishlist_items_identifiable');

        DB::statement(<<<'SQL'
            ALTER TABLE wishlist_items ADD CONSTRAINT wishlist_items_identifiable CHECK (
                group_id IS NOT NULL
                OR (source IS NOT NULL AND external_id IS NOT NULL)
                OR (snapshot_title IS NOT NULL AND btrim(snapshot_title) <> '')
            )
        SQL);
    }

    public function down(): void
    {
        /*
         * Rows that only the wider rule allows would violate the narrower one,
         * so they go first. A free-text wish cannot be converted into a product
         * reference, and leaving the migration unable to run down is worse than
         * losing rows that only existed under the wider rule.
         */
        DB::table('wishlist_items')
            ->whereNull('group_id')
            ->where(fn ($q) => $q->whereNull('source')->orWhereNull('external_id'))
            ->delete();

        DB::statement('ALTER TABLE wishlist_items DROP CONSTRAINT IF EXISTS wishlist_items_identifiable');
        DB::statement('ALTER TABLE wishlist_items ADD CONSTRAINT wishlist_items_identifiable CHECK (group_id IS NOT NULL OR (source IS NOT NULL AND external_id IS NOT NULL))');
    }
};

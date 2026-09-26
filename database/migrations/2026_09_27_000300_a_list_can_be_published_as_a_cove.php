<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A list its owner chose to publish becomes a Community Cove: a public page
 * other people can find, save and copy (docs/features/community-coves.md).
 *
 * On `wishlists`:
 *
 * - `published_at`: set while the list is published. Null is the default and
 *   the only state sharing can leave a list in; publishing is its own press.
 * - `public_slug`: the address, set at the first publication and never changed,
 *   so a title edited later does not break a link somebody saved. Kept after
 *   unpublishing, which is how the old address can answer 410 Gone.
 * - `public_title`: the name the public sees, written by the owner when they
 *   publish. Separate from `title` because a list's own title is often a name
 *   ("Emma's 40th"), and the public page must never show the recipient's name.
 * - `public_shows_owner`: the owner's first name on the page, or nothing. Null
 *   means never asked, which shows nothing.
 * - `public_hidden_at`: an admin took it off the site (Filament). Separate from
 *   `published_at` so the owner cannot undo a moderation decision by
 *   republishing.
 *
 * On `saved_coves`: a bookmark may now point at a published list instead of an
 * editorial Cove. One table with exactly one target per row (the CHECK), so the
 * Saved view stays one query.
 *
 * ## Safe on production
 *
 * Every added column is nullable with no default, which Postgres records in the
 * catalogue without rewriting the table. The indexes are built CONCURRENTLY,
 * outside a transaction, so writes to `wishlists` never wait on them; a failing
 * migration here is an outage (Coolify stops the old containers first), so
 * every statement is also safe to run twice. The CHECK constraints are added
 * NOT VALID and validated separately: validating takes a lighter lock, and
 * every existing row passes (no list is published yet, every saved row has a
 * `set_id`).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('ALTER TABLE wishlists ADD COLUMN IF NOT EXISTS published_at timestamp(0) without time zone NULL');
        DB::statement('ALTER TABLE wishlists ADD COLUMN IF NOT EXISTS public_slug varchar(120) NULL');
        DB::statement('ALTER TABLE wishlists ADD COLUMN IF NOT EXISTS public_title varchar(80) NULL');
        DB::statement('ALTER TABLE wishlists ADD COLUMN IF NOT EXISTS public_shows_owner boolean NULL');
        DB::statement('ALTER TABLE wishlists ADD COLUMN IF NOT EXISTS public_hidden_at timestamp(0) without time zone NULL');

        // One address per Cove, across every market: the slug carries a random
        // suffix, so two people who both call theirs "Birthday dad" never meet.
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS wishlists_public_slug_unique ON wishlists (public_slug) WHERE public_slug IS NOT NULL');

        // The Community Coves listing: a market's published lists, newest first.
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS wishlists_published_idx ON wishlists (market, published_at DESC) WHERE published_at IS NOT NULL');

        $this->check('wishlists', 'wishlists_published_has_slug', 'published_at IS NULL OR (public_slug IS NOT NULL AND public_title IS NOT NULL)');

        DB::statement('ALTER TABLE saved_coves ADD COLUMN IF NOT EXISTS wishlist_id uuid NULL');
        DB::statement('ALTER TABLE saved_coves ALTER COLUMN set_id DROP NOT NULL');

        if (! $this->constraintExists('saved_coves_wishlist_id_foreign')) {
            DB::statement('ALTER TABLE saved_coves ADD CONSTRAINT saved_coves_wishlist_id_foreign FOREIGN KEY (wishlist_id) REFERENCES wishlists (id) ON DELETE CASCADE');
        }

        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS saved_coves_user_wishlist_unique ON saved_coves (user_id, wishlist_id) WHERE wishlist_id IS NOT NULL');

        // "Most saved" counts rows per list.
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS saved_coves_wishlist_idx ON saved_coves (wishlist_id) WHERE wishlist_id IS NOT NULL');

        $this->check('saved_coves', 'saved_coves_one_target', 'num_nonnulls(set_id, wishlist_id) = 1');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE saved_coves DROP CONSTRAINT IF EXISTS saved_coves_one_target');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS saved_coves_wishlist_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS saved_coves_user_wishlist_unique');
        DB::statement('DELETE FROM saved_coves WHERE set_id IS NULL');
        DB::statement('ALTER TABLE saved_coves DROP CONSTRAINT IF EXISTS saved_coves_wishlist_id_foreign');
        DB::statement('ALTER TABLE saved_coves DROP COLUMN IF EXISTS wishlist_id');
        DB::statement('ALTER TABLE saved_coves ALTER COLUMN set_id SET NOT NULL');

        DB::statement('ALTER TABLE wishlists DROP CONSTRAINT IF EXISTS wishlists_published_has_slug');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS wishlists_published_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS wishlists_public_slug_unique');

        foreach (['public_hidden_at', 'public_shows_owner', 'public_title', 'public_slug', 'published_at'] as $column) {
            DB::statement("ALTER TABLE wishlists DROP COLUMN IF EXISTS {$column}");
        }
    }

    /** Add a CHECK without a long lock, and only once. */
    private function check(string $table, string $name, string $expression): void
    {
        if (! $this->constraintExists($name)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression}) NOT VALID");
        }

        DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$name}");
    }

    private function constraintExists(string $name): bool
    {
        return DB::table('pg_constraint')->where('conname', $name)->exists();
    }
};

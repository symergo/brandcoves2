<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A Cove keeps its prose as the page shows it: tokens resolved into links.
 *
 * ## Why
 *
 * Every view of a Cove page turned its text into HTML again: it built the link
 * allowlist (the 300 largest brands with a page and the 200 newest articles of
 * the market, two queries), looked up brand page addresses, and ran the token
 * and paragraph passes over the whole article. The text only changes when the
 * Cove is built or edited, so `App\Services\Cove\CoveProse` now does that work
 * once, when `EditionBuilder` builds the Cove, and stores the result here.
 *
 * ## `json`, not `jsonb`
 *
 * The one JSON column of this table that is not `jsonb`, on purpose. `jsonb`
 * stores an object with its keys re-sorted (shortest first), so a paragraph
 * stored as `{html, groupIds, figure, table}` would come back as `{html,
 * table, figure, groupIds}`, and the page props would no longer be the ones a
 * live render produces. `json` keeps the text as it was written. Nothing
 * queries inside this column, which is the only thing `jsonb` would buy.
 *
 * ## Safe on production
 *
 * Expand-only: one nullable column, no default, so on Postgres 16 it is a
 * catalogue change rather than a table rewrite, and every existing row reads
 * null. Null (or a stored value whose fingerprint no longer matches the text)
 * means the page renders the prose itself, as before, and caches that for a
 * day. Guarded with IF NOT EXISTS, because a failing migration is an outage
 * here: Coolify stops the old containers before `migrate` runs.
 *
 * See docs/features/speed.md, "Cove pages".
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE daily_pick_sets ADD COLUMN IF NOT EXISTS rendered_prose json NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE daily_pick_sets DROP COLUMN IF EXISTS rendered_prose');
    }
};

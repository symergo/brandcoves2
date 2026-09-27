<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A shop or brand Cove keeps the list of categories its prose may link to.
 *
 * ## Why
 *
 * A shop Cove's `[[search:…]]` tokens render as links only for categories the
 * shop actually sells in (the "link list", or allowlist). That list was worked
 * out on every page view by grouping every active offer of the shop by
 * category: 4.4 s warm on production for bol.com. The list changes when the
 * shop's range changes, which is weeks, not seconds, so it is now worked out
 * once when `EditionBuilder` builds the Cove and stored here.
 *
 * ## Safe on production
 *
 * Expand-only: one nullable column, no default, so on Postgres 16 it is a
 * catalogue change rather than a table rewrite, and every existing row reads
 * null. Null means "built before this column existed", and the page then
 * falls back to computing the list and caching it for a day
 * (`App\Services\Cove\EntityLinks`). Guarded with IF NOT EXISTS, because a
 * failing migration is an outage here: Coolify stops the old containers before
 * `migrate` runs.
 *
 * See docs/features/cove-entities.md, "The link list is stored at build".
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE daily_pick_sets ADD COLUMN IF NOT EXISTS link_categories jsonb NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE daily_pick_sets DROP COLUMN IF EXISTS link_categories');
    }
};

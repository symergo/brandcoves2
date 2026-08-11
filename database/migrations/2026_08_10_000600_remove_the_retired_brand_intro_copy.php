<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Delete the copy bank's `brand_intro` rows. Nothing renders them any more.
 *
 * The brand page used to open with four templated paragraphs of statistics —
 * product count, categories, shop count, price range, how many items sat below
 * their 30-day median. They were replaced by the row of term links, so every
 * variant of every `brand_intro` slot is now copy that resolves for nobody. See
 * `BrandController` and [docs/features/brand-pages.md].
 *
 * ## Why a migration rather than a `psql` session
 *
 * Same reasoning as `2026_08_09_002000_reframe_seeded_copy_away_from_comparison`:
 * `copy_templates` is per-environment state that no deploy otherwise touches, and
 * a hand-run `DELETE` reaches whichever environment someone remembered. This runs
 * in the one-shot `migrate` service on both apps, in the same deploy as the code
 * that stopped reading the rows, and it will reach any environment stood up later
 * from a restored dump.
 *
 * ## What is actually being deleted
 *
 * Read from the two environments on 2026-08-10: staging holds 56 `brand_intro`
 * rows, 14 per language, all created by one `bc:seed-copy` run on 2026-08-09 and
 * **none edited since** — `updated_at` matches `created_at` on every one of them.
 * Production's `copy_templates` is empty entirely, so there it is a no-op.
 *
 * So no editor's work is being discarded here. That mattered enough to check
 * first, because an edited variant is the one thing in this table that cannot be
 * regenerated: the shipped language lines can always be re-imported, an editor's
 * rewrite cannot.
 *
 * The `search` and `brand` surfaces are untouched. Both still render — they are
 * the long copy below the grid, which the change did not remove.
 *
 * The `copy_templates_surface_check` constraint deliberately still permits
 * `brand_intro`. Narrowing it is the one part of this that a rollback could not
 * survive: redeploying the previous commit would put back a `bc:seed-copy` that
 * writes those rows, and it would fail against a constraint that had stopped
 * accepting them.
 *
 * Idempotent, and safe to re-run: after the first pass nothing matches.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('copy_templates')->where('surface', 'brand_intro')->delete();
    }

    /**
     * Deliberately a no-op.
     *
     * The rows held nothing but the shipped language lines, which are still in
     * `lang/*\/site.php` under `site.brand`. If a rollback ever needs them back,
     * `php artisan bc:seed-copy --surface=brand_intro` re-imports exactly what
     * was deleted. Reconstructing them here would mean this file carrying its own
     * copy of four languages' worth of text, which is the sort of duplicate that
     * silently goes stale.
     */
    public function down(): void {}
};

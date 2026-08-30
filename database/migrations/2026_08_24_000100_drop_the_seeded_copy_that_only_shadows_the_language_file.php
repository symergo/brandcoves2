<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Delete the copy rows that say exactly what the language file already says.
 *
 * `bc:seed-copy` fills the bank with the shipped sentences so the admin opens
 * populated rather than blank. That made sense when the admin was a flat table
 * of rows; `EditPageCopy` now lists every slot in the registry whether or not it
 * has a row, showing the shipped line as the placeholder underneath, so an empty
 * bank is already a full-looking editor.
 *
 * What the seeded rows still do is shadow. Measured on 2026-08-24, development
 * held 140 of them — 17 brand slots and 18 search slots across four languages,
 * every one byte-identical to its language file line and not one edited since the
 * seeding run. They change nothing a visitor reads: the fallback would render the
 * same sentence. They only carry the trap documented in
 * [docs/features/brand-pages.md] — *once a slot has a row, rewriting its language
 * file changes nothing* — which has already cost one deploy that looked like it
 * worked and served the old copy.
 *
 * A slot with no rows is the safe state. It renders the shipped line and it keeps
 * rendering the shipped line after the shipped line is rewritten.
 *
 * ## The predicate, and why both halves are needed
 *
 * A row is deleted only when
 *
 *  1. its body is **exactly** the current language file line for its slot, and
 *  2. it is the **only** row for that `(surface, slot, language)`.
 *
 * (1) is what makes this non-destructive without inspecting each environment by
 * hand. A body identical to the file is recoverable from the file by definition,
 * so nothing that cannot be reconstructed is discarded — the property the earlier
 * `remove_the_retired_brand_intro_copy` established by reading `updated_at` on
 * every row before writing the delete. An edited row differs from the file and is
 * therefore invisible to this migration, whatever it was edited into.
 *
 * (2) is the one that is easy to miss. Where an editor has written a genuine
 * alternative, the slot holds their variant *and* the seeded shipped line, and
 * the rotation draws between them. Deleting the seeded row there would not fall
 * back to the file — the fallback only fires when a slot has no rows at all — it
 * would silently drop the shipped sentence out of the rotation and leave every
 * page on that slot reading the editor's alternative. So a slot that someone has
 * actually used is left exactly as it is.
 *
 * Together: every page renders precisely what it rendered before this ran.
 *
 * ## Why a migration
 *
 * Same reason as the two copy migrations before it. `copy_templates` is
 * per-environment state that no deploy otherwise touches, so a hand-run `DELETE`
 * reaches whichever environment someone remembered. This runs in the one-shot
 * `migrate` service on both apps and will reach any environment stood up later
 * from a restored dump.
 *
 * Idempotent: after the first pass the surviving rows all differ from the file or
 * have siblings.
 *
 * **This migration no longer does anything — see the note on the class below.**
 *
 * > Re-running `bc:seed-copy` puts every one of these rows back. That is the
 * > command's job and it is still the right behaviour after a *new* slot is added
 * > — but running it wholesale re-arms the shadow across the whole bank.
 */
return new class extends Migration
{
    /**
     * ## Retired 2026-09-01, and left in place
     *
     * This walked `App\Services\Seo\CopySlots` and compared each row against
     * `__('site.narrative.…')`. Both are gone: page copy is `page_blocks` now,
     * and the narrative language keys were deleted in the same release.
     *
     * The body is not restored from history, and it is not deleted either.
     * Migrations are forward-only, so this file has already run on every
     * environment that has one — its work is done and cannot be undone by
     * emptying it. What emptying it *does* fix is a fresh database, where
     * `migrate` replays the whole history and this would fatal on a class that
     * no longer exists. That is not hypothetical: it is `RefreshDatabase` in the
     * test suite, on every run.
     *
     * Rewriting it to inline the old slot list would be the alternative, and it
     * would be worse — a hundred lines reconstructing a comparison against
     * strings that are also gone, to delete rows from a table nothing reads.
     */
    public function up(): void
    {
        // Deliberately nothing. See the note above.
    }

    public function down(): void
    {
        // It never had one: the rows it removed were duplicates of the language
        // file, so there was nothing to restore even when the file existed.
    }
};

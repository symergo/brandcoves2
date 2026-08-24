<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Services\Seo\CopyBank;
use App\Services\Seo\CopySlots;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
 * > Re-running `bc:seed-copy` puts every one of these rows back. That is the
 * > command's job and it is still the right behaviour after a *new* slot is added
 * > — but running it wholesale re-arms the shadow across the whole bank.
 */
return new class extends Migration
{
    public function up(): void
    {
        $deleted = 0;

        foreach ($this->languages() as $language) {
            foreach (CopySlots::all() as $definition) {
                $surface = $definition['surface'];
                $slot = $definition['slot'];

                $rows = DB::table('copy_templates')
                    ->where('surface', $surface)
                    ->where('slot', $slot)
                    ->where('language', $language)
                    ->get(['id', 'body']);

                // Condition (2): a slot anyone has added a variant to is theirs.
                if ($rows->count() !== 1) {
                    continue;
                }

                $namespace = CopySlots::namespaceFor($surface);
                $line = __("{$namespace}.{$slot}", [], $language);

                // A missing translation resolves to the key itself, which no body
                // will ever equal — so an absent language line leaves the row in
                // place rather than deleting the only copy of that sentence.
                if (! is_string($line) || $line !== $rows->first()->body) {
                    continue;
                }

                $deleted += DB::table('copy_templates')->where('id', $rows->first()->id)->delete();
            }
        }

        if ($deleted > 0) {
            // The drawable set is cached for two minutes. Harmless to leave, but
            // the delete is the sort of change someone verifies immediately.
            CopyBank::flush();
        }
    }

    /**
     * Deliberately a no-op.
     *
     * Every row this deleted held the language file's own sentence, which is
     * still in `lang/*\/site.php`. `php artisan bc:seed-copy` re-imports exactly
     * what was removed. Reconstructing it here would mean this file carrying its
     * own copy of four languages of text — a duplicate that goes stale in silence.
     */
    public function down(): void {}

    /** @return list<string> */
    private function languages(): array
    {
        return array_values(array_unique(array_map(
            fn (Market $market) => $market->language(),
            Market::cases(),
        )));
    }
};

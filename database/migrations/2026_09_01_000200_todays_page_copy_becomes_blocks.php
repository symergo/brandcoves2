<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fill the new template with the copy the site is already rendering.
 *
 * ## Why this is not optional
 *
 * There is no fallback under a region any more — that was the point — so an
 * unseeded environment does not render slightly worse copy, it renders none.
 * Search and brand pages number in the thousands and their prose is most of what
 * a crawler has to decide what they are about. This migration is what stands
 * between the release and four hundred words leaving every one of them.
 *
 * ## Two sources, in order of who did the work
 *
 * 1. **`copy_templates` rows**, where an environment has them. Staging ran
 *    `bc:seed-copy` on 2026-08-09 and somebody may have rewritten a sentence
 *    since; production's table was empty as of 2026-08-24 and may not be by now.
 *    Every variant comes across with its weight and its enabled flag, because an
 *    editor's afternoon is the one thing in that table that cannot be
 *    regenerated.
 * 2. **The shipped strings**, from `database/migrations/data/page-blocks-2026-09.php`,
 *    for every slot with no rows.
 *
 * ## `DB::table()`, never the models
 *
 * Three reasons and all of them real. The models flush the page-copy cache on
 * every save, so this would fire around two hundred flushes for no reader. A
 * migration referencing `App\Models\PageBlock` breaks the day somebody renames
 * that class, years after this ran. And a migration must not depend on a working
 * cache — this can run in a container where Redis is not up yet.
 *
 * ## Idempotent
 *
 * A `(page, region, language)` that already holds blocks is left alone.
 * Migrations run once, but `migrate:fresh` in the test suite and a re-run after
 * a half-finished failure both argue for it, and it costs one query.
 */
return new class extends Migration
{
    public function up(): void
    {
        $blocks = require database_path('migrations/data/page-blocks-2026-09.php');

        // Whether the environment still has the old table. A database created
        // after it is dropped seeds entirely from the file, which is the same
        // result minus anybody's edits — because there were none to have.
        $hasOldTable = Schema::hasTable('copy_templates');

        $seeded = [];
        $now = now();

        foreach ($blocks as $block) {
            $page = $block['page'];
            $region = $block['region'];

            foreach ($block['bodies'] as $language => $shipped) {
                $scope = "{$page}.{$region}.{$language}";

                // Checked once per region and language rather than per block, so
                // a half-seeded region cannot end up merged with a fresh one.
                $seeded[$scope] ??= DB::table('page_blocks')
                    ->where('page', $page)
                    ->where('region', $region)
                    ->where('language', $language)
                    ->exists();

                if ($seeded[$scope]) {
                    continue;
                }

                $variants = $hasOldTable
                    ? $this->existingVariants($block['slot'], $language)
                    : [];

                $variants = $this->ensureSomethingIsDrawable($variants, $shipped);

                $blockId = DB::table('page_blocks')->insertGetId([
                    'page' => $page,
                    'region' => $region,
                    'language' => $language,
                    'kind' => $block['kind'],
                    'position' => $block['position'],
                    'conditions' => json_encode($block['conditions']),
                    'enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $rows = [];

                foreach ($variants as $variant) {
                    $rows[] = [
                        'block_id' => $blockId,
                        'body' => $variant['body'],
                        'weight' => $variant['weight'],
                        'enabled' => $variant['enabled'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                DB::table('page_block_variants')->insert($rows);
            }
        }
    }

    /**
     * What an editor wrote for this slot, if anything.
     *
     * The old slot key is `narrative.compare_1` or `brand_narrative.about_1` —
     * a namespace and a slot, which map to the old table's `surface` and `slot`.
     * Anything else in the data file (`search.empty_hint`) is a plain language
     * string that never had a row, so it returns nothing and the shipped text
     * stands.
     *
     * Exact duplicates are dropped: two identical bodies in a weighted draw are
     * one phrasing with double the weight, said twice.
     *
     * @return list<array{body: string, weight: int, enabled: bool}>
     */
    private function existingVariants(?string $slot, string $language): array
    {
        if ($slot === null) {
            return [];
        }

        [$namespace, $name] = array_pad(explode('.', $slot, 2), 2, null);

        $surface = match ($namespace) {
            'narrative' => 'search',
            'brand_narrative' => 'brand',
            default => null,
        };

        if ($surface === null || $name === null) {
            return [];
        }

        $seen = [];
        $variants = [];

        $rows = DB::table('copy_templates')
            ->where('surface', $surface)
            ->where('slot', $name)
            ->where('language', $language)
            ->orderByDesc('weight')
            ->orderBy('id')
            ->get(['body', 'weight', 'enabled']);

        foreach ($rows as $row) {
            $body = trim((string) $row->body);

            if ($body === '' || isset($seen[$body])) {
                continue;
            }

            $seen[$body] = true;

            $variants[] = [
                'body' => $body,
                // A zero-weight row was retired, not deleted. It comes across
                // retired: bringing it back into the rotation would undo a
                // decision somebody made deliberately.
                'weight' => max(0, min(100, (int) $row->weight)),
                'enabled' => (bool) $row->enabled,
            ];
        }

        return $variants;
    }

    /**
     * A block must have at least one phrasing the rotation can draw.
     *
     * The old bank fell back to the language file whenever a slot had nothing
     * drawable, so a page whose every variant was retired or switched off still
     * rendered the shipped sentence. There is no such floor here, and carrying
     * those rows across untouched would silently blank the block on every page
     * in the market — a wipe disguised as a faithful migration.
     *
     * So the shipped line goes in as the drawable one and the editor's rows ride
     * along beside it, in the state they were left in. Where the shipped line is
     * already one of them, that row is simply un-retired rather than duplicated:
     * the same sentence twice in a weighted draw is one phrasing pretending to
     * be two.
     *
     * @param  list<array{body: string, weight: int, enabled: bool}>  $variants
     * @return list<array{body: string, weight: int, enabled: bool}>
     */
    private function ensureSomethingIsDrawable(array $variants, string $shipped): array
    {
        foreach ($variants as $variant) {
            if ($variant['enabled'] && $variant['weight'] > 0) {
                return $variants;
            }
        }

        $shipped = trim($shipped);

        foreach ($variants as $index => $variant) {
            if ($variant['body'] === $shipped) {
                $variants[$index] = ['body' => $shipped, 'weight' => 1, 'enabled' => true];

                return $variants;
            }
        }

        return [['body' => $shipped, 'weight' => 1, 'enabled' => true], ...$variants];
    }

    public function down(): void
    {
        // The blocks are the site's copy now. Emptying the tables is what
        // dropping them means, and the schema migration owns that.
        DB::table('page_block_variants')->delete();
        DB::table('page_blocks')->delete();
    }
};

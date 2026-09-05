<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The related-search chips are removed, and everything that fed them.
 *
 * They cost more than they were worth. The row was drawn by a trigram scan over
 * ninety days of `search_log`, a table that grows with traffic rather than with
 * the catalogue — so its price rose with the site's own success. Measured on
 * production immediately after the caching deploy on 2026-09-05: a cold term
 * still took 9.7-11.1s and only a second visitor within the hour saw 0.8s.
 * Every first visitor on a term, and every crawler meeting a new one, paid the
 * whole scan. Caching moved the cost around rather than removing it.
 *
 * What goes with them is real, and was the reason the block existed: the only
 * outbound links on a results page that are not about the page itself, and the
 * internal linking that stops a crawler treating it as a leaf. Speed was chosen
 * over that deliberately. `:term_links` and `:brand_links` still link outward
 * from the same regions and are computed from the products already on the page
 * rather than from a table scan.
 *
 * ## Sections, not paragraphs
 *
 * The chips were placed as three blocks — a heading, an intro sentence, and a
 * paragraph holding nothing but `:related_searches`. Deleting only the third
 * would leave "Other searches people ran here" standing over an empty section,
 * and `BlockSections` only drops a heading when *every* paragraph under it
 * resolves to nothing; the intro is an ordinary sentence and would survive.
 *
 * So this deletes the section: from the heading that opens it through to the
 * chips paragraph. Found by content and by ordering rather than by position
 * numbers or seeded wording, because an editor may have moved the row or
 * rewritten the heading, and `slot` is seed-time metadata that `page_blocks`
 * does not store.
 *
 * Blocks are content, so this is destructive in the way content is: an editor
 * who rewrote that heading loses their wording, and any paragraph they added to
 * that section goes with it. Nothing else is possible forward-only, and the
 * alternative — disabled rows left behind — keeps a palette entry for a
 * placeholder that no longer resolves.
 *
 * ## Two indexes with no reader left
 *
 * `search_log_query_trgm_idx` existed for that scan; nothing else queries
 * `search_log` by similarity, so it is now pure write cost on a table written on
 * every search. `search_log_market_updated_at_index` was added hours earlier to
 * order `RecentSearches` by `updated_at`, which was only needed because the
 * buckets had been coarsened to days — they are hourly again, and that ordering
 * is back on `(market, hour_bucket)`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $chips = DB::table('page_blocks')
            ->join('page_block_variants', 'page_block_variants.block_id', '=', 'page_blocks.id')
            ->whereRaw('btrim(page_block_variants.body) = ?', [':related_searches'])
            ->select('page_blocks.id', 'page_blocks.page', 'page_blocks.region', 'page_blocks.language', 'page_blocks.position')
            ->distinct()
            ->get();

        $doomed = [];

        foreach ($chips as $chip) {
            $siblings = DB::table('page_blocks')
                ->where('page', $chip->page)
                ->where('region', $chip->region)
                ->where('language', $chip->language)
                ->where('position', '<=', $chip->position)
                ->orderBy('position')
                ->get(['id', 'kind', 'position']);

            /*
             * Walk back to the heading that opens this section. Without one the
             * chips sit in the untitled section a region starts with, where the
             * paragraphs around them are somebody else's — so only the chips go.
             */
            $from = null;

            foreach ($siblings as $sibling) {
                if ($sibling->kind === 'heading') {
                    $from = $sibling->position;
                }
            }

            foreach ($siblings as $sibling) {
                if ($from !== null && $sibling->position >= $from) {
                    $doomed[] = $sibling->id;
                }
            }

            $doomed[] = $chip->id;
        }

        $doomed = array_values(array_unique($doomed));

        if ($doomed !== []) {
            // Variants first: the foreign key points this way, and the reverse
            // order would orphan a variant whose block is already gone.
            DB::table('page_block_variants')->whereIn('block_id', $doomed)->delete();
            DB::table('page_blocks')->whereIn('id', $doomed)->delete();
        }

        DB::statement('DROP INDEX IF EXISTS search_log_query_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS search_log_market_updated_at_index');
    }

    public function down(): void
    {
        // Forward-only. The blocks are gone along with whatever an editor had
        // made of them, and reseeding the original wording would not restore
        // that. The trigram index is the one half worth recreating by hand if
        // the chips ever come back:
        //
        //   CREATE INDEX search_log_query_trgm_idx
        //       ON search_log USING GIN (query gin_trgm_ops);
    }
};

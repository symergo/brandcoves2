<?php

declare(strict_types=1);

use App\Services\Pages\PageCopy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A sentence under the search results linking the gift landing pages the
 * results are tagged for (owner, 2026-11-15; `:gift_links`):
 *
 *     Koffiemolen als cadeau
 *     Producten uit deze resultaten passen bij Cadeaus voor papa die van koffie houdt.
 *     Wil je koffiemolen als cadeau geven of zelf krijgen? Bewaar koffiemolen op je cadeaulijst.
 *
 * It goes in the ":term als cadeau" section, above the sentence that saves
 * the search: that section is already about giving, so it needs no heading
 * of its own. Inserted only before that section's shipped paragraph, and
 * only once; an edited layout is left alone.
 */
return new class extends Migration
{
    private const COPY = [
        'nl' => ['Wil je :term als cadeau geven of zelf krijgen? :save_search_link.', 'Producten uit deze resultaten passen bij :gift_links.'],
        'en' => ['Want to give :term as a gift, or get it yourself? :save_search_link.', 'Products in these results fit :gift_links.'],
        'fr' => ['Vous voulez offrir :term ou le recevoir vous-même ? :save_search_link.', 'Des produits de ces résultats conviennent pour :gift_links.'],
    ];

    public function up(): void
    {
        foreach (self::COPY as $language => [$anchor, $paragraph]) {
            $blocks = fn () => DB::table('page_blocks as b')
                ->join('page_block_variants as v', 'v.block_id', '=', 'b.id')
                ->where('b.page', 'search')
                ->where('b.region', 'below_grid')
                ->where('b.language', $language);

            $anchorBlock = $blocks()->where('v.body', $anchor)->first(['b.id', 'b.position']);

            if ($anchorBlock === null || $blocks()->where('v.body', 'like', '%:gift_links%')->exists()) {
                continue;
            }

            $position = (int) $anchorBlock->position;
            $now = now();

            // Make room where the anchor stands; the new sentence takes its place.
            DB::table('page_blocks')
                ->where('page', 'search')
                ->where('region', 'below_grid')
                ->where('language', $language)
                ->where('position', '>=', $position)
                ->increment('position');

            $id = DB::table('page_blocks')->insertGetId([
                'page' => 'search',
                'region' => 'below_grid',
                'language' => $language,
                'kind' => 'paragraph',
                'position' => $position,
                'conditions' => json_encode([]),
                'enabled' => true,
                'note' => 'Added 2026-11-15: gift pages these results are tagged for.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('page_block_variants')->insert([
                'block_id' => $id,
                'body' => $paragraph,
                'weight' => 1,
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        PageCopy::flush();
    }

    public function down(): void
    {
        // Forward-only: the copy is the editor's to change in /admin.
    }
};

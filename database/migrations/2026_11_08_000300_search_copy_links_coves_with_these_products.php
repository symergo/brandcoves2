<?php

declare(strict_types=1);

use App\Services\Pages\PageCopy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A section under the search results linking our Coves that hold products
 * from these results (owner, 2026-11-08: "add some sections with links
 * integrated to guides related to the search"):
 *
 *     Cadeau-ideeën met lenovo
 *     Je vindt lenovo ook in Cadeaus voor de student, De beste tablets.
 *
 * Placed straight after "Meer lezen over :term", which `:guide_links` fills by
 * title and now also by the results' category. Inserted only after that
 * section's shipped paragraph, and only once; an edited layout is left alone.
 */
return new class extends Migration
{
    private const COPY = [
        'nl' => ['Lees ook :guide_links.', 'Cadeau-ideeën met :term', 'Je vindt :term ook in :cove_links.'],
        'en' => ['See also :guide_links.', 'Gift ideas with :term', 'You will also find :term in :cove_links.'],
        'fr' => ['Lisez aussi :guide_links.', 'Idées cadeaux avec :term', 'Vous retrouvez aussi :term dans :cove_links.'],
    ];

    public function up(): void
    {
        foreach (self::COPY as $language => [$anchor, $heading, $paragraph]) {
            $blocks = fn () => DB::table('page_blocks as b')
                ->join('page_block_variants as v', 'v.block_id', '=', 'b.id')
                ->where('b.page', 'search')
                ->where('b.region', 'below_grid')
                ->where('b.language', $language);

            $anchorBlock = $blocks()->where('v.body', $anchor)->first(['b.id', 'b.position']);

            if ($anchorBlock === null || $blocks()->where('v.body', $paragraph)->exists()) {
                continue;
            }

            $position = (int) $anchorBlock->position;
            $now = now();

            DB::table('page_blocks')
                ->where('page', 'search')
                ->where('region', 'below_grid')
                ->where('language', $language)
                ->where('position', '>', $position)
                ->increment('position', 2);

            foreach ([['heading', $heading], ['paragraph', $paragraph]] as $offset => [$kind, $body]) {
                $id = DB::table('page_blocks')->insertGetId([
                    'page' => 'search',
                    'region' => 'below_grid',
                    'language' => $language,
                    'kind' => $kind,
                    'position' => $position + $offset + 1,
                    'conditions' => json_encode([]),
                    'enabled' => true,
                    'note' => 'Added 2026-11-08: Coves holding products from these results.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('page_block_variants')->insert([
                    'block_id' => $id,
                    'body' => $body,
                    'weight' => 1,
                    'enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        PageCopy::flush();
    }

    public function down(): void
    {
        // Forward-only: the copy is the editor's to change in /admin.
    }
};

<?php

declare(strict_types=1);

use App\Services\Pages\PageCopy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Under "Andere merken" on the search page, a question above the list (owner,
 * 2026-11-11: "Voeg toe: Welke andere merken verkopen <categorieën>?"):
 *
 *     Andere merken
 *     Welke andere merken verkopen autospeaker, constructiespeelgoed en midi controller?
 *     Autospeaker: JBL, Pioneer
 *     ...
 *
 * Inserted straight after the shipped heading, once, and only where that
 * heading still stands; `:other_brand_categories` is empty exactly when the
 * list is, so the question never shows alone. Data only.
 */
return new class extends Migration
{
    private const COPY = [
        'nl' => ['Andere merken', 'Welke andere merken verkopen :other_brand_categories?'],
        'en' => ['Other brands', 'Which other brands sell :other_brand_categories?'],
        'fr' => ['Autres marques', 'Quelles autres marques vendent :other_brand_categories ?'],
    ];

    public function up(): void
    {
        foreach (self::COPY as $language => [$heading, $question]) {
            $blocks = fn () => DB::table('page_blocks as b')
                ->join('page_block_variants as v', 'v.block_id', '=', 'b.id')
                ->where('b.page', 'search')
                ->where('b.region', 'below_grid')
                ->where('b.language', $language);

            $anchor = $blocks()->where('b.kind', 'heading')->where('v.body', $heading)->first(['b.id', 'b.position']);

            if ($anchor === null || $blocks()->where('v.body', $question)->exists()) {
                continue;
            }

            $position = (int) $anchor->position;
            $now = now();

            DB::table('page_blocks')
                ->where('page', 'search')
                ->where('region', 'below_grid')
                ->where('language', $language)
                ->where('position', '>', $position)
                ->increment('position');

            $id = DB::table('page_blocks')->insertGetId([
                'page' => 'search',
                'region' => 'below_grid',
                'language' => $language,
                'kind' => 'paragraph',
                'position' => $position + 1,
                'conditions' => json_encode([]),
                'enabled' => true,
                'note' => 'Added 2026-11-11: the question above the other-brands list.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('page_block_variants')->insert([
                'block_id' => $id,
                'body' => $question,
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

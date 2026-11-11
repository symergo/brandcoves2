<?php

declare(strict_types=1);

use App\Services\Pages\PageCopy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The other-brands section under the search results, one line per kind of
 * product (owner, 2026-11-11). The search "custom" printed
 *
 *     Merken zoals Hot Wheels
 *     Zoek je alternatieve merken voor Hot Wheels (autospeaker,
 *     constructiespeelgoed, midi controller)? Bekijk dan de aanbiedingen en
 *     offertes van Lego, JBL, Geomag, Hercules, Pioneer, Mega Bloks.
 *
 * Hot Wheels was only the most common brand among very different results, and
 * the brands of four kinds of product ran together. Now:
 *
 *     Andere merken
 *     Autospeaker: JBL, Pioneer
 *     Constructiespeelgoed: Lego, Geomag, Mega Bloks
 *     Midi controller: Hercules
 *
 * through `:other_brands_by_category`. Only a block still carrying a shipped
 * text is changed; an editor's own wording is left alone. Data only.
 */
return new class extends Migration
{
    /** @var array<string, array{heading: array{0: list<string>, 1: string}, paragraph: array{0: list<string>, 1: string}}> */
    private const COPY = [
        'nl' => [
            'heading' => [['Merken zoals :top_brand'], 'Andere merken'],
            'paragraph' => [[
                'Zoek je alternatieve merken voor :top_brand:result_categories? Bekijk dan de aanbiedingen en offertes van :other_brand_links.',
                'Zoek je alternatieve merken voor :top_brand? Bekijk dan de aanbiedingen en offertes van :other_brand_links.',
            ], ':other_brands_by_category'],
        ],
        'en' => [
            'heading' => [['Brands like :top_brand'], 'Other brands'],
            'paragraph' => [[
                'Looking for alternative brands to :top_brand:result_categories? Then see the offers from :other_brand_links.',
                'Looking for alternative brands to :top_brand? Then see the offers from :other_brand_links.',
            ], ':other_brands_by_category'],
        ],
        'fr' => [
            'heading' => [['Des marques comme :top_brand'], 'Autres marques'],
            'paragraph' => [[
                'Vous cherchez des marques alternatives à :top_brand:result_categories ? Découvrez alors les offres de :other_brand_links.',
                'Vous cherchez des marques alternatives à :top_brand ? Découvrez alors les offres de :other_brand_links.',
            ], ':other_brands_by_category'],
        ],
    ];

    public function up(): void
    {
        foreach (self::COPY as $language => $parts) {
            $blockIds = DB::table('page_blocks')
                ->where('page', 'search')
                ->where('region', 'below_grid')
                ->where('language', $language)
                ->pluck('id');

            foreach ($parts as [$old, $new]) {
                DB::table('page_block_variants')
                    ->whereIn('block_id', $blockIds)
                    ->whereIn('body', $old)
                    ->update(['body' => $new, 'updated_at' => now()]);
            }
        }

        PageCopy::flush();
    }

    public function down(): void
    {
        // Forward-only: the copy is the editor's to change in /admin.
    }
};

<?php

declare(strict_types=1);

use App\Services\Pages\PageCopy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The alternative-brands sentence names the categories of the results (owner,
 * 2026-11-08: "mention the categories if that makes sense"):
 *
 *     Zoek je alternatieve merken voor Lenovo (tablet, laptop)? Bekijk dan ...
 *
 * `:result_categories` carries its own brackets and is empty when no category
 * name is fit to print (`ResultCategories::shown()`), so one phrasing covers
 * both cases: without categories it reads exactly as before.
 *
 * Only the wording from `2026_11_08_000100` is changed; an edited block is the
 * editor's.
 */
return new class extends Migration
{
    private const COPY = [
        'nl' => [
            'Zoek je alternatieve merken voor :top_brand? Bekijk dan de aanbiedingen en offertes van :other_brand_links.',
            'Zoek je alternatieve merken voor :top_brand:result_categories? Bekijk dan de aanbiedingen en offertes van :other_brand_links.',
        ],
        'en' => [
            'Looking for alternative brands to :top_brand? Then see the offers from :other_brand_links.',
            'Looking for alternative brands to :top_brand:result_categories? Then see the offers from :other_brand_links.',
        ],
        'fr' => [
            'Vous cherchez des marques alternatives à :top_brand ? Découvrez alors les offres de :other_brand_links.',
            'Vous cherchez des marques alternatives à :top_brand:result_categories ? Découvrez alors les offres de :other_brand_links.',
        ],
    ];

    public function up(): void
    {
        foreach (self::COPY as $language => [$old, $new]) {
            $blockIds = DB::table('page_blocks')
                ->where('page', 'search')
                ->where('region', 'below_grid')
                ->where('language', $language)
                ->pluck('id');

            DB::table('page_block_variants')
                ->whereIn('block_id', $blockIds)
                ->where('body', $old)
                ->update(['body' => $new, 'updated_at' => now()]);
        }

        PageCopy::flush();
    }

    public function down(): void
    {
        // Forward-only: the copy is the editor's to change in /admin.
    }
};

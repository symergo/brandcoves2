<?php

declare(strict_types=1);

use App\Services\Pages\PageCopy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The copy under the search results, revised (owner, 2026-11-08):
 *
 * - the price section ("Prijzen voor :term" and its paragraph) is gone;
 * - ":term het goedkoopst kopen" is ":term prijzen vergelijken", and that
 *   section now names the shops it compares, each an affiliate link to its
 *   cheapest product here (`:shop_links`);
 * - "Kijk dan ook bij :term_links." is "Vergelijk dan de beste aanbiedingen
 *   voor :term_links.";
 * - a new section names other brands of this kind of product
 *   (`:other_brand_links`).
 *
 * Dutch, English and French alike, so the three languages keep one shape.
 *
 * ## Only the shipped wording is touched
 *
 * Every change matches a variant on its exact body as shipped on 2026-11-07.
 * A block an editor rewrote in /admin is theirs and is left alone; an insert
 * whose anchor is not found is skipped. Data only, no schema, so it cannot fail
 * a deploy for a schema reason.
 */
return new class extends Migration
{
    private const COPY = [
        'nl' => [
            'remove' => ['Prijzen voor :term', 'Op deze pagina liggen de prijzen voor :term tussen :low en :high. Het verschil zit meestal in het product zelf: een duurder model heeft vaak meer functies of een groter formaat.'],
            'rename' => [
                ':term het goedkoopst kopen' => ':term prijzen vergelijken',
                'Kijk dan ook bij :term_links.' => 'Vergelijk dan de beste aanbiedingen voor :term_links.',
            ],
            'shops_after' => 'Deze pagina zet :shops aanbiedingen van webwinkels naast elkaar, de goedkoopste eerst. Je koopt altijd bij de winkel zelf; wij verkopen niets.',
            'shops' => 'Je vergelijkt hier onder meer de prijzen van :shop_links.',
            'brands_after' => 'Vergelijk dan de beste aanbiedingen voor :term_links.',
            'brands_heading' => 'Andere merken',
            'brands' => 'Dit soort producten vind je ook van :other_brand_links.',
        ],
        'en' => [
            'remove' => ['Prices for :term', 'On this page, prices for :term run from :low to :high. The difference is usually in the product itself: a dearer model often has more features or a larger size.'],
            'rename' => [
                'Buying :term for less' => 'Comparing :term prices',
                'Try :term_links.' => 'Then compare the best offers for :term_links.',
            ],
            'shops_after' => 'This page puts :shops offers from online shops side by side, the cheapest first. You always buy from the shop itself; we sell nothing.',
            'shops' => 'The shops compared here include :shop_links.',
            'brands_after' => 'Then compare the best offers for :term_links.',
            'brands_heading' => 'Other brands',
            'brands' => 'You will also find this kind of product from :other_brand_links.',
        ],
        'fr' => [
            'remove' => ['Prix pour :term', 'Sur cette page, les prix pour :term vont de :low à :high. La différence tient le plus souvent au produit lui-même : un modèle plus cher a souvent plus de fonctions ou un plus grand format.'],
            'rename' => [
                'Acheter :term moins cher' => 'Comparer les prix de :term',
                'Regardez aussi :term_links.' => 'Comparez alors les meilleures offres pour :term_links.',
            ],
            'shops_after' => 'Cette page met côte à côte :shops offres de boutiques en ligne, la moins chère d\'abord. Vous achetez toujours auprès de la boutique elle-même ; nous ne vendons rien.',
            'shops' => 'Les boutiques comparées ici comprennent :shop_links.',
            'brands_after' => 'Comparez alors les meilleures offres pour :term_links.',
            'brands_heading' => 'Autres marques',
            'brands' => 'Vous trouverez aussi ce type de produit chez :other_brand_links.',
        ],
    ];

    public function up(): void
    {
        foreach (self::COPY as $language => $copy) {
            foreach ($copy['remove'] as $body) {
                $ids = $this->blocksWith($language, $body);
                if ($ids !== []) {
                    DB::table('page_blocks')->whereIn('id', $ids)->update(['enabled' => false, 'updated_at' => now()]);
                }
            }

            foreach ($copy['rename'] as $old => $new) {
                $ids = $this->blocksWith($language, $old);
                if ($ids !== []) {
                    DB::table('page_block_variants')->whereIn('block_id', $ids)->where('body', $old)
                        ->update(['body' => $new, 'updated_at' => now()]);
                }
            }

            $this->insertAfter($language, $copy['shops_after'], [['paragraph', $copy['shops']]]);
            $this->insertAfter($language, $copy['brands_after'], [
                ['heading', $copy['brands_heading']],
                ['paragraph', $copy['brands']],
            ]);
        }

        PageCopy::flush();
    }

    public function down(): void
    {
        // Forward-only: the copy is the editor's to change in /admin.
    }

    /** @return list<int> the search below-grid blocks in this language with a variant of exactly this body */
    private function blocksWith(string $language, string $body): array
    {
        return DB::table('page_blocks as b')
            ->join('page_block_variants as v', 'v.block_id', '=', 'b.id')
            ->where('b.page', 'search')
            ->where('b.region', 'below_grid')
            ->where('b.language', $language)
            ->where('v.body', $body)
            ->pluck('b.id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * New blocks straight after the block carrying $anchor, moving the rest
     * down. Skipped when the anchor is gone, or when the first new body is
     * already there (a re-run).
     *
     * @param  list<array{0: string, 1: string}>  $blocks  kind, body
     */
    private function insertAfter(string $language, string $anchor, array $blocks): void
    {
        $anchorId = $this->blocksWith($language, $anchor)[0] ?? null;

        if ($anchorId === null || $this->blocksWith($language, $blocks[0][1]) !== []) {
            return;
        }

        $position = (int) DB::table('page_blocks')->where('id', $anchorId)->value('position');
        $now = now();

        DB::table('page_blocks')
            ->where('page', 'search')
            ->where('region', 'below_grid')
            ->where('language', $language)
            ->where('position', '>', $position)
            ->increment('position', count($blocks));

        foreach ($blocks as $offset => [$kind, $body]) {
            $id = DB::table('page_blocks')->insertGetId([
                'page' => 'search',
                'region' => 'below_grid',
                'language' => $language,
                'kind' => $kind,
                'position' => $position + $offset + 1,
                'conditions' => json_encode([]),
                'enabled' => true,
                'note' => 'Added 2026-11-08: the shops compared, and other brands.',
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
};

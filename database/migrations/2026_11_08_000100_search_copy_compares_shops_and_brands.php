<?php

declare(strict_types=1);

use App\Services\Pages\PageCopy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The copy under the search results, rewritten (owner, 2026-11-08), in Dutch,
 * English and French:
 *
 * - one price-comparison section instead of three (":term vergelijken",
 *   "Prijzen voor :term" and ":term het goedkoopst kopen"), and no price-range
 *   paragraph;
 * - it names the shops it compares, each an affiliate link to its cheapest
 *   product here (`:shop_links`): "Je vergelijkt hier prijzen voor :term bij
 *   ...";
 * - other brands, against a brand found here: "Zoek je merken die vergelijkbaar
 *   zijn met :top_brand?" (`:other_brand_links`);
 * - "Kijk dan ook bij :term_links." is "Vergelijk dan de beste aanbiedingen
 *   voor :term_links.".
 *
 * ## The page's own words, not generic ones
 *
 * The owner's rule for this copy: no "bij deze zoekopdracht", no "producten".
 * Every sentence names the search term, a brand or a shop that was found. A
 * sentence that would read the same on every results page is the kind of
 * repeated text search engines discount, and it tells the reader nothing.
 *
 * ## Only copy nobody edited is replaced
 *
 * A language's search copy is replaced whole, and only when every block in it
 * still carries one of the known texts: the copy shipped on 2026-11-07, or the
 * intermediate wording this migration had on a development database for an
 * hour that day. One block an editor rewrote in /admin and that language is
 * left alone. Data only, so it cannot fail a deploy for a schema reason.
 */
return new class extends Migration
{
    /** @var array<string, list<array{0: string, 1: list<string>, 2: string}>> kind, conditions, body */
    private const COPY = [
        'nl' => [
            ['heading', [], ':term prijzen vergelijken'],
            ['paragraph', [], 'Je vergelijkt hier prijzen voor :term bij :shop_links, de goedkoopste aanbieding eerst. Via "Bekijk alle aanbiedingen" zie je wat elke winkel vraagt, en je koopt altijd bij de winkel zelf.'],
            // Not opening on :term: the term is lowercase as typed, and a
            // sentence would start with a small letter.
            ['paragraph', ['has_brands'], 'Je vindt hier :term van onder meer :brand_links.'],
            ['paragraph', ['has_discount'], 'Voor :term liggen :reduced prijzen nu lager dan de gewone prijs van de afgelopen 30 dagen, tot :percent% minder.'],
            ['heading', [], 'Merken zoals :top_brand'],
            ['paragraph', [], 'Zoek je merken die vergelijkbaar zijn met :top_brand? Kijk dan ook bij :other_brand_links.'],
            ['heading', [], 'Meer lezen over :term'],
            ['paragraph', [], 'Lees ook :guide_links.'],
            ['heading', [], 'Zoek je iets specifiekers?'],
            ['paragraph', [], 'Vergelijk dan de beste aanbiedingen voor :term_links.'],
            ['heading', [], ':term als cadeau'],
            ['paragraph', [], 'Is het voor iemand anders? Met :gift_finder_link krijg je ideeën op basis van wie het is en wat die graag doet, en in onze :guides_link lees je waar je op let.'],
        ],
        'en' => [
            ['heading', [], 'Comparing :term prices'],
            ['paragraph', [], 'Here you compare prices for :term at :shop_links, the cheapest offer first. "See all offers" shows what each shop asks, and you always buy from the shop itself.'],
            ['paragraph', ['has_brands'], 'You will find :term here from brands such as :brand_links.'],
            ['paragraph', ['has_discount'], 'For :term, :reduced prices are now below their usual price over the last 30 days, by up to :percent%.'],
            ['heading', [], 'Brands like :top_brand'],
            ['paragraph', [], 'Looking for brands similar or comparable to :top_brand? Have a look at brands such as :other_brand_links.'],
            ['heading', [], 'More to read about :term'],
            ['paragraph', [], 'See also :guide_links.'],
            ['heading', [], 'Looking for something more specific?'],
            ['paragraph', [], 'Then compare the best offers for :term_links.'],
            ['heading', [], ':term as a gift'],
            ['paragraph', [], 'Is it for someone else? :gift_finder_link gives you ideas based on who they are and what they enjoy, and our :guides_link say what to look out for.'],
        ],
        'fr' => [
            ['heading', [], 'Comparer les prix de :term'],
            ['paragraph', [], 'Vous comparez ici les prix de :term chez :shop_links, l\'offre la moins chère d\'abord. « Voir toutes les offres » montre ce que demande chaque boutique, et vous achetez toujours auprès de la boutique elle-même.'],
            ['paragraph', ['has_brands'], 'Vous trouvez ici :term de marques comme :brand_links.'],
            ['paragraph', ['has_discount'], 'Pour :term, :reduced prix sont maintenant inférieurs au prix habituel des 30 derniers jours, jusqu\'à :percent % de moins.'],
            ['heading', [], 'Des marques comme :top_brand'],
            ['paragraph', [], 'Vous cherchez des marques similaires ou comparables à :top_brand ? Découvrez aussi :other_brand_links.'],
            ['heading', [], 'À lire sur :term'],
            ['paragraph', [], 'Lisez aussi :guide_links.'],
            ['heading', [], 'Vous cherchez quelque chose de plus précis ?'],
            ['paragraph', [], 'Comparez alors les meilleures offres pour :term_links.'],
            ['heading', [], ':term en cadeau'],
            ['paragraph', [], 'C\'est pour quelqu\'un d\'autre ? :gift_finder_link vous donne des idées selon qui c\'est et ce qu\'il ou elle aime, et nos :guides_link disent à quoi faire attention.'],
        ],
    ];

    /**
     * Every text a block may carry for its language to count as unedited: the
     * copy shipped on 2026-11-07 (`2026_11_07_000300_search_copy_with_links`)
     * and the intermediate wording of this migration.
     *
     * @var array<string, list<string>>
     */
    private const KNOWN = [
        'nl' => [
            ':term vergelijken',
            'Bij deze zoekopdracht vind je producten van onder meer :brand_links. Elk product staat er één keer, met de laagste prijs en de winkel waar je die vindt. Via "Bekijk alle aanbiedingen" zie je wat de andere winkels vragen.',
            'Prijzen voor :term',
            'Op deze pagina liggen de prijzen voor :term tussen :low en :high. Het verschil zit meestal in het product zelf: een duurder model heeft vaak meer functies of een groter formaat.',
            ':reduced producten zijn nu goedkoper dan hun gewone prijs van de afgelopen 30 dagen, tot :percent% minder.',
            ':term het goedkoopst kopen',
            ':term prijzen vergelijken',
            'Deze pagina zet :shops aanbiedingen van webwinkels naast elkaar, de goedkoopste eerst. Je koopt altijd bij de winkel zelf; wij verkopen niets.',
            'Je vergelijkt hier onder meer de prijzen van :shop_links.',
            'Meer lezen over :term',
            'Lees ook :guide_links.',
            'Zoek je iets specifiekers?',
            'Kijk dan ook bij :term_links.',
            'Vergelijk dan de beste aanbiedingen voor :term_links.',
            'Andere merken',
            'Dit soort producten vind je ook van :other_brand_links.',
            ':term vind je hier onder meer van :brand_links.',
            ':term als cadeau',
            'Is het voor iemand anders? Met :gift_finder_link krijg je ideeën op basis van wie het is en wat die graag doet, en in onze :guides_link lees je waar je op let.',
        ],
        'en' => [
            'Comparing :term',
            'This search finds products from brands such as :brand_links. Each product appears once, with the lowest price and the shop that has it. "See all offers" shows what the other shops ask.',
            'Prices for :term',
            'On this page, prices for :term run from :low to :high. The difference is usually in the product itself: a dearer model often has more features or a larger size.',
            ':reduced products are now cheaper than their usual price over the last 30 days, by up to :percent%.',
            'Buying :term for less',
            'Comparing :term prices',
            'This page puts :shops offers from online shops side by side, the cheapest first. You always buy from the shop itself; we sell nothing.',
            'The shops compared here include :shop_links.',
            'More to read about :term',
            'See also :guide_links.',
            'Looking for something more specific?',
            'Try :term_links.',
            'Then compare the best offers for :term_links.',
            'Other brands',
            'You will also find this kind of product from :other_brand_links.',
            ':term as a gift',
            'Is it for someone else? :gift_finder_link gives you ideas based on who they are and what they enjoy, and our :guides_link say what to look out for.',
        ],
        'fr' => [
            'Comparer :term',
            'Cette recherche trouve des produits de marques comme :brand_links. Chaque produit n\'apparaît qu\'une fois, avec le prix le plus bas et la boutique qui le propose. « Voir toutes les offres » montre ce que demandent les autres boutiques.',
            'Prix pour :term',
            'Sur cette page, les prix pour :term vont de :low à :high. La différence tient le plus souvent au produit lui-même : un modèle plus cher a souvent plus de fonctions ou un plus grand format.',
            ':reduced produits sont maintenant moins chers que leur prix habituel des 30 derniers jours, jusqu\'à :percent % de moins.',
            'Acheter :term moins cher',
            'Comparer les prix de :term',
            'Cette page met côte à côte :shops offres de boutiques en ligne, la moins chère d\'abord. Vous achetez toujours auprès de la boutique elle-même ; nous ne vendons rien.',
            'Les boutiques comparées ici comprennent :shop_links.',
            'À lire sur :term',
            'Lisez aussi :guide_links.',
            'Vous cherchez quelque chose de plus précis ?',
            'Regardez aussi :term_links.',
            'Comparez alors les meilleures offres pour :term_links.',
            'Autres marques',
            'Vous trouverez aussi ce type de produit chez :other_brand_links.',
            ':term en cadeau',
            'C\'est pour quelqu\'un d\'autre ? :gift_finder_link vous donne des idées selon qui c\'est et ce qu\'il ou elle aime, et nos :guides_link disent à quoi faire attention.',
        ],
    ];

    public function up(): void
    {
        foreach (self::COPY as $language => $blocks) {
            $existing = DB::table('page_blocks')
                ->where('page', 'search')
                ->where('region', 'below_grid')
                ->where('language', $language)
                ->pluck('id')
                ->all();

            if ($existing === []) {
                // Nothing written in this language: the copy from 2026-11-07
                // seeds it, and a fresh database is not this migration's to fill.
                continue;
            }

            $bodies = DB::table('page_block_variants')->whereIn('block_id', $existing)->pluck('body')->all();
            $edited = array_diff($bodies, self::KNOWN[$language], array_column($blocks, 2));

            if ($edited !== []) {
                continue;
            }

            DB::transaction(function () use ($existing, $language, $blocks): void {
                // Cascades to the variants.
                DB::table('page_blocks')->whereIn('id', $existing)->delete();

                $now = now();

                foreach ($blocks as $index => [$kind, $conditions, $body]) {
                    $id = DB::table('page_blocks')->insertGetId([
                        'page' => 'search',
                        'region' => 'below_grid',
                        'language' => $language,
                        'kind' => $kind,
                        'position' => $index + 1,
                        'conditions' => json_encode($conditions),
                        'enabled' => true,
                        'note' => 'Rewritten 2026-11-08: one comparison section, named shops and brands.',
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
            });
        }

        PageCopy::flush();
    }

    public function down(): void
    {
        // Forward-only: the copy is the editor's to change in /admin.
    }
};

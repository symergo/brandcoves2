<?php

declare(strict_types=1);

use App\Services\Pages\PageCopy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The copy under the search results, again, in Dutch, English and French
 * (owner, 2026-11-07).
 *
 * They shipped in September (`page-blocks-2026-09.php`), eighteen blocks per
 * language, and only the Spanish were still there on production in November:
 * the other three had been taken out in /admin. A results page with no words
 * of its own is a page of product names any shop also has, which is the case
 * for putting copy back, and the owner asked for it with links rather than
 * words alone.
 *
 * ## Shorter than before, and built from the page
 *
 * About 150 to 200 words, nearly all of it the page's own facts (the price
 * range, how many offers, which brands, what is reduced), each block hidden
 * when its fact is missing. Long fixed text repeated over thousands of URLs is
 * what search engines treat as scaled content; the September copy was 18
 * blocks and mostly fixed sentences. Headings are the questions people type.
 *
 * ## Links rather than words
 *
 * `:brand_links` (brand pages), `:term_links` (narrower searches),
 * `:guide_links` (our guides and gift-idea pages about the term; its block is
 * left out when there are none), and `:gift_finder_link` and `:guides_link`
 * to Find a gift and the guides. All resolve per market, so one block is right
 * in every market of its language.
 *
 * ## Only where nobody wrote any
 *
 * A language that already has blocks in `search.below_grid` is left alone:
 * those are an editor's, and the admin is where they change. Additive, so it
 * cannot fail a deploy for a schema reason.
 */
return new class extends Migration
{
    /** @var array<string, list<array{kind: string, conditions: list<string>, body: string}>> */
    private const COPY = [
        'nl' => [
            ['kind' => 'heading', 'conditions' => [], 'body' => ':term vergelijken'],
            ['kind' => 'paragraph', 'conditions' => ['has_brands'], 'body' => 'Bij deze zoekopdracht vind je producten van onder meer :brand_links. Elk product staat er één keer, met de laagste prijs en de winkel waar je die vindt. Via "Bekijk alle aanbiedingen" zie je wat de andere winkels vragen.'],
            ['kind' => 'heading', 'conditions' => ['has_prices'], 'body' => 'Prijzen voor :term'],
            ['kind' => 'paragraph', 'conditions' => ['has_prices'], 'body' => 'Op deze pagina liggen de prijzen voor :term tussen :low en :high. Het verschil zit meestal in het product zelf: een duurder model heeft vaak meer functies of een groter formaat.'],
            ['kind' => 'paragraph', 'conditions' => ['has_discount'], 'body' => ':reduced producten zijn nu goedkoper dan hun gewone prijs van de afgelopen 30 dagen, tot :percent% minder.'],
            ['kind' => 'heading', 'conditions' => [], 'body' => ':term het goedkoopst kopen'],
            ['kind' => 'paragraph', 'conditions' => [], 'body' => 'Deze pagina zet :shops aanbiedingen van webwinkels naast elkaar, de goedkoopste eerst. Je koopt altijd bij de winkel zelf; wij verkopen niets.'],
            ['kind' => 'heading', 'conditions' => [], 'body' => 'Meer lezen over :term'],
            ['kind' => 'paragraph', 'conditions' => [], 'body' => 'Lees ook :guide_links.'],
            ['kind' => 'heading', 'conditions' => [], 'body' => 'Zoek je iets specifiekers?'],
            ['kind' => 'paragraph', 'conditions' => [], 'body' => 'Kijk dan ook bij :term_links.'],
            ['kind' => 'heading', 'conditions' => [], 'body' => ':term als cadeau'],
            ['kind' => 'paragraph', 'conditions' => [], 'body' => 'Is het voor iemand anders? Met :gift_finder_link krijg je ideeën op basis van wie het is en wat die graag doet, en in onze :guides_link lees je waar je op let.'],
        ],
        'en' => [
            ['kind' => 'heading', 'conditions' => [], 'body' => 'Comparing :term'],
            ['kind' => 'paragraph', 'conditions' => ['has_brands'], 'body' => 'This search finds products from brands such as :brand_links. Each product appears once, with the lowest price and the shop that has it. "See all offers" shows what the other shops ask.'],
            ['kind' => 'heading', 'conditions' => ['has_prices'], 'body' => 'Prices for :term'],
            ['kind' => 'paragraph', 'conditions' => ['has_prices'], 'body' => 'On this page, prices for :term run from :low to :high. The difference is usually in the product itself: a dearer model often has more features or a larger size.'],
            ['kind' => 'paragraph', 'conditions' => ['has_discount'], 'body' => ':reduced products are now cheaper than their usual price over the last 30 days, by up to :percent%.'],
            ['kind' => 'heading', 'conditions' => [], 'body' => 'Buying :term for less'],
            ['kind' => 'paragraph', 'conditions' => [], 'body' => 'This page puts :shops offers from online shops side by side, the cheapest first. You always buy from the shop itself; we sell nothing.'],
            ['kind' => 'heading', 'conditions' => [], 'body' => 'More to read about :term'],
            ['kind' => 'paragraph', 'conditions' => [], 'body' => 'See also :guide_links.'],
            ['kind' => 'heading', 'conditions' => [], 'body' => 'Looking for something more specific?'],
            ['kind' => 'paragraph', 'conditions' => [], 'body' => 'Try :term_links.'],
            ['kind' => 'heading', 'conditions' => [], 'body' => ':term as a gift'],
            ['kind' => 'paragraph', 'conditions' => [], 'body' => 'Is it for someone else? :gift_finder_link gives you ideas based on who they are and what they enjoy, and our :guides_link say what to look out for.'],
        ],
        'fr' => [
            ['kind' => 'heading', 'conditions' => [], 'body' => 'Comparer :term'],
            ['kind' => 'paragraph', 'conditions' => ['has_brands'], 'body' => 'Cette recherche trouve des produits de marques comme :brand_links. Chaque produit n\'apparaît qu\'une fois, avec le prix le plus bas et la boutique qui le propose. « Voir toutes les offres » montre ce que demandent les autres boutiques.'],
            ['kind' => 'heading', 'conditions' => ['has_prices'], 'body' => 'Prix pour :term'],
            ['kind' => 'paragraph', 'conditions' => ['has_prices'], 'body' => 'Sur cette page, les prix pour :term vont de :low à :high. La différence tient le plus souvent au produit lui-même : un modèle plus cher a souvent plus de fonctions ou un plus grand format.'],
            ['kind' => 'paragraph', 'conditions' => ['has_discount'], 'body' => ':reduced produits sont maintenant moins chers que leur prix habituel des 30 derniers jours, jusqu\'à :percent % de moins.'],
            ['kind' => 'heading', 'conditions' => [], 'body' => 'Acheter :term moins cher'],
            ['kind' => 'paragraph', 'conditions' => [], 'body' => 'Cette page met côte à côte :shops offres de boutiques en ligne, la moins chère d\'abord. Vous achetez toujours auprès de la boutique elle-même ; nous ne vendons rien.'],
            ['kind' => 'heading', 'conditions' => [], 'body' => 'À lire sur :term'],
            ['kind' => 'paragraph', 'conditions' => [], 'body' => 'Lisez aussi :guide_links.'],
            ['kind' => 'heading', 'conditions' => [], 'body' => 'Vous cherchez quelque chose de plus précis ?'],
            ['kind' => 'paragraph', 'conditions' => [], 'body' => 'Regardez aussi :term_links.'],
            ['kind' => 'heading', 'conditions' => [], 'body' => ':term en cadeau'],
            ['kind' => 'paragraph', 'conditions' => [], 'body' => 'C\'est pour quelqu\'un d\'autre ? :gift_finder_link vous donne des idées selon qui c\'est et ce qu\'il ou elle aime, et nos :guides_link disent à quoi faire attention.'],
        ],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::COPY as $language => $blocks) {
            $written = DB::table('page_blocks')
                ->where('page', 'search')
                ->where('region', 'below_grid')
                ->where('language', $language)
                ->exists();

            if ($written) {
                continue;
            }

            foreach ($blocks as $index => $block) {
                $id = DB::table('page_blocks')->insertGetId([
                    'page' => 'search',
                    'region' => 'below_grid',
                    'language' => $language,
                    'kind' => $block['kind'],
                    'position' => $index + 1,
                    'conditions' => json_encode($block['conditions']),
                    'enabled' => true,
                    'note' => 'Shipped 2026-11-07: shorter, built from the page, with links.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('page_block_variants')->insert([
                    'block_id' => $id,
                    'body' => $block['body'],
                    'weight' => 1,
                    'enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // The blocks are cached per language; a new deploy should show them at once.
        PageCopy::flush();
    }

    public function down(): void
    {
        // Forward-only: copy written here is the editor's to change in /admin.
    }
};

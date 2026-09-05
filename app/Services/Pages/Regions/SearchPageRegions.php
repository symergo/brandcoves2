<?php

declare(strict_types=1);

namespace App\Services\Pages\Regions;

use App\Services\Pages\Placeholders\SiteLink;

/**
 * Where prose can go on a search results page.
 *
 * ## Why a results page needs several hundred words at all
 *
 * A grid of cards is almost pure markup. Strip the prices and titles and there
 * is nothing left for a search engine to decide what the page is *about* — which
 * is why comparison sites rank for their guides and not for their listings.
 *
 * The line that must not be crossed is the obvious way to hit a word count:
 * repeat the query with filler around it. That works for about a fortnight, and
 * then a helpful-content update decides the domain is mostly padding and takes
 * the pages that were actually good down with it. So every block belongs to one
 * of two kinds — a fact about *this* page, read off the grid above it, or a true
 * explanation of how the site works, written once and worth reading. The
 * placeholder-availability rule enforces the first; nothing can enforce the
 * second except the person writing.
 */
final class SearchPageRegions
{
    public const PAGE = 'search';

    /** @return list<Region> */
    public static function all(): array
    {
        $facts = [
            'term', 'count', 'shown', 'shops', 'comparable',
            'reduced', 'percent', 'low', 'high', 'brands',
        ];

        /*
         * No :brand_page_link here.
         *
         * A search page has no single brand — its context supplies no `brand`
         * fact — so a block naming it would resolve to nothing and vanish
         * silently on every search page. `:brand_links` is the tool for this
         * page: every brand in the results, each linked.
         */
        $links = [
            'brand_links', 'term_links', 'term_page_link',
            ...array_keys(SiteLink::all()),
        ];

        $conditions = [
            new Condition('has_prices', 'At least one product has a price'),
            new Condition('has_discount', 'At least one product is below its 30-day median'),
            new Condition('multi_shop', 'At least one product is sold by more than one shop'),
            new Condition('has_brands', 'The results carry a brand'),
        ];

        return [
            new Region(
                page: self::PAGE,
                key: 'above_grid',
                label: 'Above the results',
                /*
                 * The warning is the point of this blurb.
                 *
                 * A shopper came for products. Several hundred words between
                 * them and the first card is a worse page for a human, and
                 * Google has said so for years — so this is not even a trade
                 * against ranking. The region exists because sometimes one
                 * sentence genuinely helps; saying so at the moment somebody is
                 * about to write six is the only guard that scales.
                 */
                blurb: 'Between the search box and the first product card. Keep it to a sentence or two — '
                    .'anything longer pushes the products down, which is a worse page for a shopper and for '
                    .'Google alike. Hidden on page 2, on a filtered URL, and when nothing matched. Note the '
                    .'word chips already render here, so :term_links would show them twice.',
                layout: Region::FLOW,
                // Ships empty on purpose: nothing appears on any page until
                // somebody deliberately writes it.
                requiresContent: false,
                placeholders: [...$facts, ...$links],
                conditions: $conditions,
            ),

            new Region(
                page: self::PAGE,
                key: 'below_grid',
                label: 'Below the results',
                blurb: 'After the products and the pagination, laid out in up to three columns. This is the '
                    .'page\'s long copy and where nearly all of it belongs. Hidden on page 2, on a filtered '
                    .'URL, and when nothing matched — a filtered variant is noindex, and repeating several '
                    .'hundred words across near-identical URLs is the doorway-page pattern.',
                layout: Region::SECTIONS,
                requiresContent: true,
                placeholders: [...$facts, ...$links],
                conditions: $conditions,
            ),

            new Region(
                page: self::PAGE,
                key: 'empty_state',
                label: 'When nothing matched',
                blurb: 'Under the "no results" line, which always renders whatever you write here. This is the '
                    .'one region shown on a page a crawler is told to ignore, because it is for the reader: a '
                    .'dead end is exactly where a way out belongs.',
                layout: Region::FLOW,
                requiresContent: true,
                /*
                 * No prices, no shops, no brands — there are no results to read
                 * them off. Offering a placeholder the page cannot answer would
                 * let somebody write a sentence that silently never appears.
                 *
                 * The links stay, and matter more here than anywhere: a dead end
                 * is exactly where a way out belongs.
                 */
                placeholders: [
                    'term', 'term_page_link',
                    ...array_keys(SiteLink::all()),
                ],
                conditions: [],
            ),
        ];
    }
}

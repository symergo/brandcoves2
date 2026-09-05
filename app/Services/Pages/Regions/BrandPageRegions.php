<?php

declare(strict_types=1);

namespace App\Services\Pages\Regions;

use App\Services\Pages\Placeholders\SiteLink;

/**
 * Where prose can go on a brand page.
 *
 * ## `above_grid` exists again, and it ships empty
 *
 * A brand page used to open with ten slots of templated statistics — the
 * `brand_intro` surface — and they were deleted on 2026-08-10 because they were
 * arithmetic about the grid, written in sentences, identically on a thousand
 * pages. See docs/features/brand-pages.md.
 *
 * Three things have changed and one has not. The mechanism can no longer produce
 * that: every word here is typed by a person, and nothing assembles a sentence
 * out of a number. It ships **empty**, so the deletion is not being reversed —
 * a place is being made available. And the availability rule means a sentence
 * naming a number vanishes where the number is absent, which was one of the
 * named old failure modes: `:comparable` of zero asserting a comparison that
 * does not exist.
 *
 * What has not changed is the content judgement, so the region's blurb carries
 * it verbatim. Somebody about to ignore it reads it at the moment they are about
 * to.
 */
final class BrandPageRegions
{
    public const PAGE = 'brand';

    /** @return list<Region> */
    public static function all(): array
    {
        $facts = [
            'brand', 'count', 'shown', 'shops', 'comparable', 'reduced',
            'percent', 'low', 'high', 'shop', 'category', 'categories',
        ];

        // No :brand_links. A brand page's results are one brand by
        // construction, so the list would be a link back to the page you are on.
        $links = [
            'term_links', 'brand_page_link',
            ...array_keys(SiteLink::all()),
        ];

        $conditions = [
            new Condition('has_prices', 'At least one product has a price'),
            new Condition('has_discount', 'At least one product is below its 30-day median'),
            new Condition('multi_shop', 'At least one product is sold by more than one shop'),
            new Condition('has_top_shop', 'We know which shop carries most of this brand'),
            new Condition('has_top_category', 'We know the brand\'s leading category'),
            new Condition('has_categories', 'We know which categories the brand appears in'),
        ];

        return [
            new Region(
                page: self::PAGE,
                key: 'above_grid',
                label: 'Above the products',
                blurb: 'Between the brand heading and the first product card. A brand page opened with '
                    .'templated statistics until 2026-08-10, when they were removed for being arithmetic in '
                    .'sentences, the same on every brand. Keep whatever goes here short and worth reading, or '
                    .'leave it empty — which is how it ships. Hidden on page 2, on a sub-search, and on any '
                    .'filtered or re-sorted URL.',
                layout: Region::FLOW,
                requiresContent: false,
                placeholders: [...$facts, ...$links],
                conditions: $conditions,
            ),

            new Region(
                page: self::PAGE,
                key: 'below_grid',
                label: 'Below the products',
                blurb: 'Full width, after both columns, laid out in up to three columns. The page\'s long copy. '
                    .'Hidden on page 2, on a sub-search, and on any filtered or re-sorted URL — those are all '
                    .'noindex, and repeating the copy across them is the doorway-page pattern.',
                layout: Region::SECTIONS,
                requiresContent: true,
                placeholders: [...$facts, ...$links],
                conditions: $conditions,
            ),

            new Region(
                page: self::PAGE,
                key: 'empty_state',
                label: 'When the brand has nothing to show',
                blurb: 'Under the "nothing here" line, which always renders whatever you write. Shown even on a '
                    .'noindex sub-search, because it is for the reader rather than for a crawler.',
                layout: Region::FLOW,
                requiresContent: true,
                // No products, so no facts about them — but a reader at a dead
                // end still needs somewhere to go.
                placeholders: [
                    'brand', 'brand_page_link',
                    ...array_keys(SiteLink::all()),
                ],
                conditions: [],
            ),
        ];
    }
}

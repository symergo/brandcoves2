<?php

declare(strict_types=1);

namespace App\Services\Pages\Regions;

use App\Services\Pages\Placeholders\SiteLink;

/**
 * Where prose can go on a written brand or shop page.
 *
 * ## Two pages, one class
 *
 * A Brand Cove and a Shop Cove are one page shape — that is what
 * `CoveKind::isEntity()` asserts and what `docs/features/cove-entities.md`
 * argues. But they are two `page` keys rather than one, because the words differ
 * even where the layout does not: a band above a shop piece talks about buying
 * from somebody, a band above a brand piece talks about what somebody makes. One
 * key would force an editor to write a sentence true of both, and the sentence
 * that is true of both is the sentence worth nothing.
 *
 * ## What is templated here, and what is not
 *
 * **The Cove's own prose is not.** That is written per entity, in the planner or
 * over the editorial API, and it is the reason the page exists. These regions
 * are the parts *around* it that are the same on every entity page in a market —
 * a line about how the shortlist is chosen, a standing note about affiliate
 * links — so they can be changed without a deploy and without editing forty
 * Coves.
 *
 * That division is the whole point: **a place is a deploy, text is not.**
 *
 * ## The facts are about the sidebar, not the catalogue
 *
 * An entity Cove carries no shortlist of its own; the products beside it are a
 * live rail. So the facts a region may state here are read off *that rail* and
 * off the entity's totals — never off a page of results, because this page has
 * none. Same rule as everywhere else: a reader can check a claim about the eight
 * products they can see.
 */
final class EntityCoveRegions
{
    public const BRAND = 'brand_cove';

    public const SHOP = 'shop_cove';

    /** @return list<Region> */
    public static function all(): array
    {
        return [
            ...self::forPage(self::BRAND, 'brand'),
            ...self::forPage(self::SHOP, 'shop'),
        ];
    }

    /**
     * @return list<Region>
     */
    private static function forPage(string $page, string $noun): array
    {
        /*
         * Facts about the products *beside* the piece, plus the entity's own
         * totals. No `:term_links` and no `:brand_links`: this page has no
         * result set to draw words out of, and a brand page linking to itself
         * is the mistake `BrandPageRegions` already names.
         */
        $placeholders = [
            'entity', 'count', 'shown', 'shops', 'reduced', 'percent', 'low', 'high', 'categories',
            ...array_keys(SiteLink::all()),
        ];

        $conditions = [
            new Condition('has_prices', 'At least one product beside the piece has a price'),
            new Condition('has_discount', 'At least one is below its 30-day median'),
            new Condition('has_categories', "We know which categories this {$noun} sells in"),
        ];

        return [
            new Region(
                page: $page,
                key: 'above_prose',
                label: 'Above the writing',
                blurb: "Between the heading and the first paragraph of the {$noun} piece. Ships empty. "
                    .'Anything here appears on every written '.$noun.' page in the market, so it has to be '
                    .'true of all of them — the sentence about this particular '.$noun.' belongs in the '
                    .'piece itself, where somebody wrote it on purpose.',
                layout: Region::FLOW,
                requiresContent: false,
                placeholders: $placeholders,
                conditions: $conditions,
            ),
            new Region(
                page: $page,
                key: 'below_prose',
                label: 'Under the writing',
                blurb: 'After the last paragraph, above the products people have wish-listed. Ships empty. '
                    .'The place for a standing note — how the shortlist beside the piece is chosen, or what '
                    .'a discount is measured against — rather than for more about this '.$noun.'.',
                layout: Region::FLOW,
                requiresContent: false,
                placeholders: $placeholders,
                conditions: $conditions,
            ),
            new Region(
                page: $page,
                key: 'sidebar',
                label: 'In the sidebar, under the products',
                blurb: 'Between the product lists and the "see all" link. Ships empty, and it is the '
                    .'narrowest column on the page — two lines read well here and a paragraph does not. '
                    .'The place for a note about the products beside it: that a discount is measured '
                    .'against our own 30-day median rather than a crossed-out price, or where a '
                    .'popularity ranking came from.',
                layout: Region::FLOW,
                requiresContent: false,
                placeholders: $placeholders,
                conditions: $conditions,
            ),
        ];
    }
}

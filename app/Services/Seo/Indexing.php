<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Enums\Market;
use App\Models\Product;
use App\Models\ProductGroup;

/**
 * Whether a page has enough on it to be offered to a search engine.
 *
 * The rule since 2026-09-12 is that every public page is indexable (docs/features/seo.md). This is the one
 * exception to it, added 2026-11-15 on the owner's worry that Google sees thin English pages beside a strong
 * be-nl, and limited to what the production count of that day showed to be thin:
 *
 * | market | product groups | with no offer at all |
 * |--------|---------------:|---------------------:|
 * | be-nl  |      2,404,692 |         62,516 (3%)  |
 * | be-fr  |      2,064,703 |         60,996 (3%)  |
 * | nl-nl  |      1,225,150 |         25,518 (2%)  |
 * | en     |         33,135 |         12,979 (39%) |
 *
 * The English Coves were not thin: 97 published against 151 on be-nl, with as many picks and as much text
 * each, and 98% of their picks buyable. What is thin in English is the catalogue. Two in five English
 * product pages had nothing to buy, where elsewhere it is one in forty, so there an unbuyable product is
 * what the market looks like to a crawler and not the odd page out.
 *
 * So: an English product page with nothing to buy says `noindex, follow`, and claims no language twins
 * (a page that asks to be left out of the index must not sit in an hreflang set; its buyable twins already
 * leave it out, {@see Alternates::forProducts()} pairs presentable products only). Its links are still
 * followed and the page still works for a visitor. Every other market keeps the 2026-09-12 rule: there
 * the argument for it holds, a product that comes back in stock is then already known to the index.
 *
 * To widen or drop the exception, change {@see self::MARKETS}; nothing else knows which markets it covers.
 *
 * Also here, since the same day: which product links on a search page a crawler is asked to follow
 * ({@see self::followFromSearch()}). That steers crawling; it takes no page out of the index.
 */
final class Indexing
{
    /** The markets in which a product page with nothing to buy stays out of the index. */
    private const MARKETS = [Market::En];

    /**
     * Whether a search result's link to its product page may be followed by a crawler.
     *
     * Owner, 2026-11-15: "minder crawl budget naar producten en meer naar search results". A search page links
     * to up to 48 product pages, and there are 5.7 million of them against a few thousand search pages worth
     * ranking, so a crawler that follows every card spends its visit on products. The link is followed only
     * where the product page has something a shop's own page has not: the comparison, so a product sold by
     * more than one shop (72,209 of 2.4 million on be-nl that day, 3%). Every other card's link is `nofollow`.
     *
     * What this is not: the product pages stay indexable, stay in nobody's robots.txt, and are still linked
     * (followed) from the Coves that pick them and from brand pages; a visitor notices nothing. `nofollow` is
     * a hint that steers a crawl, not a lock, and it passes no link value to the pages it does link, which is
     * the price of it (docs/features/seo.md says what was weighed). To undo it, return true here.
     */
    public static function followFromSearch(ProductGroup $group): bool
    {
        return $group->merchant_count > 1;
    }

    /**
     * The robots value of a product page: null to be indexed, as everywhere.
     *
     * @param  array<int, Product>  $offers  the active offers the page shows, sold-out ones included
     */
    public static function product(ProductGroup $group, array $offers): ?string
    {
        if (! in_array($group->market, self::MARKETS, true)) {
            return null;
        }

        foreach ($offers as $offer) {
            if ($offer->availability->isBuyable()) {
                return null;
            }
        }

        return 'noindex, follow';
    }
}

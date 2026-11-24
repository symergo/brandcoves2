<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Enums\Market;
use App\Models\Product;
use App\Models\ProductGroup;
use Illuminate\Support\Facades\DB;

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
 * ## The second exception: one shop, no Cove (owner, 2026-11-24)
 *
 * A product page that only one shop sells shows that shop's feed text, which Google already has from the
 * shop itself, and there are millions of them. What a page of ours adds is a comparison (two or more shops)
 * or writing (a published Cove names it). Without either it is `noindex, follow`: still a page for a
 * visitor, its links still followed, only not offered to the index. `index_single_shop_products` in
 * config/giftcoves.php turns it off. It was asked for as a Google improvement without Search Console data
 * (none was connected); how much search traffic single-shop pages brought was not known when it shipped.
 */
final class Indexing
{
    /** The markets in which a product page with nothing to buy stays out of the index. */
    private const MARKETS = [Market::En];

    /**
     * The robots value of a product page: null to be indexed, as everywhere.
     *
     * @param  array<int, Product>  $offers  the active offers the page shows, sold-out ones included
     */
    public static function product(ProductGroup $group, array $offers): ?string
    {
        if (! in_array($group->market, self::MARKETS, true)) {
            return self::hasSomethingOfItsOwn($group) ? null : 'noindex, follow';
        }

        foreach ($offers as $offer) {
            if ($offer->availability->isBuyable()) {
                return null;
            }
        }

        return 'noindex, follow';
    }

    /**
     * Two or more shops to compare, or a published Cove that writes about it (see the class docblock).
     * One indexed lookup on daily_picks (group_id, created_at) for a single-shop product; none otherwise.
     */
    private static function hasSomethingOfItsOwn(ProductGroup $group): bool
    {
        if (config('giftcoves.index_single_shop_products') || (int) $group->merchant_count >= 2) {
            return true;
        }

        return DB::table('daily_picks')
            ->join('daily_pick_sets', 'daily_pick_sets.id', '=', 'daily_picks.set_id')
            ->where('daily_picks.group_id', $group->id)
            ->whereNotNull('daily_pick_sets.published_at')
            ->where('daily_pick_sets.published_at', '<=', now())
            ->exists();
    }
}

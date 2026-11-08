<?php

declare(strict_types=1);

namespace App\Services\Pages\Placeholders;

use App\Enums\ProductStatus;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Pages\Context\PageContext;

/**
 * The shops whose prices these results compare, each linked to its cheapest
 * product here (owner, 2026-11-08: "include the shops where the prices are
 * compared in that section with an affiliate link to the shop (can be a
 * product link from the results)").
 *
 * ## A product, not the shop's home page or its search
 *
 * The link is the click-out of that shop's cheapest offer among the results,
 * so it lands on something this page is about, carries the network's
 * tracking, and is recorded like every other click-out. A link to the shop's
 * own search for the term was the other option the owner named; nothing in the
 * catalogue says how each shop builds its search address, and a guessed one
 * is a broken link with our name on it.
 *
 * Marked `sponsored`: it is an affiliate link, and `/go/` is closed to
 * crawlers in robots.txt. Six at most, the most offers first, the same cap a
 * sentence of brands has.
 */
final class ShopLinks implements PlaceholderFunction
{
    private const LIMIT = 6;

    public function name(): string
    {
        return 'shop_links';
    }

    public function label(): string
    {
        return 'Shops in these results, linked';
    }

    public function help(): string
    {
        return 'The shops whose offers these results compare, the most offers first, at most six. Each links (as an affiliate link) to its cheapest product here. Empty when no shop is known, which hides the block.';
    }

    public function level(): Level
    {
        return Level::Inline;
    }

    public function absent(): Absence
    {
        return Absence::Blank;
    }

    public function sample(): Value
    {
        return Value::links([
            ['label' => 'bol', 'url' => '#', 'rel' => 'sponsored nofollow'],
            ['label' => 'Coolblue', 'url' => '#', 'rel' => 'sponsored nofollow'],
        ]);
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function resolve(PageContext $context): Value
    {
        $groupIds = array_values(array_filter(array_map(
            fn ($group) => $group instanceof ProductGroup ? $group->id : null,
            $context->items,
        )));

        if ($groupIds === []) {
            return Value::nothing();
        }

        // Every live offer of these products that has a shop, cheapest first:
        // the first one met per shop is its link. A results page is a few
        // dozen products, so this is one indexed read of a few hundred rows.
        $offers = Product::query()
            ->forMarket($context->market)
            ->whereIn('group_id', $groupIds)
            ->whereNotNull('merchant_id')
            ->whereNotNull('price')
            ->whereIn('status', [ProductStatus::Active->value, ProductStatus::Stale->value])
            ->orderBy('price')
            ->get(['id', 'market', 'source', 'merchant_id', 'price', 'affiliate_url']);

        $byShop = [];

        foreach ($offers as $offer) {
            $byShop[$offer->merchant_id]['count'] = ($byShop[$offer->merchant_id]['count'] ?? 0) + 1;
            $byShop[$offer->merchant_id]['offer'] ??= $offer;
        }

        uasort($byShop, fn (array $a, array $b) => $b['count'] <=> $a['count']);

        $merchants = Merchant::query()
            ->whereIn('id', array_keys($byShop))
            ->where('enabled', true)
            ->get()
            ->keyBy('id');

        $links = [];
        $seen = [];

        foreach ($byShop as $merchantId => $shop) {
            $merchant = $merchants->get($merchantId);
            $url = $merchant === null || ! $shop['offer']->hasSafeAffiliateUrl() ? null : $shop['offer']->outboundUrl();

            if ($url === null) {
                continue;
            }

            // Two feeds of one shop (Coolblue BE through two accounts) are
            // one name in a sentence.
            $name = $merchant->displayName();

            if (isset($seen[mb_strtolower($name)])) {
                continue;
            }

            $seen[mb_strtolower($name)] = true;
            $links[] = ['label' => $name, 'url' => $url, 'rel' => 'sponsored nofollow'];

            if (count($links) === self::LIMIT) {
                break;
            }
        }

        return Value::links($links);
    }
}

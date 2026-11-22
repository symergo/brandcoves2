<?php

declare(strict_types=1);

namespace App\Services\Cove;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Models\BrandStat;
use App\Models\DailyPickSet;
use App\Models\GiftLanding;
use App\Models\ProductGroup;
use App\Models\Wishlist;
use Illuminate\Support\Facades\Cache;

/**
 * Which entries of the Discover menu have something behind them in a market (2026-11-22).
 *
 * The menu listed the same six pages in every market. Spain, opened to visitors that day with only
 * eBay behind it, had no daily edition, guides, gift ideas, community lists or brands, so most entries
 * led to an empty page (owner: "hide the discover sections on page and menu if there is no content").
 * Each answer asks the same thing its page lists, as one EXISTS query; the Discover page itself
 * already hid its empty bands.
 *
 * Kept ten minutes per market, like the Discover page's bands: something published shows up in the
 * menu within that, and a page view costs one cache read.
 */
final class DiscoverMenu
{
    private const TTL = 600;

    /** @return array{daily: bool, surprise: bool, giftIdeas: bool, guides: bool, community: bool, brands: bool, coves: bool} */
    public function for(Market $market): array
    {
        return Cache::remember('bc:discover-menu:'.$market->value, self::TTL, function () use ($market): array {
            $found = [
                'daily' => DailyPickSet::query()->forMarket($market)->daily()->published()->exists(),
                'surprise' => ProductGroup::query()->forMarket($market)->presentable()->where('surprise_score', '>', 0)->exists(),
                'giftIdeas' => DailyPickSet::query()->forMarket($market)
                    ->whereIn('kind', [CoveKind::Persona->value, CoveKind::Occasion->value])->published()->exists()
                    || GiftLanding::query()->forMarket($market)->exists(),
                'guides' => DailyPickSet::query()->forMarket($market)->articles()->published()->exists(),
                'community' => Wishlist::query()->communityCoves()->where('wishlists.market', $market->value)->exists(),
                'brands' => BrandStat::query()->forMarket($market)->exists(),
            ];

            // "All Coves" shows every kind above in bands: worth a link when any has something
            return $found + ['coves' => in_array(true, $found, true)];
        });
    }
}

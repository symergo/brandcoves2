<?php

declare(strict_types=1);

namespace App\Services\Cove;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Models\BrandStat;
use App\Models\DailyPickSet;
use App\Models\Merchant;
use App\Services\Shops\ShopDirectory;
use Illuminate\Support\Facades\Cache;

/**
 * The link list of a shop or brand Cove: the categories its prose may link to.
 *
 * `EntityRails::vocabularyForShop()` and `vocabularyForBrand()` answer the
 * question (what this entity sells most, most first). This class decides
 * **when** it is asked, because the answer is expensive: it groups every active
 * offer of the shop or brand by category. For bol.com on production that was
 * 4.4 s, and the shop page asked it on every view.
 *
 * ## Worked out at build, read at render
 *
 * `EditionBuilder::buildArticle()` calls `compute()` and stores the list in
 * `daily_pick_sets.link_categories`. The page reads the stored list through
 * `forShopCove()` / `forBrandCove()` and never runs the query.
 *
 * Freezing it at build is also the more honest answer, not only the faster
 * one: the prose was written against the list as it stood then, so the links
 * the writer was promised are the links the reader gets. A redo or a rebuild
 * works it out again.
 *
 * ## Coves built before the column
 *
 * Their `link_categories` is null. The page then computes the list once and
 * caches it for a day per market and shop or brand, so the first visitor of
 * the day pays and nobody else does. Rebuilding the Cove removes the fallback
 * for good.
 */
final readonly class EntityLinks
{
    /** A day: a shop's range moves over weeks, and this is only the fallback. */
    private const FALLBACK_TTL = 86400;

    public function __construct(
        private EntityRails $rails,
        private ShopDirectory $shops,
    ) {}

    /**
     * The link list, live, for the entity a plan or Cove names.
     *
     * Empty for a slug naming no shop this market compares or no brand it
     * carries, and for a kind that is not an entity. That is the honest answer
     * rather than a failure: there is nothing to link to.
     *
     * @return list<string>
     */
    public function compute(CoveKind $kind, Market $market, string $slug): array
    {
        if ($kind === CoveKind::Shop) {
            $shop = $this->shops->shopFor($market, $slug);

            return $shop === null ? [] : $this->rails->vocabularyForShop($shop, $market);
        }

        if ($kind === CoveKind::Brand) {
            $brand = BrandStat::query()->forMarket($market)->where('slug', $slug)->first();

            return $brand === null ? [] : $this->rails->vocabularyForBrand($brand, $market);
        }

        return [];
    }

    /**
     * A shop Cove's link list: stored, or computed and cached for a day.
     *
     * Takes the shop the caller already resolved, so the page does not look it
     * up a second time. Null when this market no longer compares the shop.
     *
     * @return list<string>
     */
    public function forShopCove(DailyPickSet $cove, ?Merchant $shop): array
    {
        return $this->stored($cove) ?? Cache::remember(
            'bc:entity-links:'.$cove->market->value.':shop:'.$cove->slug,
            self::FALLBACK_TTL,
            fn (): array => $shop === null ? [] : $this->rails->vocabularyForShop($shop, $cove->market),
        );
    }

    /**
     * A brand Cove's link list: stored, or computed and cached for a day.
     *
     * @return list<string>
     */
    public function forBrandCove(DailyPickSet $cove, BrandStat $brand): array
    {
        return $this->stored($cove) ?? Cache::remember(
            'bc:entity-links:'.$cove->market->value.':brand:'.$brand->slug,
            self::FALLBACK_TTL,
            fn (): array => $this->rails->vocabularyForBrand($brand, $cove->market),
        );
    }

    /** @return list<string>|null */
    private function stored(DailyPickSet $cove): ?array
    {
        $list = $cove->link_categories;

        return is_array($list) ? array_values(array_map('strval', $list)) : null;
    }
}

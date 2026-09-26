<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Enums\Market;
use App\Models\DailyPickSet;
use App\Models\ProductGroup;
use App\Support\CurrentMarket;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What the product page says about a product beyond its shops: how many people
 * keep it, which Coves hold it, and where to go next.
 *
 * Roadmap step 3 (docs/strategy.md, section 4): a product page is a meeting
 * point of shops, people and Coves, not only a click-out to a shop. See
 * docs/features/product-signals.md.
 *
 * Cached per product and market for an hour: the counts move slowly, and the
 * product page is the most-crawled template on the site.
 */
class ProductSignals
{
    /** Seconds one answer is kept. */
    private const TTL = 3600;

    /** Products in the "often on the same lists" band. */
    private const ALSO_ON = 4;

    /** Coves named on the page; the rest are counted. */
    private const COVES_NAMED = 3;

    /**
     * Price bands for "Gifts under €…", in euros. The smallest one the product
     * fits under is offered; above the last one there is no band, because
     * "under €500" is not a gift budget anybody searches by.
     */
    private const BANDS = [25, 50, 100, 200];

    /**
     * Different people, not lists: a signed-in owner or an anonymous one.
     * One person with three lists holding a product is one.
     */
    private const PEOPLE = "count(DISTINCT COALESCE('u' || wishlists.owner_user_id::text, 'a' || wishlists.owner_anon_id::text))";

    /**
     * @return array{
     *     savedBy: int|null,
     *     coveCount: int,
     *     coves: list<array{title: string, url: string}>,
     *     band: array{euros: int, url: string}|null,
     *     alsoOn: list<array{id: int, title: string, image: string|null, price: int|null, url: string}>
     * }
     */
    public function for(ProductGroup $group, CurrentMarket $current): array
    {
        return Cache::remember(
            "product-signals:{$group->id}:{$current->get()->value}",
            self::TTL,
            fn (): array => [
                'savedBy' => $this->savedBy($group),
                ...$this->coves($group, $current),
                'band' => $this->band($group, $current),
                'alsoOn' => $this->alsoOn($group),
            ],
        );
    }

    /**
     * The same two counts for a whole page of search results, in two queries.
     *
     * One row per product that has something to say; a product with neither
     * enough people nor a Cove is absent. Read by SearchController for the
     * small line under each card (docs/features/search.md, "What other people
     * keep").
     *
     * The threshold here is `list_signals.min_owners`, the one the owner named
     * for search on 2026-09-26, not this class's own `saved_threshold`: see
     * {@see self::searchThreshold()}. Claims are never read (invariant 4) and
     * an unaccepted suggestion is not a save, exactly as on the product page.
     *
     * @param  list<int>  $groupIds
     * @return array<int, array{savedBy: int|null, coveCount: int}>
     */
    public function forResults(array $groupIds, Market $market): array
    {
        if ($groupIds === []) {
            return [];
        }

        $people = DB::table('wishlist_items')
            ->join('wishlists', 'wishlists.id', '=', 'wishlist_items.wishlist_id')
            ->whereIn('wishlist_items.group_id', $groupIds)
            ->whereNotNull('wishlist_items.accepted_at')
            ->groupBy('wishlist_items.group_id')
            ->havingRaw(self::PEOPLE.' >= ?', [self::searchThreshold()])
            ->selectRaw('wishlist_items.group_id, '.self::PEOPLE.' AS people')
            ->pluck('people', 'group_id');

        $coves = DB::table('daily_picks')
            ->whereIn('daily_picks.group_id', $groupIds)
            ->whereIn('daily_picks.set_id', DailyPickSet::query()->forMarket($market)->published()->select('id'))
            ->groupBy('daily_picks.group_id')
            ->selectRaw('daily_picks.group_id, count(DISTINCT daily_picks.set_id) AS coves')
            ->pluck('coves', 'group_id');

        $out = [];

        foreach ($groupIds as $id) {
            $savedBy = isset($people[$id]) ? (int) $people[$id] : null;
            $coveCount = (int) ($coves[$id] ?? 0);

            if ($savedBy !== null || $coveCount > 0) {
                $out[$id] = ['savedBy' => $savedBy, 'coveCount' => $coveCount];
            }
        }

        return $out;
    }

    /**
     * How many different people must keep something before the search page
     * may say so, per product or summed over a search term.
     *
     * `list_signals.min_owners`: the setting crowd tags, product links and
     * crowd picks use, and the one the owner named for the search page. It is
     * 5 unless GIFT_MIN_OWNERS lowers it (1 on production since 2026-09-26,
     * while there are too few lists for anything to reach five). Never below
     * one: "kept by 0 people" is not a count worth printing.
     *
     * The product page's "Saved by N" still reads `saved_threshold` (5); the
     * two differ while GIFT_MIN_OWNERS is set. Recorded in
     * docs/features/search.md so the owner can decide whether they should be one.
     */
    public static function searchThreshold(): int
    {
        return max(1, (int) config('giftcoves.list_signals.min_owners', 5));
    }

    /**
     * How many different people keep this product on a list.
     *
     * People, not lists: one person with three lists holding it is one. An
     * anonymous visitor's list counts, because it is a real person's list.
     * A suggestion nobody has accepted yet does not count, and nothing about
     * claims is read (invariant 4): being bought is not being wanted.
     *
     * Null below the threshold. "Saved by 1 person" on a product somebody's
     * friends know they keep a list of is a way of pointing at that list.
     */
    private function savedBy(ProductGroup $group): ?int
    {
        $count = (int) DB::table('wishlist_items')
            ->join('wishlists', 'wishlists.id', '=', 'wishlist_items.wishlist_id')
            ->where('wishlist_items.group_id', $group->id)
            ->whereNotNull('wishlist_items.accepted_at')
            ->selectRaw("count(DISTINCT COALESCE('u' || wishlists.owner_user_id::text, 'a' || wishlists.owner_anon_id::text)) AS people")
            ->value('people');

        return $count >= (int) config('giftcoves.product_signals.saved_threshold', 5) ? $count : null;
    }

    /**
     * The published Coves in this market that pick this product, newest first.
     *
     * Editorial Coves only, for now. Public lists join the count when they
     * exist (roadmap step 6).
     *
     * @return array{coveCount: int, coves: list<array{title: string, url: string}>}
     */
    private function coves(ProductGroup $group, CurrentMarket $current): array
    {
        $query = DailyPickSet::query()
            ->forMarket($current->get())
            ->published()
            ->whereHas('picks', fn ($q) => $q->where('group_id', $group->id));

        $named = (clone $query)
            ->orderByDesc('published_at')
            ->limit(self::COVES_NAMED)
            ->get(['id', 'kind', 'slug', 'theme_title'])
            ->filter(fn (DailyPickSet $cove) => filled($cove->slug))
            ->map(fn (DailyPickSet $cove): array => [
                'title' => (string) $cove->theme_title,
                'url' => $current->url($cove->kind->path((string) $cove->slug, $current->get())),
            ])
            ->values()
            ->all();

        return ['coveCount' => $query->count(), 'coves' => $named];
    }

    /**
     * Products people keep on the same lists as this one, most people first.
     *
     * From `product_links`, which CountListSignals rebuilds nightly from
     * distinct people and only above a threshold, within one market. Only
     * what can be shown: in stock, priced, with a picture.
     *
     * @return list<array{id: int, title: string, image: string|null, price: int|null, url: string}>
     */
    private function alsoOn(ProductGroup $group): array
    {
        $ids = DB::table('product_links')
            ->where(fn ($q) => $q->where('group_a', $group->id)->orWhere('group_b', $group->id))
            ->orderByDesc('owners')
            ->limit(self::ALSO_ON * 2)
            ->get(['group_a', 'group_b'])
            ->map(fn ($link) => (int) ((int) $link->group_a === $group->id ? $link->group_b : $link->group_a))
            ->all();

        if ($ids === []) {
            return [];
        }

        $groups = ProductGroup::query()->whereIn('id', $ids)->presentable()->get()->keyBy('id');

        return collect($ids)
            ->map(fn (int $id) => $groups->get($id))
            ->filter()
            ->take(self::ALSO_ON)
            ->map(fn (ProductGroup $g): array => [
                'id' => $g->id,
                'title' => $g->displayTitle(),
                'image' => $g->image_url,
                'price' => $g->min_price,
                'url' => $g->path(),
            ])
            ->values()
            ->all();
    }

    /** @return array{euros: int, url: string}|null */
    private function band(ProductGroup $group, CurrentMarket $current): ?array
    {
        if ($group->min_price === null) {
            return null;
        }

        foreach (self::BANDS as $euros) {
            if ($group->min_price <= $euros * 100) {
                return ['euros' => $euros, 'url' => $current->url('search').'?max='.$euros];
            }
        }

        return null;
    }
}

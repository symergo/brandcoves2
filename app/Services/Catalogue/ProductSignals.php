<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

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

    /** Coves named on the page; the rest are counted. */
    private const COVES_NAMED = 3;

    /**
     * Price bands for "Gifts under €…", in euros. The smallest one the product
     * fits under is offered; above the last one there is no band, because
     * "under €500" is not a gift budget anybody searches by.
     */
    private const BANDS = [25, 50, 100, 200];

    /**
     * @return array{
     *     savedBy: int|null,
     *     coveCount: int,
     *     coves: list<array{title: string, url: string}>,
     *     band: array{euros: int, url: string}|null
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
            ],
        );
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

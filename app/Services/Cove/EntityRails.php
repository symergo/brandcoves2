<?php

declare(strict_types=1);

namespace App\Services\Cove;

use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Models\BrandStat;
use App\Models\Merchant;
use App\Models\PopularRank;
use App\Models\ProductGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The product rails under an entity Cove — a shop's, or a brand's.
 *
 * An entity Cove carries no shortlist: its prose is about sub-brands and
 * categories rather than about individual products, so there is nothing frozen
 * at build time that can go stale. The products come from here instead, live at
 * render and cached.
 *
 * That split is the whole design. A frozen "biggest discounts" list is wrong
 * within days, and a live one cannot be named in prose written last month —
 * so the prose talks about ranges, which do not move, and the rails talk about
 * products, which do.
 *
 * ## Three rails, three different claims
 *
 * | Rail | Ordered by | What it claims |
 * |---|---|---|
 * | `discounts` | `discount_percent` off our own stored prices | first-party, and a reader can check it |
 * | `popular` | `PopularRank` | a retailer's chart |
 * | `wishlisted` | distinct wishlists holding it | what **our** visitors want |
 *
 * The third is the honest one, and it is the only one that is genuinely about
 * this site's own audience rather than about somebody else's sales.
 *
 * ## The wishlist rail is aggregate, and stays that way
 *
 * Four rules, and the threshold is the one that matters:
 *
 * - **Distinct wishlists are counted, never people**, and whose is never exposed.
 * - **Three lists minimum.** With one, a shared list and a brand page together
 *   identify an individual's list. A threshold is what makes the count
 *   anonymous rather than a lookup with extra steps.
 * - **Computed live and cached, never stored in a snapshot table.** A list its
 *   owner deletes, or one reaped by `bc:prune-personal-data`, then leaves the
 *   rail at the next cache expiry rather than persisting in an aggregate nobody
 *   thinks to prune.
 * - **Invariant 4 is untouched.** Nothing here reads `claimed_by_hash` or says
 *   anything about whether an item was bought. It is membership, not claim
 *   state, and the two are not the same question.
 *
 * Private lists **do** count toward the threshold-gated total. The output
 * carries no identity, and excluding them would weaken the signal without
 * making it safer. That is a judgement call rather than an obvious one — see
 * docs/features/cove-entities.md.
 */
final readonly class EntityRails
{
    /**
     * How many distinct wishlists a product needs before it can appear.
     *
     * Three, not one. The number is the anonymity: below it the rail becomes a
     * way of asking whether one particular person wants one particular thing.
     */
    public const WISHLIST_FLOOR = 3;

    /** Long enough to be worth caching, short enough that a deleted list leaves. */
    private const TTL = 900;

    private const PER_RAIL = 8;

    /**
     * Every rail for a brand, in the order they are read.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function forBrand(BrandStat $brand, Market $market): array
    {
        /*
         * A brand's identity is its slug plus its spellings.
         *
         * Feeds disagree about punctuation — "Audio-Technica" and "Audio
         * Technica" are one brand — so the aliases are the filter, and the
         * folding was done in PHP when `brand_stats` was written because
         * Postgres cannot reproduce `Str::slug()`: it transliterates where
         * `lower(replace(...))` does not.
         */
        $spellings = array_values(array_unique([$brand->brand, ...(array) ($brand->aliases ?? [])]));

        return $this->rails(
            'brand:'.$market->value.':'.$brand->slug,
            $market,
            fn (Builder $q) => $q->whereIn('brand', $spellings),
        );
    }

    /**
     * Every rail for a shop.
     *
     * Scoped through `products.merchant_id`, which is indexed — the same
     * `EXISTS` the shops directory uses to decide membership.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function forShop(Merchant $shop, Market $market): array
    {
        return $this->rails(
            'shop:'.$market->value.':'.$shop->id,
            $market,
            fn (Builder $q) => $q->whereHas('offers', fn (Builder $p) => $p
                ->where('market', $market->value)
                ->where('merchant_id', $shop->id)
                ->where('status', ProductStatus::Active->value)),
        );
    }

    /**
     * @param  callable(Builder): Builder  $scope
     * @return array<string, list<array<string, mixed>>>
     */
    private function rails(string $key, Market $market, callable $scope): array
    {
        return Cache::remember("bc:rails:{$key}", self::TTL, fn (): array => [
            'discounts' => $this->discounts($market, $scope),
            'popular' => $this->popular($market, $scope),
            'wishlisted' => $this->wishlisted($market, $scope),
        ]);
    }

    /**
     * Biggest price drops.
     *
     * The first-party rail: computed from prices this site stored itself, so the
     * claim on the label is one a reader can check against the card underneath.
     *
     * @param  callable(Builder): Builder  $scope
     * @return list<array<string, mixed>>
     */
    private function discounts(Market $market, callable $scope): array
    {
        /*
         * The same rule as `ProductGroup::discountPercent()`, in SQL.
         *
         * There is no `discount_percent` column: a discount is measured against
         * the **30-day median** rather than a merchant-supplied "was" price,
         * which is frequently fiction. Ordering needs it in the database, and
         * the floor is repeated rather than approximated — a saving that floors
         * to zero is not a saving, and a rail that showed one would be claiming
         * nothing while looking exactly like a rail claiming something.
         */
        $drop = '(product_groups.median_price - product_groups.min_price)::numeric / product_groups.median_price';

        return $this->present(
            $this->base($market, $scope)
                ->whereNotNull('median_price')
                ->where('median_price', '>', 0)
                ->whereColumn('min_price', '<', 'median_price')
                ->whereRaw("floor({$drop} * 100) > 0")
                ->orderByRaw("{$drop} desc")
                ->limit(self::PER_RAIL)
                ->get()
        );
    }

    /**
     * What is selling, by the charts.
     *
     * **The rank may be the label here**, which narrows the rule in
     * `docs/features/popularity-charts.md` — "the rank shapes the shelf, it is
     * never the label". That was written when a chart only ordered an internal
     * shelf. On an entity page it is the point of the rail, and the doc records
     * the exception rather than being left to contradict this code.
     *
     * @param  callable(Builder): Builder  $scope
     * @return list<array<string, mixed>>
     */
    private function popular(Market $market, callable $scope): array
    {
        /*
         * Joined rather than related, because `ProductGroup` has no
         * `popularRanks` relation and adding one to the model for a single rail
         * would put the chart on every group everybody loads.
         *
         * `captured_on` is not filtered: a product that charted last week is
         * still what was selling, and requiring today's capture would empty the
         * rail on any market whose pull has not run.
         */
        $best = PopularRank::query()
            ->selectRaw('group_id, min(rank) as best_rank')
            ->where('market', $market->value)
            ->whereNotNull('group_id')
            ->groupBy('group_id');

        return $this->present(
            $this->base($market, $scope)
                ->joinSub($best, 'ranked', 'ranked.group_id', '=', 'product_groups.id')
                ->orderBy('ranked.best_rank')
                ->limit(self::PER_RAIL)
                ->select('product_groups.*')
                ->get()
        );
    }

    /**
     * What our own visitors have put on a list.
     *
     * The honest rail, and the only one about this site's audience rather than
     * somebody else's sales. Threshold-gated to `WISHLIST_FLOOR` distinct
     * lists — see the class note for why that number is the anonymity rather
     * than a tuning parameter.
     *
     * @param  callable(Builder): Builder  $scope
     * @return list<array<string, mixed>>
     */
    private function wishlisted(Market $market, callable $scope): array
    {
        /*
         * `count(distinct wishlist_id)`, written out rather than via
         * `withCount(...->distinct())` — that builds
         * `select distinct on (wishlist_id) count(*)`, which Postgres rejects
         * outright, and the shape of the error says nothing about what was
         * meant.
         *
         * Distinct **lists**, never rows and never people. One person with four
         * lists is not four people wanting a thing, and counting rows would let
         * a single enthusiastic list clear the floor on its own — which is
         * exactly the case the floor exists to exclude.
         */
        $lists = '(select count(distinct wishlist_id) from wishlist_items'
            .' where wishlist_items.group_id = product_groups.id)';

        return $this->present(
            $this->base($market, $scope)
                ->whereRaw("{$lists} >= ?", [self::WISHLIST_FLOOR])
                ->orderByRaw("{$lists} desc")
                ->limit(self::PER_RAIL)
                ->get()
        );
    }

    /**
     * Products that can be shown at all.
     *
     * In stock, priced and pictured — the same gate every other surface uses. A
     * rail is a shelf, and a card with no image on it reads as broken however
     * good the product is.
     *
     * @param  callable(Builder): Builder  $scope
     * @return Builder<ProductGroup>
     */
    private function base(Market $market, callable $scope): Builder
    {
        // `presentable()` is the gate every other shelf uses. A second copy of
        // "in stock, priced and pictured" is a second thing to keep in step.
        $query = ProductGroup::query()
            ->where('market', $market->value)
            ->presentable();

        return $scope($query);
    }

    /**
     * @param  Collection<int, ProductGroup>  $groups
     * @return list<array<string, mixed>>
     */
    private function present(Collection $groups): array
    {
        return $groups->map(fn (ProductGroup $g) => [
            'id' => $g->id,
            'title' => $g->title,
            'brand' => $g->brand,
            'slug' => $g->slug,
            'imageUrl' => $g->image_url,
            // Cents, like everywhere else. A formatted string here would be one
            // more place the currency and the rounding could disagree.
            'minPriceCents' => $g->min_price,
            'discountPercent' => $g->discountPercent(),
            'merchantCount' => $g->merchant_count,
        ])->values()->all();
    }
}

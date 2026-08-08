<?php

declare(strict_types=1);

namespace App\Services\Discover\Retrievers;

use App\Enums\Source;
use App\Models\ProductGroup;
use App\Services\Discover\Candidate;
use App\Services\Discover\DiscoveryRequest;
use Illuminate\Support\Facades\DB;

/**
 * Genuinely good prices — measured against our own history, never a merchant's word.
 *
 * ## Why the merchant's "was" price is never used
 *
 * A large share of feed rows carry a reference price that was never charged.
 * Ranking on it produces a page of 60%-off badges that are all fiction, and a
 * deals surface whose discounts are fiction is worth less than no deals surface
 * — it teaches people not to trust the numbers anywhere else on the site.
 *
 * So value is measured two ways, both ours:
 *
 * 1. **Against the 30-day median** we recorded. A real drop shows up here and a
 *    permanent "discount" does not, because the median moved with it.
 * 2. **Against the same product at another shop right now.** A cross-merchant
 *    gap is the one claim that needs no history at all and cannot be faked by
 *    one merchant — the other shop's price is the evidence.
 *
 * COMPLIANCE: sources that disallow price tracking are excluded from the
 * history side. Their prices are stored, but a user-facing feature built on
 * retained pricing may not include them. See docs/features/amazon-compliance.md.
 */
class ValueRetriever implements Retriever
{
    /** Below this a "saving" is noise — rounding, VAT drift, a shipping tweak. */
    private const MIN_DISCOUNT_PERCENT = 8;

    public function key(): string
    {
        return 'value';
    }

    public function isAvailable(DiscoveryRequest $request): bool
    {
        return true;
    }

    public function retrieve(DiscoveryRequest $request, int $take): array
    {
        $trackable = array_values(array_filter(
            Source::values(),
            fn (string $s) => Source::from($s)->allowsPriceTracking(),
        ));

        $groups = ProductGroup::query()
            ->forMarket($request->market)
            ->presentable()
            ->when(
                $request->excludeGroupIds !== [],
                fn ($q) => $q->whereNotIn('id', $request->excludeGroupIds),
            )
            ->when($request->budgetMax !== null, fn ($q) => $q->where('min_price', '<=', $request->budgetMax))
            ->when($request->budgetMin !== null, fn ($q) => $q->where('min_price', '>=', $request->budgetMin))
            ->where(function ($q): void {
                // Either lane qualifies: below our own median, or cheaper here
                // than at another shop carrying the same product.
                $q->where(function ($sub): void {
                    $sub->whereNotNull('median_price')
                        ->whereColumn('min_price', '<', 'median_price');
                })->orWhere(function ($sub): void {
                    $sub->where('merchant_count', '>', 1)
                        ->whereColumn('max_price', '>', 'min_price');
                });
            })
            ->orderByRaw('
                GREATEST(
                    COALESCE((median_price - min_price)::float / NULLIF(median_price, 0), 0),
                    COALESCE((max_price - min_price)::float / NULLIF(max_price, 0), 0)
                ) DESC
            ')
            ->limit($take * 3)
            ->get();

        $lows = $this->historicLows($groups->pluck('id')->all(), $trackable);

        return $groups
            ->map(function (ProductGroup $group) use ($lows) {
                $value = $this->value($group);

                return (new Candidate($group))->withSignals([
                    // Value rides on `relevance`, which reads oddly until you
                    // see why: the Deals profile is "how well does this answer
                    // *the question I am asking*", and the question is "is this
                    // a good price". One objective, no extra term.
                    'relevance' => $value,
                    'quality' => $group->merchant_count > 1 ? 1.0 : 0.75,
                    // At or near its lowest price since we started watching is
                    // a fact worth surfacing, and it is a fact we own.
                    'novelty' => isset($lows[$group->id]) && $group->min_price !== null
                        && $group->min_price <= $lows[$group->id] * 1.02 ? 1.0 : 0.3,
                    'unexpectedness' => min(1.0, ((float) ($group->surprise_score ?? 30)) / 100),
                ], $this->key());
            })
            ->filter(fn (Candidate $c) => $c->signal('relevance') > 0)
            ->take($take)
            ->values()
            ->all();
    }

    /**
     * The better of the two measurable savings, 0..1.
     *
     * Whichever is larger, because they are two views of the same question and
     * a product can qualify on either. Sub-threshold savings score zero rather
     * than a small number — a 3% "deal" on a deals page is a broken promise,
     * not a weak result.
     */
    private function value(ProductGroup $group): float
    {
        $againstMedian = 0.0;

        if ($group->median_price !== null && $group->median_price > 0 && $group->min_price !== null) {
            $againstMedian = max(0.0, ($group->median_price - $group->min_price) / $group->median_price);
        }

        $againstOtherShops = 0.0;

        if ($group->max_price !== null && $group->max_price > 0 && $group->min_price !== null) {
            $againstOtherShops = max(0.0, ($group->max_price - $group->min_price) / $group->max_price);
        }

        $best = max($againstMedian, $againstOtherShops);

        if ($best * 100 < self::MIN_DISCOUNT_PERCENT) {
            return 0.0;
        }

        // Capped at 60%: beyond that the "before" price is almost always the
        // fiction this class exists to avoid, even in our own history — a
        // mis-ingested price becomes a median.
        return min(1.0, $best / 0.6);
    }

    /**
     * Lowest recorded price per group, trackable sources only.
     *
     * @param  list<int>  $groupIds
     * @param  list<string>  $trackable
     * @return array<int, int>
     */
    private function historicLows(array $groupIds, array $trackable): array
    {
        if ($groupIds === []) {
            return [];
        }

        return collect(DB::table('price_history as h')
            ->join('products as p', 'p.id', '=', 'h.product_id')
            ->whereIn('p.group_id', $groupIds)
            ->whereIn('p.source', $trackable)
            ->where('h.captured_on', '>=', now()->subDays(180)->toDateString())
            ->groupBy('p.group_id')
            ->select('p.group_id', DB::raw('min(h.price) as low'))
            ->get())
            ->mapWithKeys(fn ($row) => [(int) $row->group_id => (int) $row->low])
            ->all();
    }
}

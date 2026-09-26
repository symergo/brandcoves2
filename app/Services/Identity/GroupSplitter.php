<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\MatchRule;
use App\Enums\MatchStatus;
use App\Models\IdentityOverride;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\User;
use App\Services\Ingestion\ProductGrouper;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Take offers out of a product that they do not belong to.
 *
 * The reverse of GroupMerger, and it lives in identity for the same reason: the
 * grouper re-derives every offer's product from its key twice a day, so moving
 * `group_id` alone would put the offers straight back. Each chosen offer gets
 * an override, `split:{first chosen offer id}`, which beats its own key and any
 * alias on that key. All the chosen offers share it, so a split makes exactly
 * one new product, however many offers go into it.
 *
 * The new product is created and linked here, in the request, rather than by
 * running the grouper for the market: that pass is set-based over the whole
 * market and takes minutes on production, and the person who clicked "Split"
 * should see the result on the next page load.
 *
 * The two products are recorded as a rejected pair, so the match rules never
 * propose putting back together what a person just took apart.
 */
final class GroupSplitter
{
    public function __construct(private readonly ProductGrouper $grouper) {}

    /**
     * @param  list<int>  $offerIds  offers of `$group` to move into a new product
     * @return ProductGroup the new product
     */
    public function split(ProductGroup $group, array $offerIds, ?User $by = null, ?string $reason = null): ProductGroup
    {
        $offers = Product::query()
            ->where('group_id', $group->id)
            ->whereIn('id', $offerIds)
            ->orderBy('id')
            ->get();

        if ($offers->isEmpty()) {
            throw new InvalidArgumentException('Choose at least one offer of this product to split off.');
        }

        if ($offers->count() === $group->offers()->count()) {
            throw new InvalidArgumentException('Splitting off every offer would leave an empty product. Leave at least one behind.');
        }

        return DB::transaction(function () use ($group, $offers, $by, $reason): ProductGroup {
            $key = 'split:'.$offers->first()->id;

            foreach ($offers as $offer) {
                IdentityOverride::query()->updateOrCreate(
                    ['product_id' => $offer->id],
                    ['forced_key' => $key, 'reason' => $reason, 'created_by' => $by?->id],
                );
            }

            // The display fields are seeded from the best of the moved offers
            // and refreshed by recomputeGroups() below. `title` kind: the key
            // is not a barcode, and an EAN-kind key is printed as one.
            $seed = $offers->sortBy(fn (Product $p) => [$p->image_url === null ? 1 : 0, $p->price ?? PHP_INT_MAX])->first();

            /** @var ProductGroup $new */
            $new = ProductGroup::query()->firstOrCreate(
                ['market' => $group->market->value, 'identity_key' => $key],
                [
                    'identity_kind' => 'title',
                    'title' => $seed->title,
                    'slug' => $this->slug($seed->title),
                    'brand' => $seed->brand,
                    'image_url' => $seed->image_url,
                    'category' => $seed->merchant_category,
                    'first_seen_at' => now(),
                ],
            );

            Product::query()->whereIn('id', $offers->pluck('id'))->update(['group_id' => $new->id]);

            DB::table('match_candidates')->upsert([[
                'market' => $group->market->value,
                'group_a' => min($group->id, $new->id),
                'group_b' => max($group->id, $new->id),
                'rule' => MatchRule::Manual->value,
                'score' => 0,
                'status' => MatchStatus::Rejected->value,
                'decided_by' => $by?->id,
                'decided_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]], ['group_a', 'group_b'], ['status', 'decided_by', 'decided_at', 'updated_at']);

            $this->grouper->recomputeGroups($group->market, [$group->id, $new->id]);

            return $new->refresh();
        });
    }

    /** The same slug the grouper writes, so a split product's URL looks like any other. */
    private function slug(string $title): string
    {
        $row = DB::selectOne("SELECT left(regexp_replace(lower(unaccent(?)), '[^a-z0-9]+', '-', 'g'), 80) AS slug", [$title]);

        return (string) $row->slug;
    }
}

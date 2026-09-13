<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Models\ProductGroup;
use Illuminate\Support\Facades\DB;

/**
 * The products a visitor meets on an editorial surface that still carry the
 * feed's title, or no gift tags yet.
 *
 * "Editorial surface" is where a title is read as a sentence rather than
 * scanned in a grid: a Cove's finds, a curated shortlist, a bestseller chart,
 * the Surprise pool. Those are the products worth a hand-written title, and
 * they are bounded — a few hundred per market — where the catalogue is three
 * hundred thousand. Search results are not here: a search shows what was
 * searched for, and the mechanical cleaner is good enough there.
 *
 * Four sources, each named on the row so the writer knows why a product is on
 * the list:
 *
 * - `daily`: a pick in a published or scheduled edition of any kind.
 * - `plan`: an item on a plan that is still open (draft or approved).
 * - `chart`: a product on a bestseller chart captured in the last fortnight.
 * - `surprise`: the top of the Surprise pool, the same query the sampler runs.
 *
 * Paged by id, because the writer works through the list in batches and posts
 * titles as they go: a page that moved under them would repeat products.
 */
class UntitledProducts
{
    /** How much of the Surprise pool counts as an editorial surface. The sampler's own pool size. */
    private const SURPRISE_POOL = 200;

    /** How far back a chart capture still counts as current. */
    private const CHART_DAYS = 14;

    public const MISSING_TITLE = 'title';

    public const MISSING_TAGS = 'tags';

    /**
     * @param  string  $missing  which editorial field is still empty: 'title' or 'tags'
     * @return list<array<string, mixed>>
     */
    public function list(Market $market, int $limit = 200, ?int $after = null, string $missing = self::MISSING_TITLE): array
    {
        $surfaces = $this->surfaces($market);

        if ($surfaces === []) {
            return [];
        }

        return ProductGroup::query()
            ->forMarket($market)
            ->whereIn('id', array_keys($surfaces))
            ->when($missing === self::MISSING_TAGS,
                fn ($q) => $q->whereRaw("gift_tags = '[]'::jsonb"),
                fn ($q) => $q->whereNull('display_title'),
            )
            ->when($after !== null, fn ($q) => $q->where('id', '>', $after))
            ->orderBy('id')
            ->limit(max(1, min($limit, 200)))
            ->get(['id', 'market', 'slug', 'title', 'display_title', 'gift_tags', 'brand', 'category', 'min_price', 'image_url'])
            ->map(fn (ProductGroup $group): array => [
                'id' => $group->id,
                'title' => $group->title,
                'displayTitle' => $group->displayTitle(),
                'tags' => $group->giftTags(),
                'brand' => $group->brand,
                'category' => $group->category,
                'minPriceCents' => $group->min_price,
                'imageUrl' => $group->image_url,
                'url' => $group->path(),
                'surfaces' => $surfaces[$group->id],
            ])
            ->all();
    }

    /**
     * Group id => the surfaces it sits on, in a fixed order.
     *
     * @return array<int, list<string>>
     */
    private function surfaces(Market $market): array
    {
        $found = [];

        $add = function (iterable $ids, string $surface) use (&$found): void {
            foreach ($ids as $id) {
                $found[(int) $id][] = $surface;
            }
        };

        $add(DB::table('daily_picks')
            ->join('daily_pick_sets', 'daily_pick_sets.id', '=', 'daily_picks.set_id')
            ->where('daily_pick_sets.market', $market->value)
            ->whereIn('daily_pick_sets.status', [PublishStatus::Published->value, PublishStatus::Scheduled->value])
            ->whereNotNull('daily_picks.group_id')
            ->distinct()
            ->pluck('daily_picks.group_id'), 'daily');

        $add(DB::table('cove_plan_items')
            ->join('cove_plans', 'cove_plans.id', '=', 'cove_plan_items.plan_id')
            ->where('cove_plans.market', $market->value)
            ->whereIn('cove_plans.status', ['draft', 'approved'])
            ->whereNotNull('cove_plan_items.group_id')
            ->distinct()
            ->pluck('cove_plan_items.group_id'), 'plan');

        $add(DB::table('popular_ranks')
            ->where('market', $market->value)
            ->whereNotNull('group_id')
            ->where('captured_on', '>=', now()->subDays(self::CHART_DAYS)->toDateString())
            ->distinct()
            ->pluck('group_id'), 'chart');

        $add(ProductGroup::query()
            ->forMarket($market)
            ->presentable()
            ->where('surprise_score', '>', 0)
            ->orderByDesc('surprise_score')
            ->limit(self::SURPRISE_POOL)
            ->pluck('id'), 'surprise');

        return $found;
    }
}

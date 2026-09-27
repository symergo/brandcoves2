<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Models\DailyPickSet;
use App\Models\PersonaTopList;
use App\Models\PopularRank;
use App\Models\ProductGroup;
use App\Services\Cove\EntityRails;
use App\Support\CurrentMarket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A persona's top 10 of the week, at the end of its page (owner, 2026-09-27).
 *
 * The pool is what the suggestion engine answers to the persona's brief, the
 * same pool as the budget tabs above it and filtered by the same fit check,
 * minus the products the curated shelf already shows. The order is what
 * people are buying and wanting:
 *
 * - a bestseller-chart position (a retailer's chart, rank 1 counts most);
 * - how many distinct wish lists on this site hold it, from
 *   `EntityRails::WISHLIST_FLOOR` lists up, below which the count would
 *   identify one person's list;
 * - and where neither says anything, the engine's own order.
 *
 * Worked out once a week (Monday) and stored, so the list holds for a week and
 * can say so. Only the ids are stored, never a count: the wish-list aggregate
 * must not outlive a list its owner deletes, and a week-old order of ids is
 * the most it keeps. No AI anywhere. See docs/features/persona-top-ten.md.
 */
class PersonaTopTen
{
    /** How many products the list shows. */
    public const SIZE = 10;

    /**
     * Fewer than this and there is no list: "top 10" over four products is
     * a claim the page cannot back.
     */
    public const MINIMUM = 6;

    /**
     * At most this many from one brand, so a persona about coffee is not ten
     * machines from whoever has the most listings.
     */
    private const PER_BRAND = 2;

    /** Candidates asked from the engine per price band. */
    private const POOL_PER_BAND = 20;

    /**
     * The price bands the ten places are spread over, in cents; the last has
     * no ceiling. Without them the engine's own order decided (few products
     * carry a chart or wish-list signal yet) and a cooking persona got ten
     * appliances between 277 and 479 euro (2026-09-27). Taken in turn, a
     * list holds something at every budget the page's tabs above it offer.
     *
     * @var list<array{0: int, 1: int|null}>
     */
    private const BANDS = [[0, 2499], [2500, 7499], [7500, 14999], [15000, null]];

    /**
     * One wish list is worth this many chart places. With it, four lists
     * (100) weigh as much as a number 1 on a retailer's chart: our own
     * visitors' wishes are the rarer signal and the more honest one.
     */
    private const POINTS_PER_LIST = 25;

    public function __construct(
        private readonly SuggestionEngine $engine,
        private readonly PersonaBudgets $budgets,
    ) {}

    /**
     * Work out this week's list for one persona and store it. Returns the ids,
     * or an empty list when the persona cannot fill one (nothing is stored).
     *
     * @return list<int>
     */
    public function refresh(DailyPickSet $persona, ?Carbon $week = null): array
    {
        $week = ($week ?? Carbon::now())->copy()->startOfWeek();
        $ids = $this->rank($persona);

        if (count($ids) < self::MINIMUM) {
            // A persona that falls under the minimum loses this week's list
            // rather than keeping a stale one under a "this week" heading.
            PersonaTopList::query()->where('set_id', $persona->id)->where('week', $week->toDateString())->delete();

            return [];
        }

        PersonaTopList::query()->updateOrCreate(
            ['set_id' => $persona->id, 'week' => $week->toDateString()],
            ['group_ids' => $ids],
        );

        return $ids;
    }

    /**
     * The list as the page shows it: the latest week's, products still
     * presentable, in order. Null when there is none.
     *
     * @return array{week: string, updated: string, items: list<array<string, mixed>>}|null
     */
    public function forPage(DailyPickSet $persona, CurrentMarket $current): ?array
    {
        $list = PersonaTopList::query()
            ->where('set_id', $persona->id)
            // Never an old list: two weeks without a refresh means the job
            // stopped, and a "this week" heading over it would be untrue.
            ->where('week', '>=', Carbon::now()->startOfWeek()->subWeek()->toDateString())
            ->latest('week')
            ->first();

        if ($list === null) {
            return null;
        }

        $groups = ProductGroup::query()
            ->forMarket($persona->market)
            ->presentable()
            ->whereIn('id', $list->group_ids ?: [0])
            ->get()
            ->keyBy('id');

        $items = [];

        foreach ($list->group_ids as $id) {
            $group = $groups->get((int) $id);

            // Gone out of stock since Monday: it drops out and the rest move
            // up, rather than a card that cannot be bought.
            if ($group === null) {
                continue;
            }

            $items[] = [
                'rank' => count($items) + 1,
                'groupId' => $group->id,
                'title' => $group->displayTitle(),
                'image' => $group->image_url,
                'price' => $group->min_price,
                'url' => $current->url("p/{$group->id}/{$group->slug}"),
            ];
        }

        return count($items) < self::MINIMUM
            ? null
            // `updated` is the day it was worked out, which the page shows:
            // a list made by hand on a Sunday belongs to the week that began
            // the Monday before, and "updated on Monday" would be untrue.
            : ['week' => $list->week->toDateString(), 'updated' => $list->updated_at->toDateString(), 'items' => $items];
    }

    /**
     * The ranked ids, best first.
     *
     * @return list<int>
     */
    public function rank(DailyPickSet $persona): array
    {
        $brief = $this->budgets->brief($persona, self::POOL_PER_BAND);

        if ($brief === null) {
            return [];
        }

        $persona->loadMissing('picks');
        $shown = $persona->picks->pluck('group_id')->filter()->map(fn ($id) => (int) $id)->values()->all();

        // What an interest tag on the product says about this persona.
        $wanted = array_map(fn (string $interest) => GiftTags::interest($interest), $brief->interests);

        /*
         * The pool, per price band: the engine asked once per band, so a
         * band the engine would never reach on its own order still has
         * candidates.
         */
        $candidates = [];
        $order = 0;

        foreach (self::BANDS as $band => [$min, $max]) {
            $asked = $brief->withBudget($min, $max)->withLimit(self::POOL_PER_BAND)->excluding($shown);

            foreach ($this->engine->suggest($asked) as $suggestion) {
                $group = $suggestion->group;

                if (isset($candidates[$group->id]) || ! PersonaBudgets::fits($brief, $suggestion)) {
                    continue;
                }

                // The engine may round a band's edge; the band is decided by
                // the price the page will show.
                if ($group->min_price === null || $group->min_price < $min || ($max !== null && $group->min_price > $max)) {
                    continue;
                }

                $candidates[$group->id] = [
                    'group' => $group,
                    'band' => $band,
                    'order' => $order++,
                    /*
                     * An editor's or the crowd's interest tag, rather than a
                     * word in the title. A title word is how a DJ controller
                     * "matched" cooking through "mixer"; a tag is somebody
                     * having looked at the product. Tagged candidates go
                     * first, and title-only ones fill in where too few are
                     * tagged (in nl-nl, most of them for now).
                     */
                    'tagged' => $wanted === [] || array_intersect($wanted, [...$group->giftTags(), ...$group->crowdTags()]) !== [],
                ];
            }
        }

        if ($candidates === []) {
            return [];
        }

        $ids = array_keys($candidates);
        $charts = $this->bestChartRanks($persona, $ids);
        $lists = $this->wishLists($ids);

        foreach ($candidates as $id => &$candidate) {
            $chart = isset($charts[$id]) ? max(0, 101 - $charts[$id]) : 0;
            $candidate['score'] = $chart + self::POINTS_PER_LIST * ($lists[$id] ?? 0);
        }
        unset($candidate);

        // Best first within each band: tagged, then the signals, then the
        // engine's own order.
        $key = fn (array $c) => [(int) ! $c['tagged'], -$c['score'], $c['order']];
        uasort($candidates, fn (array $a, array $b) => $key($a) <=> $key($b));

        $queues = array_fill(0, count(self::BANDS), []);

        foreach ($candidates as $id => $candidate) {
            $queues[$candidate['band']][] = $id;
        }

        /*
         * The bands in turn, one place each round, so the ten are spread over
         * every budget the catalogue can fill. A band that runs dry is skipped
         * and the others fill its places.
         */
        $picked = [];
        $brands = [];

        while (count($picked) < self::SIZE && array_filter($queues) !== []) {
            foreach ($queues as $band => &$queue) {
                while ($queue !== []) {
                    $id = array_shift($queue);
                    $brand = mb_strtolower((string) $candidates[$id]['group']->brand);

                    if ($brand !== '' && ($brands[$brand] ?? 0) >= self::PER_BRAND) {
                        continue;
                    }

                    $brands[$brand] = ($brands[$brand] ?? 0) + 1;
                    $picked[] = $id;

                    break;
                }

                if (count($picked) === self::SIZE) {
                    break;
                }
            }
            unset($queue);
        }

        // The places are chosen for spread; the numbering is by the same
        // order as within a band, so rank 1 is the strongest of the ten.
        usort($picked, fn (int $a, int $b) => $key($candidates[$a]) <=> $key($candidates[$b]));

        return array_map('intval', $picked);
    }

    /**
     * Each product's best position on any chart in this market.
     *
     * Not filtered on the capture date, like the popular rail on a brand page:
     * what charted last week is still what was selling.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function bestChartRanks(DailyPickSet $persona, array $ids): array
    {
        return PopularRank::query()
            ->where('market', $persona->market->value)
            ->whereIn('group_id', $ids)
            ->groupBy('group_id')
            ->selectRaw('group_id, min(rank) as best_rank')
            ->pluck('best_rank', 'group_id')
            ->map(fn ($rank) => (int) $rank)
            ->all();
    }

    /**
     * Distinct wish lists per product, only where at least the floor.
     *
     * The floor is applied in the query, as on the brand page's wish-list
     * rail: a product under it never leaves the database with its count.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function wishLists(array $ids): array
    {
        return DB::table('wishlist_items')
            ->whereIn('group_id', $ids)
            ->groupBy('group_id')
            ->havingRaw('count(distinct wishlist_id) >= ?', [EntityRails::WISHLIST_FLOOR])
            ->selectRaw('group_id, count(distinct wishlist_id) as list_count')
            ->pluck('list_count', 'group_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }
}

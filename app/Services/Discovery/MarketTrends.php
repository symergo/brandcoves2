<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Enums\Market;
use App\Enums\Source;
use App\Models\PopularRank;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What is moving in a market, read off the rank history.
 *
 * The instrument, not a surface. Two snapshots of a bestseller chart a week
 * apart say something no single snapshot can: a static chart is a list of what
 * sells, a moving one is a list of what is *starting* to sell — and the second is
 * the one worth writing a guide about or putting in front of someone.
 *
 * Pure: rank rows in, moves out. No AI, no network, no writes. That keeps it
 * usable from an admin page, a topic miner and a test alike.
 *
 * See docs/features/popularity-charts.md.
 */
class MarketTrends
{
    /** The default comparison window. A week smooths a chart's daily jitter. */
    public const WINDOW_DAYS = 7;

    /**
     * Products climbing a chart, biggest movers first.
     *
     * @return list<TrendMove>
     */
    public function risers(Market $market, int $windowDays = self::WINDOW_DAYS, int $limit = 25, ?Source $source = null): array
    {
        return $this->moves($market, $windowDays, $source)
            ->filter(fn (TrendMove $m) => ! $m->isNewEntry() && (int) $m->delta() > 0)
            ->sortByDesc(fn (TrendMove $m) => $m->magnitude())
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Products sliding down it.
     *
     * Worth as much as the risers and easier to forget: a category whose whole
     * top ten is falling is a category losing interest, and that is a reason not
     * to commission a guide about it.
     *
     * @return list<TrendMove>
     */
    public function fallers(Market $market, int $windowDays = self::WINDOW_DAYS, int $limit = 25, ?Source $source = null): array
    {
        return $this->moves($market, $windowDays, $source)
            ->filter(fn (TrendMove $m) => ! $m->isNewEntry() && (int) $m->delta() < 0)
            ->sortByDesc(fn (TrendMove $m) => $m->magnitude())
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Products that were not on the chart a week ago and are now.
     *
     * Ordered by current rank: arriving at #4 is a bigger event than arriving at
     * #94, and both are new.
     *
     * @return list<TrendMove>
     */
    public function newEntries(Market $market, int $windowDays = self::WINDOW_DAYS, int $limit = 25, ?Source $source = null): array
    {
        return $this->moves($market, $windowDays, $source)
            ->filter(fn (TrendMove $m) => $m->isNewEntry())
            ->sortBy(fn (TrendMove $m) => $m->rank)
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Categories with the most movement, hottest first.
     *
     * The aggregate a person actually reads: not "this kettle went up eleven
     * places" but "kettles are moving". Churn is the share of the chart that
     * changed, so a small category with three swaps ranks above a large one with
     * three, which is the right way round — the small one turned over.
     *
     * @return list<array{category_external_id: string, name: string|null, entries: int, moved: int, new: int, churn: float}>
     */
    public function activeCategories(Market $market, int $windowDays = self::WINDOW_DAYS, int $limit = 15, ?Source $source = null): array
    {
        $byCategory = [];

        foreach ($this->moves($market, $windowDays, $source) as $move) {
            $key = $move->categoryExternalId;

            $byCategory[$key] ??= [
                'category_external_id' => $key,
                'name' => $move->categoryName,
                'entries' => 0,
                'moved' => 0,
                'new' => 0,
                'churn' => 0.0,
            ];

            $byCategory[$key]['entries']++;

            if ($move->isNewEntry()) {
                $byCategory[$key]['new']++;
                $byCategory[$key]['moved']++;
            } elseif ($move->delta() !== 0) {
                $byCategory[$key]['moved']++;
            }
        }

        foreach ($byCategory as $key => $row) {
            $byCategory[$key]['churn'] = $row['entries'] === 0
                ? 0.0
                : round($row['moved'] / $row['entries'], 3);
        }

        $rows = array_values($byCategory);

        usort($rows, fn (array $a, array $b) => [$b['churn'], $b['new']] <=> [$a['churn'], $a['new']]);

        return array_slice($rows, 0, $limit);
    }

    /**
     * Every move on every chart in a market, between the latest snapshot and the
     * most recent one at least `$windowDays` old.
     *
     * Per chart, deliberately. A product's rank only means anything inside the
     * chart it was measured on — comparing its position in "Koptelefoons" against
     * its position in the market-wide list would invent a movement neither chart
     * recorded.
     *
     * @return Collection<int, TrendMove>
     */
    public function moves(Market $market, int $windowDays = self::WINDOW_DAYS, ?Source $source = null): Collection
    {
        $latest = PopularRank::latestCapturedOn($market, $source);

        if ($latest === null) {
            return collect();
        }

        $previous = $this->comparisonDate($market, $latest, $windowDays, $source);

        if ($previous === null) {
            // One snapshot is a list, not a trend. Returning "everything is a
            // new entry" would be technically true and completely misleading on
            // the day a new environment first pulls a chart.
            return collect();
        }

        $rows = DB::table('popular_ranks as now')
            ->leftJoin('popular_ranks as was', function ($join) use ($previous): void {
                $join->on('was.source', '=', 'now.source')
                    ->on('was.market', '=', 'now.market')
                    ->on('was.category_external_id', '=', 'now.category_external_id')
                    ->on('was.external_id', '=', 'now.external_id')
                    ->where('was.captured_on', '=', $previous);
            })
            ->leftJoin('product_groups as g', 'g.id', '=', 'now.group_id')
            ->leftJoin('chart_categories as c', function ($join): void {
                $join->on('c.source', '=', 'now.source')
                    ->on('c.market', '=', 'now.market')
                    ->on('c.external_id', '=', 'now.category_external_id');
            })
            ->where('now.market', $market->value)
            ->where('now.captured_on', $latest)
            ->when($source !== null, fn ($q) => $q->where('now.source', $source->value))
            ->select([
                'now.external_id',
                'now.group_id',
                'now.category_external_id',
                'now.rank',
                'was.rank as previous_rank',
                'g.title',
                'c.name as category_name',
            ])
            ->get();

        return $rows->map(fn ($row) => new TrendMove(
            externalId: (string) $row->external_id,
            groupId: $row->group_id === null ? null : (int) $row->group_id,
            title: $row->title === null ? null : (string) $row->title,
            categoryExternalId: (string) $row->category_external_id,
            categoryName: $row->category_name === null ? null : (string) $row->category_name,
            rank: (int) $row->rank,
            previousRank: $row->previous_rank === null ? null : (int) $row->previous_rank,
        ));
    }

    /**
     * The most recent snapshot at least `$windowDays` before the latest one.
     *
     * "Nearest older than", not "exactly N days ago". A skipped run or a deploy
     * that ate a night would otherwise find no row and report the entire chart
     * as new entries — the loudest possible signal, produced by a gap in our own
     * data.
     */
    private function comparisonDate(Market $market, string $latest, int $windowDays, ?Source $source): ?string
    {
        $cutoff = now()->parse($latest)->subDays(max(1, $windowDays))->toDateString();

        $date = DB::table('popular_ranks')
            ->where('market', $market->value)
            ->when($source !== null, fn ($q) => $q->where('source', $source->value))
            ->where('captured_on', '<=', $cutoff)
            ->max('captured_on');

        return $date === null ? null : (string) $date;
    }
}

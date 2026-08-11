<?php

declare(strict_types=1);

namespace App\Services\Charts;

use App\Enums\Market;
use App\Enums\Source;
use App\Jobs\PullPopularCharts;
use App\Models\ChartCategory;
use App\Models\PopularRank;
use App\Services\Connectors\ChartCategory as DiscoveredCategory;
use App\Services\Connectors\ChartEntry;
use App\Services\Connectors\PopularityConnector;
use App\Services\Ingestion\OfferUpserter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pull one chart and write down what it said.
 *
 * The decision half of chart ingestion; {@see PullPopularCharts}
 * orchestrates. Three writes, in this order and for these reasons:
 *
 * 1. **The products**, through the ordinary {@see OfferUpserter} — the same path
 *    a live search already takes. A chart entry that never reaches
 *    `product_groups` cannot be suggested by anything, because every engine here
 *    ranks groups.
 * 2. **The ranks**, decision-only. Position and date, no catalogue copy.
 * 3. **The categories**, which is how the next chart becomes discoverable.
 *
 * Idempotent throughout: the scheduler retries, redeploys interrupt jobs, and an
 * operator will run this by hand. Two pulls of one chart on one day must leave
 * exactly one snapshot.
 */
class ChartPuller
{
    public function __construct(private readonly OfferUpserter $upserter) {}

    /**
     * @param  string|null  $categoryId  null for the market-wide chart
     * @param  int|null  $parentDepth  depth of the chart being pulled; children get one more
     */
    public function pull(
        PopularityConnector $connector,
        Market $market,
        ?string $categoryId = null,
        int $parentDepth = 0,
    ): ChartPullResult {
        $source = $connector->source();

        $chart = $connector->popular($market, $categoryId, $this->limit($source));

        if ($chart->isEmpty()) {
            // No entries still means the categories are worth keeping — a chart
            // can be empty for a market and still tell us the taxonomy.
            $discovered = $this->recordCategories($source, $market, $chart->categories, $parentDepth);

            return new ChartPullResult(categoriesDiscovered: $discovered);
        }

        $offers = array_map(fn ($entry) => $entry->offer, $chart->entries);

        /*
         * The catalogue write is gated, not assumed.
         *
         * bol permits it; Amazon does not, and this class must not be the place
         * that forgets. When storage is forbidden the ranks are still recorded —
         * that is exactly what decision-only storage is for, and the products are
         * re-fetched live at render instead.
         */
        $written = 0;
        $skipped = 0;

        if ($source->allowsCatalogueStorage()) {
            $result = $this->upserter->upsert($offers);
            $written = $result['written'];
            $skipped = $result['skipped'];
        }

        $this->recordRanks($source, $market, $categoryId, $chart->entries);

        $discovered = $this->recordCategories($source, $market, $chart->categories, $parentDepth);

        if ($categoryId !== null) {
            ChartCategory::query()
                ->where('source', $source->value)
                ->where('market', $market->value)
                ->where('external_id', $categoryId)
                ->update(['last_pulled_at' => now()]);
        }

        return new ChartPullResult(
            entries: count($chart->entries),
            productsWritten: $written,
            productsSkipped: $skipped,
            categoriesDiscovered: $discovered,
        );
    }

    /**
     * One row per entry per day.
     *
     * @param  list<ChartEntry>  $entries
     */
    private function recordRanks(Source $source, Market $market, ?string $categoryId, array $entries): void
    {
        $now = Carbon::now();
        $rows = [];

        foreach ($entries as $entry) {
            $rows[] = [
                'source' => $source->value,
                'market' => $market->value,
                // '*' rather than null — the daily unique key cannot span a
                // nullable column in Postgres. See the migration.
                'category_external_id' => $categoryId ?? PopularRank::OVERALL,
                'external_id' => $entry->offer->externalId,
                'rank' => $entry->rank,
                'captured_on' => $now->toDateString(),
                'captured_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return;
        }

        DB::table('popular_ranks')->upsert(
            $rows,
            ['source', 'market', 'category_external_id', 'external_id', 'captured_on'],
            // group_id is deliberately absent: a re-run must not blank a link
            // that a later pass already resolved.
            ['rank', 'captured_at', 'updated_at'],
        );
    }

    /**
     * @param  list<DiscoveredCategory>  $categories
     * @return int how many were new
     */
    private function recordCategories(Source $source, Market $market, array $categories, int $parentDepth): int
    {
        if ($categories === []) {
            return 0;
        }

        $existing = ChartCategory::query()
            ->where('source', $source->value)
            ->where('market', $market->value)
            ->pluck('external_id', 'slug');

        $new = 0;

        foreach ($categories as $category) {
            $isNew = ! $existing->contains($category->externalId);
            $slug = $this->slug($category, $existing);

            ChartCategory::query()->updateOrCreate(
                [
                    'source' => $source->value,
                    'market' => $market->value,
                    'external_id' => $category->externalId,
                ],
                [
                    'name' => $category->name,
                    'slug' => $slug,
                    'parent_external_id' => $category->parentExternalId,
                    // Depth is where we FOUND it, not where the source thinks it
                    // sits. It exists to bound the crawl, and the crawl is what
                    // produced this row.
                    'depth' => $parentDepth + 1,
                    'product_count' => $category->productCount,
                ],
            );

            // Kept current within the run, so two same-named categories
            // discovered from one response cannot both claim the bare slug.
            $existing->put($slug, $category->externalId);

            if ($isNew) {
                $new++;
            }
        }

        return $new;
    }

    /**
     * A stable, readable slug — and a collision-proof one.
     *
     * bol reuses category names across branches of its taxonomy ("Accessoires"
     * appears under half a dozen parents), so the name alone is not unique
     * within a market. A colliding slug would violate the unique index and abort
     * the whole pull, so a collision falls back to appending the source's own id
     * rather than failing.
     *
     * @param  Collection<string, string>  $existing  slug => external id
     */
    private function slug(DiscoveredCategory $category, $existing): string
    {
        $slug = Str::slug($category->name);

        if ($slug === '') {
            return 'c-'.Str::slug($category->externalId);
        }

        $taken = $existing->get($slug);

        return ($taken === null || $taken === $category->externalId)
            ? $slug
            : $slug.'-'.Str::slug($category->externalId);
    }

    /**
     * Attach rank rows to the groups their products ended up in.
     *
     * Separate from the pull, and re-run every time, because grouping happens on
     * its own schedule: a product upserted by this morning's pull has no group
     * until GroupProducts runs, so the link is resolved on a later pass rather
     * than at write time. Self-healing by construction — anything still unlinked
     * inside the window gets another attempt tomorrow.
     *
     * @return int rows linked
     */
    public function linkRanks(Source $source, Market $market, int $days = 30): int
    {
        return DB::update(<<<'SQL'
            UPDATE popular_ranks r
            SET group_id = p.group_id, updated_at = now()
            FROM products p
            WHERE r.source = ?
              AND r.market = ?
              AND r.captured_on >= ?
              AND r.group_id IS NULL
              AND p.source = r.source
              AND p.market = r.market
              AND p.external_id = r.external_id
              AND p.group_id IS NOT NULL
        SQL, [
            $source->value,
            $market->value,
            Carbon::now()->subDays($days)->toDateString(),
        ]);
    }

    /** How many entries to collect per chart, per that source's own settings. */
    private function limit(Source $source): int
    {
        $pageSize = (int) config("brandcoves.connectors.{$source->value}.popular.page_size", 50);
        $pages = (int) config("brandcoves.connectors.{$source->value}.popular.pages", 2);

        return max(1, $pageSize * $pages);
    }
}

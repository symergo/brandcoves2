<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Enums\Source;
use App\Models\ChartCategory;
use App\Models\PopularRank;
use App\Services\Discovery\MarketTrends;
use App\Services\Discovery\TrendMove;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reading movement off the rank history.
 *
 * The instrument behind the admin trends page and the chart-derived guide
 * topics. Its failure modes are all of the "plausible and wrong" kind — a
 * comparison against the wrong date, or against nothing at all, produces a page
 * full of confident arrows that mean nothing.
 */
class MarketTrendsTest extends TestCase
{
    use RefreshDatabase;

    private function rank(string $externalId, int $rank, string $on, string $category = PopularRank::OVERALL): void
    {
        PopularRank::create([
            'source' => Source::Bol->value,
            'market' => Market::BeNl->value,
            'category_external_id' => $category,
            'external_id' => $externalId,
            'rank' => $rank,
            'captured_on' => $on,
            'captured_at' => $on.' 03:40:00',
        ]);
    }

    private function trends(): MarketTrends
    {
        return app(MarketTrends::class);
    }

    #[Test]
    public function it_separates_climbers_fallers_and_arrivals(): void
    {
        $was = now()->subDays(7)->toDateString();
        $now = now()->toDateString();

        $this->rank('climber', 40, $was);
        $this->rank('climber', 6, $now);

        $this->rank('faller', 4, $was);
        $this->rank('faller', 30, $now);

        $this->rank('arrival', 9, $now);

        $this->assertSame(['climber'], array_map(
            fn (TrendMove $m) => $m->externalId,
            $this->trends()->risers(Market::BeNl),
        ));

        $this->assertSame(['faller'], array_map(
            fn (TrendMove $m) => $m->externalId,
            $this->trends()->fallers(Market::BeNl),
        ));

        $this->assertSame(['arrival'], array_map(
            fn (TrendMove $m) => $m->externalId,
            $this->trends()->newEntries(Market::BeNl),
        ));
    }

    #[Test]
    public function a_new_entry_is_not_reported_as_an_enormous_climb(): void
    {
        $this->rank('anything', 50, now()->subDays(7)->toDateString());
        $this->rank('arrival', 3, now()->toDateString());

        $arrival = collect($this->trends()->moves(Market::BeNl))
            ->firstWhere(fn (TrendMove $m) => $m->externalId === 'arrival');

        // "Came from nowhere" and "climbed a long way" are different events, and
        // folding the first into the second would make every arrival the biggest
        // mover on the page forever.
        $this->assertTrue($arrival->isNewEntry());
        $this->assertNull($arrival->delta());
    }

    #[Test]
    public function one_snapshot_reports_no_trend_at_all(): void
    {
        $this->rank('a', 1, now()->toDateString());
        $this->rank('b', 2, now()->toDateString());

        /*
         * On the day a new environment first pulls a chart, every row is
         * technically a new entry. Saying so would fill the trends page with
         * arrivals on the one day the data cannot support the claim — one
         * snapshot is a list, not a trend.
         */
        $this->assertSame([], $this->trends()->newEntries(Market::BeNl));
        $this->assertSame([], $this->trends()->risers(Market::BeNl));
    }

    #[Test]
    public function movement_is_measured_per_chart_not_across_charts(): void
    {
        $was = now()->subDays(7)->toDateString();
        $now = now()->toDateString();

        // #2 in a category and #80 overall, unchanged in both.
        $this->rank('x', 2, $was, '4770');
        $this->rank('x', 80, $was, PopularRank::OVERALL);
        $this->rank('x', 2, $now, '4770');
        $this->rank('x', 80, $now, PopularRank::OVERALL);

        // Comparing its category position against its market-wide one would
        // invent a 78-place move that neither chart recorded.
        $this->assertSame([], $this->trends()->risers(Market::BeNl));
        $this->assertSame([], $this->trends()->fallers(Market::BeNl));
    }

    #[Test]
    public function the_comparison_reaches_past_a_gap_in_the_data(): void
    {
        // A skipped run, a deploy that ate a night: no snapshot exactly seven
        // days back. Finding nothing there would report the whole chart as
        // arrivals — the loudest possible signal, produced by our own gap.
        $this->rank('steady', 10, now()->subDays(21)->toDateString());
        $this->rank('steady', 4, now()->toDateString());

        $risers = $this->trends()->risers(Market::BeNl);

        $this->assertCount(1, $risers);
        $this->assertSame(6, $risers[0]->delta());
    }

    #[Test]
    public function active_categories_rank_by_churn_rather_than_size(): void
    {
        ChartCategory::create([
            'source' => Source::Bol->value,
            'market' => Market::BeNl->value,
            'external_id' => 'small',
            'name' => 'Small but moving',
            'slug' => 'small-but-moving',
        ]);

        ChartCategory::create([
            'source' => Source::Bol->value,
            'market' => Market::BeNl->value,
            'external_id' => 'big',
            'name' => 'Big and static',
            'slug' => 'big-and-static',
        ]);

        $was = now()->subDays(7)->toDateString();
        $now = now()->toDateString();

        // Three entries, all of them moved.
        foreach ([1, 2, 3] as $i) {
            $this->rank("s{$i}", $i + 3, $was, 'small');
            $this->rank("s{$i}", $i, $now, 'small');
        }

        // Ten entries, three of them moved.
        foreach (range(1, 10) as $i) {
            $this->rank("b{$i}", $i <= 3 ? $i + 5 : $i, $was, 'big');
            $this->rank("b{$i}", $i, $now, 'big');
        }

        $categories = $this->trends()->activeCategories(Market::BeNl);

        // Churn, not absolute movement: three swaps out of three is a category
        // turning over, three out of ten is a category with a busy fortnight.
        $this->assertSame('small', $categories[0]['category_external_id']);
        $this->assertSame('Small but moving', $categories[0]['name']);
        $this->assertEqualsWithDelta(1.0, $categories[0]['churn'], 0.001);
    }
}

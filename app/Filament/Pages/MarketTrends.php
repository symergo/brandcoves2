<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\Market;
use App\Models\ChartCategory;
use App\Models\PopularRank;
use App\Services\Discovery\MarketTrends as Trends;
use App\Services\Discovery\TrendMove;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * What is moving in each market, read off the bestseller charts.
 *
 * The chart data has two jobs, and this is the second one: the engines use it to
 * decide what to suggest, and a person uses this page to decide what to write
 * about and which categories are worth chasing an advertiser for. It is the only
 * place the rankings are shown to anybody — they are never published to visitors,
 * because a retailer's chart is their measurement and not our content.
 *
 * Read-only on purpose. Nothing here writes, nothing here triggers a pull; the
 * command and the scheduler own that, and a button that quietly spends a
 * rate-limited API budget is a button somebody will hold down.
 */
class MarketTrends extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Market trends';

    protected static ?string $title = 'Market trends';

    protected string $view = 'filament.pages.market-trends';

    /** Which market is on screen. */
    public string $market = '';

    /**
     * One MarketTrends per render, so its remembered moves are shared by the
     * four lists on the page. Protected, so Livewire does not carry it between
     * requests: every request starts from fresh chart data.
     */
    protected ?Trends $trends = null;

    public function mount(): void
    {
        $this->market = $this->marketsWithData()[0] ?? Market::default()->value;
    }

    /**
     * Markets that have chart data, so the switcher does not offer empty tabs.
     *
     * @return list<string>
     */
    public function marketsWithData(): array
    {
        /*
         * One EXISTS per market rather than a DISTINCT over the whole table.
         *
         * `SELECT DISTINCT market` reads every rank row ever captured, and the
         * table only grows: a year of daily charts is millions of rows to learn
         * five values. Each EXISTS stops at its first row, and there are only
         * as many as there are markets.
         */
        return array_values(array_filter(
            Market::values(),
            fn (string $m) => DB::table('popular_ranks')->where('market', $m)->exists(),
        ));
    }

    private function trends(): Trends
    {
        return $this->trends ??= app(Trends::class);
    }

    public function selected(): Market
    {
        return Market::tryFrom($this->market) ?? Market::default();
    }

    /**
     * When the two snapshots being compared were taken.
     *
     * Shown rather than assumed: every number on this page is a difference
     * between two dates, and "up 14 places" means nothing without knowing whether
     * that is since yesterday or since a gap in the data three weeks ago.
     *
     * @return array{latest: ?string, window_days: int, categories: int, entries: int}
     */
    public function snapshot(): array
    {
        $market = $this->selected();
        $latest = PopularRank::latestCapturedOn($market);

        return [
            'latest' => $latest,
            'window_days' => Trends::WINDOW_DAYS,
            'categories' => ChartCategory::query()->forMarket($market)->enabled()->count(),
            'entries' => $latest === null ? 0 : DB::table('popular_ranks')
                ->where('market', $market->value)
                ->where('captured_on', $latest)
                ->count(),
        ];
    }

    /** @return list<TrendMove> */
    public function risers(): array
    {
        return $this->trends()->risers($this->selected());
    }

    /** @return list<TrendMove> */
    public function newEntries(): array
    {
        return $this->trends()->newEntries($this->selected());
    }

    /** @return list<TrendMove> */
    public function fallers(): array
    {
        return $this->trends()->fallers($this->selected());
    }

    /** @return list<array{category_external_id: string, name: string|null, entries: int, moved: int, new: int, churn: float}> */
    public function activeCategories(): array
    {
        return $this->trends()->activeCategories($this->selected());
    }
}

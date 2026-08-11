<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\Source;
use App\Jobs\PullPopularCharts;
use App\Models\ChartCategory;
use App\Models\IngestionJob;
use App\Models\Merchant;
use App\Models\PopularRank;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Charts\ChartPuller;
use App\Services\Connectors\ChartCategory as DiscoveredCategory;
use App\Services\Connectors\ChartEntry;
use App\Services\Connectors\ConnectorRegistry;
use App\Services\Connectors\Offer;
use App\Services\Connectors\PopularChart;
use App\Services\Connectors\PopularityConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * From a source's chart to rows we can suggest from.
 *
 * Three properties carry the feature: the snapshot is one per chart per day
 * however often the puller runs, the crawl is bounded and says what it deferred,
 * and rank rows find their product group even though grouping happens on a
 * different schedule.
 */
class PopularChartPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'brandcoves.connectors.bol.popular.enabled' => true,
            'brandcoves.connectors.bol.popular.page_size' => 50,
            'brandcoves.connectors.bol.popular.pages' => 1,
            'brandcoves.connectors.bol.popular.max_categories' => 40,
            'brandcoves.connectors.bol.popular.max_depth' => 2,
        ]);
    }

    /**
     * A stub source: a scripted chart per category, and a count of what was
     * asked for. No HTTP — the connector's own contract is covered by
     * BolPopularChartTest, and what matters here is what the puller does with
     * whatever it gets.
     */
    private function connector(array $charts, bool $coolingDown = false): PopularityConnector
    {
        return new class($charts, $coolingDown) implements PopularityConnector
        {
            /** @var list<string|null> */
            public array $asked = [];

            public function __construct(private array $charts, private bool $coolingDown) {}

            public function source(): Source
            {
                return Source::Bol;
            }

            public function supports(Market $market): bool
            {
                return $market->bolCountry() !== null;
            }

            public function isChartCoolingDown(): bool
            {
                return $this->coolingDown;
            }

            public function popular(Market $market, ?string $categoryId, int $limit): PopularChart
            {
                $this->asked[] = $categoryId;

                return $this->charts[$categoryId ?? '*'] ?? PopularChart::empty();
            }
        };
    }

    private function offer(string $externalId, string $title): Offer
    {
        return new Offer(
            source: Source::Bol,
            externalId: $externalId,
            market: Market::BeNl,
            title: $title,
            affiliateUrl: 'https://partner.bol.com/click/click?url=x',
            price: 9995,
            merchantName: 'bol.com',
            merchantExternalId: 'bol',
            merchantDeepLink: 'https://www.bol.com/nl/p/x/'.$externalId.'/',
            imageUrl: 'https://media.bol.com/1.jpg',
            ean: '400638133393'.substr($externalId, -1),
            availability: Availability::InStock,
        );
    }

    /** @param list<array{0: string, 1: string}> $products */
    private function chart(array $products, array $categories = []): PopularChart
    {
        $entries = [];
        $rank = 0;

        foreach ($products as [$id, $title]) {
            $entries[] = new ChartEntry($this->offer($id, $title), ++$rank);
        }

        return new PopularChart($entries, $categories);
    }

    #[Test]
    public function a_pull_writes_ranks_and_seeds_the_catalogue(): void
    {
        $connector = $this->connector([
            '*' => $this->chart(
                [['1', 'First'], ['2', 'Second']],
                [new DiscoveredCategory('4770', 'Koptelefoons')],
            ),
        ]);

        app(ChartPuller::class)->pull($connector, Market::BeNl, null);

        // Ranks: the decision, stored as position + date and nothing else.
        $this->assertSame(2, PopularRank::query()->count());
        $this->assertSame(1, (int) PopularRank::query()->where('external_id', '1')->value('rank'));
        $this->assertSame(
            PopularRank::OVERALL,
            PopularRank::query()->where('external_id', '1')->value('category_external_id'),
        );

        /*
         * And the products, through the ordinary upserter.
         *
         * Without this a chart entry can never be suggested by anything: every
         * engine on the site ranks product_groups, so an entry that does not
         * reach the catalogue is a row nobody can ever act on.
         */
        $this->assertSame(2, Product::query()->where('source', Source::Bol->value)->count());

        // And the frontier for tomorrow.
        $this->assertDatabaseHas('chart_categories', ['external_id' => '4770', 'slug' => 'koptelefoons']);
    }

    #[Test]
    public function two_pulls_on_one_day_leave_one_snapshot(): void
    {
        $connector = $this->connector(['*' => $this->chart([['1', 'First'], ['2', 'Second']])]);

        app(ChartPuller::class)->pull($connector, Market::BeNl, null);
        app(ChartPuller::class)->pull($connector, Market::BeNl, null);

        // The scheduler retries, redeploys interrupt jobs, and an operator will
        // run this by hand. A second snapshot for the same Tuesday would make
        // every movement calculation compare a day against itself.
        $this->assertSame(2, PopularRank::query()->count());
    }

    #[Test]
    public function a_source_that_may_not_be_mirrored_still_gets_its_ranks_recorded(): void
    {
        $connector = new class($this->chart([['B01', 'An ASIN']])) implements PopularityConnector
        {
            public function __construct(private PopularChart $chart) {}

            public function source(): Source
            {
                // Amazon forbids mirroring title, price and image.
                return Source::Amazon;
            }

            public function supports(Market $market): bool
            {
                return true;
            }

            public function isChartCoolingDown(): bool
            {
                return false;
            }

            public function popular(Market $market, ?string $categoryId, int $limit): PopularChart
            {
                return $this->chart;
            }
        };

        app(ChartPuller::class)->pull($connector, Market::BeNl, null);

        /*
         * The decision is stored; the catalogue entry is not.
         *
         * This is the whole reason popular_ranks holds an external id and a
         * position rather than a copy of the product — it makes the same table
         * correct for a source that permits mirroring and one that forbids it,
         * with no second schema and no special case at the call site.
         */
        $this->assertSame(1, PopularRank::query()->where('source', Source::Amazon->value)->count());
        $this->assertSame(0, Product::query()->where('source', Source::Amazon->value)->count());
    }

    #[Test]
    public function ranks_are_linked_to_their_group_once_grouping_has_run(): void
    {
        $connector = $this->connector(['*' => $this->chart([['1', 'First']])]);
        $puller = app(ChartPuller::class);

        $puller->pull($connector, Market::BeNl, null);

        // Products are grouped by a job on its own schedule, so at write time
        // there is nothing to link to. Null is the honest state, not a bug.
        $this->assertNull(PopularRank::query()->value('group_id'));

        $group = ProductGroup::create([
            'market' => Market::BeNl,
            'identity_key' => 'k-linked',
            'identity_kind' => 'ean',
            'title' => 'First',
            'slug' => 'first',
            'image_url' => 'https://img.test/x.jpg',
            'min_price' => 9995,
            'merchant_count' => 1,
            'in_stock' => true,
        ]);

        Product::query()->where('external_id', '1')->update(['group_id' => $group->id]);

        $linked = $puller->linkRanks(Source::Bol, Market::BeNl);

        $this->assertSame(1, $linked);
        $this->assertSame($group->id, (int) PopularRank::query()->value('group_id'));
    }

    #[Test]
    public function the_crawl_is_bounded_and_says_what_it_deferred(): void
    {
        Log::spy();

        config(['brandcoves.connectors.bol.popular.max_categories' => 1]);

        $charts = [
            '*' => $this->chart([['1', 'First']], [
                new DiscoveredCategory('4770', 'Koptelefoons'),
                new DiscoveredCategory('4771', 'Speakers'),
                new DiscoveredCategory('4772', 'Kabels'),
            ]),
        ];

        $connector = $this->connector($charts);
        $this->app->instance(ConnectorRegistry::class, $this->registryWith($connector));

        (new PullPopularCharts(Market::BeNl))->handle(
            app(ConnectorRegistry::class),
            app(ChartPuller::class),
        );

        // The market-wide chart plus exactly one category.
        $this->assertSame([null, '4770'], $connector->asked);

        /*
         * And it must SAY so. A silent cap reads as "we covered everything",
         * which is the wrong impression to leave about a crawl that
         * deliberately does not — someone comparing our category coverage
         * against bol's would otherwise conclude the discovery step is broken.
         */
        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message) => str_contains($message, 'per-run budget'))
            ->atLeast()->once();
    }

    #[Test]
    public function a_category_is_not_pulled_twice_in_one_day(): void
    {
        $connector = $this->connector([
            '*' => $this->chart([['1', 'First']], [new DiscoveredCategory('4770', 'Koptelefoons')]),
            '4770' => $this->chart([['2', 'Second']]),
        ]);

        $this->app->instance(ConnectorRegistry::class, $this->registryWith($connector));

        foreach (range(1, 2) as $ignored) {
            (new PullPopularCharts(Market::BeNl))->handle(
                app(ConnectorRegistry::class),
                app(ChartPuller::class),
            );
        }

        // Second run: the market-wide chart again (its daily snapshot is the one
        // that matters most), but not the category — that would spend a request
        // from a rate-limited budget to overwrite the same row.
        $this->assertSame([null, '4770', null], $connector->asked);
    }

    #[Test]
    public function an_interrupted_crawl_keeps_its_place(): void
    {
        $connector = $this->connector(
            ['*' => $this->chart([['1', 'First']])],
            coolingDown: true,
        );

        $this->app->instance(ConnectorRegistry::class, $this->registryWith($connector));

        (new PullPopularCharts(Market::BeNl))->handle(
            app(ConnectorRegistry::class),
            app(ChartPuller::class),
        );

        /*
         * The cursor survives a run that stopped early.
         *
         * `last_pulled_at` alone would let a same-day retry re-pull the
         * market-wide chart for nothing — two requests out of a budget that was
         * already refusing, which is what triggered the stop in the first place.
         */
        $tracker = IngestionJob::query()->where('job_key', 'bol:charts:be-nl')->first();

        $this->assertNotNull($tracker);
        $this->assertSame(1, $tracker->cursor['categories_done'] ?? null);
    }

    #[Test]
    public function a_market_the_source_does_not_serve_is_skipped(): void
    {
        $connector = $this->connector(['*' => $this->chart([['1', 'First']])]);
        $this->app->instance(ConnectorRegistry::class, $this->registryWith($connector));

        (new PullPopularCharts(Market::Es))->handle(
            app(ConnectorRegistry::class),
            app(ChartPuller::class),
        );

        // Not an error, and not a failed job in the admin table: bol simply does
        // not operate in Spain.
        $this->assertSame([], $connector->asked);
        $this->assertSame(0, IngestionJob::query()->count());
    }

    #[Test]
    public function two_categories_sharing_a_name_do_not_collide_on_one_slug(): void
    {
        // bol reuses category names across branches of its taxonomy —
        // "Accessoires" hangs under half a dozen parents — so the name alone is
        // not unique within a market, and a collision would abort the pull.
        $connector = $this->connector(['*' => $this->chart([['1', 'First']], [
            new DiscoveredCategory('100', 'Accessoires'),
            new DiscoveredCategory('200', 'Accessoires'),
        ])]);

        app(ChartPuller::class)->pull($connector, Market::BeNl, null);

        $slugs = ChartCategory::query()->orderBy('external_id')->pluck('slug', 'external_id');

        $this->assertSame('accessoires', $slugs['100']);
        $this->assertSame('accessoires-200', $slugs['200']);
    }

    private function registryWith(PopularityConnector $connector): ConnectorRegistry
    {
        $registry = new ConnectorRegistry;
        $registry->registerPopularity($connector);

        return $registry;
    }

    private function merchant(): Merchant
    {
        return Merchant::firstOrCreate(
            ['source' => Source::Bol->value, 'external_id' => 'bol'],
            ['name' => 'bol.com'],
        );
    }
}

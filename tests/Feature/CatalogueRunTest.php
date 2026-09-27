<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Enums\Source;
use App\Jobs\ClassifyGiftability;
use App\Jobs\ContinueCatalogueRun;
use App\Jobs\FindMatchCandidates;
use App\Jobs\GroupProducts;
use App\Jobs\IngestFeed;
use App\Jobs\PlanGiftLandingPages;
use App\Jobs\RefreshBrandStats;
use App\Models\Feed;
use App\Models\Product;
use App\Services\Ingestion\CatalogueRun;
use App\Services\Ingestion\ProductGrouper;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * The catalogue run: each step when the one before it is done, market by market.
 *
 * Replaced fixed clock times on 2026-09-28 (ingest 04:10, group 05:00,
 * classify 05:10, ...). See App\Services\Ingestion\CatalogueRun.
 */
class CatalogueRunTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    #[Test]
    public function one_market_is_a_chain_from_its_feeds_to_its_landing_pages(): void
    {
        Bus::fake();
        $this->feed(Market::BeNl);

        CatalogueRun::start([Market::BeNl, Market::NlNl], morning: true);

        Bus::assertChained([
            Bus::chainedBatch(fn (PendingBatch $batch) => $batch->jobs->count() === 1
                && $batch->jobs->first() instanceof IngestFeed
                && $batch->allowsFailures()),
            GroupProducts::class,
            ClassifyGiftability::class,
            RefreshBrandStats::class,
            FindMatchCandidates::class,
            PlanGiftLandingPages::class,
            new ContinueCatalogueRun([Market::NlNl], true),
        ]);
    }

    #[Test]
    public function the_afternoon_run_and_an_unpublished_market_plan_no_landing_pages(): void
    {
        $this->assertNotContains(PlanGiftLandingPages::class, array_map(
            fn ($step) => $step::class,
            CatalogueRun::stepsFor(Market::BeNl, morning: false),
        ));

        $this->assertFalse(Market::Es->isPublished());
        $this->assertNotContains(PlanGiftLandingPages::class, array_map(
            fn ($step) => $step::class,
            CatalogueRun::stepsFor(Market::Es, morning: true),
        ));
    }

    #[Test]
    public function the_steps_run_in_order_market_after_market_then_the_whole_catalogue_ones(): void
    {
        $this->feed(Market::BeNl);
        $order = $this->recordOrder();

        CatalogueRun::start([Market::BeNl, Market::NlNl], morning: true);

        $this->assertSame([
            // Laravel's own job that turns the batch in the chain into a batch.
            'ChainedBatch',
            'IngestFeed',
            'GroupProducts:be-nl',
            'ClassifyGiftability:be-nl',
            'RefreshBrandStats:be-nl',
            'FindMatchCandidates:be-nl',
            'PlanGiftLandingPages:be-nl',
            'ContinueCatalogueRun',
            'GroupProducts:nl-nl',
            'ClassifyGiftability:nl-nl',
            'RefreshBrandStats:nl-nl',
            'FindMatchCandidates:nl-nl',
            'PlanGiftLandingPages:nl-nl',
            'ContinueCatalogueRun',
            'LinkBarcodeItems',
            'RefreshWishlistedProducts',
            'FireWatchAlerts',
        ], $order->all);

        // And the ingest really landed before the grouping read it.
        $this->assertGreaterThan(0, Product::query()->whereNotNull('group_id')->count());
    }

    #[Test]
    public function a_market_whose_step_fails_does_not_stop_the_next_market(): void
    {
        $order = $this->recordOrder();

        // Grouping throws for be-nl only.
        $this->app->bind(ProductGrouper::class, fn () => new class extends ProductGrouper
        {
            public function run(Market $market): array
            {
                if ($market === Market::BeNl) {
                    throw new RuntimeException('be-nl grouping failed');
                }

                return parent::run($market);
            }
        });

        try {
            CatalogueRun::start([Market::BeNl, Market::NlNl], morning: false);
        } catch (Throwable) {
            // The sync queue rethrows the failure after the chain's catch has run.
        }

        $this->assertContains('GroupProducts:nl-nl', $order->all);
        $this->assertContains('RefreshWishlistedProducts', $order->all);
        $this->assertNotContains('ClassifyGiftability:be-nl', $order->all, 'the failed market stops at its failed step');
    }

    /** @return object{all: list<string>} */
    private function recordOrder(): object
    {
        $order = new class
        {
            /** @var list<string> */
            public array $all = [];
        };

        Event::listen(JobProcessing::class, function (JobProcessing $event) use ($order): void {
            $command = unserialize($event->job->payload()['data']['command']);
            $name = class_basename($command);

            if (property_exists($command, 'market') && $command->market instanceof Market) {
                $name .= ':'.$command->market->value;
            }

            $order->all[] = $name;
        });

        return $order;
    }

    private function feed(Market $market): Feed
    {
        return Feed::create([
            'source' => Source::Awin,
            'external_feed_id' => '18755',
            'market' => $market,
            'label' => 'Test advertiser',
            'enabled' => true,
            'column_map' => ['url' => base_path('tests/Fixtures/awin-sample.csv')],
        ]);
    }
}

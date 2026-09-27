<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\Market;
use App\Enums\Source;
use App\Jobs\ClassifyGiftability;
use App\Jobs\FindMatchCandidates;
use App\Jobs\GroupProducts;
use App\Jobs\IngestFeed;
use App\Jobs\LinkBarcodeItems;
use App\Jobs\PlanGiftLandingPages;
use App\Jobs\PullPopularCharts;
use App\Jobs\RefreshBrandStats;
use App\Jobs\RefreshWishlistedProducts;
use App\Models\Feed;
use App\Models\IngestionJob;
use App\Services\Ingestion\ProductGrouper;
use Exception;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The long jobs run one at a time per feed, market or chart.
 *
 * Each of the three defined `uniqueId()` from the start, and none implemented
 * `ShouldBeUnique`, which is the only thing that makes Laravel read it. So
 * "one ingestion per feed at a time, however many times it gets queued" was a
 * comment rather than a guarantee, and two runs for one feed would have
 * interleaved their cursor writes. The scheduler's `withoutOverlapping()` did
 * not cover it either: that guards the closure that *dispatches*, which is
 * over in milliseconds.
 */
class JobUniquenessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_chunked_jobs_are_unique(): void
    {
        foreach ([IngestFeed::class, PullPopularCharts::class] as $job) {
            $this->assertTrue(
                is_subclass_of($job, ShouldBeUnique::class),
                "{$job} defines uniqueId(), which counts for nothing unless it implements ShouldBeUnique",
            );
        }
    }

    /**
     * The steps of the catalogue run guard themselves when they RUN.
     *
     * They must not be ShouldBeUnique (since 2026-09-28): Laravel checks that
     * when a chain dispatches its next job, and a refused job is dropped with
     * the rest of the chain. See App\Jobs\Concerns\RunsOneAtATime.
     */
    #[Test]
    public function the_catalogue_run_steps_run_one_at_a_time_without_being_unique(): void
    {
        foreach ([
            new GroupProducts(Market::BeNl),
            new ClassifyGiftability(Market::BeNl),
            new RefreshBrandStats(Market::BeNl),
            new FindMatchCandidates(Market::BeNl),
            new PlanGiftLandingPages(Market::BeNl),
            new LinkBarcodeItems,
            new RefreshWishlistedProducts,
        ] as $job) {
            $this->assertNotInstanceOf(ShouldBeUnique::class, $job, $job::class.' would cut the catalogue chain');
            $this->assertContainsOnlyInstancesOf(WithoutOverlapping::class, $job->middleware());
            $this->assertNotEmpty($job->middleware(), $job::class.' has no overlap guard');
        }
    }

    #[Test]
    public function a_second_grouping_of_the_same_market_while_one_runs_is_skipped(): void
    {
        Cache::flush();

        $lock = Cache::lock((new WithoutOverlapping(Market::BeNl->value))->getLockKey(new GroupProducts(Market::BeNl)), 60);
        $this->assertTrue($lock->get());

        try {
            $ran = false;
            $this->app->bind(ProductGrouper::class, function () use (&$ran) {
                $ran = true;

                return new ProductGrouper;
            });

            GroupProducts::dispatchSync(Market::BeNl);

            $this->assertFalse($ran, 'the second copy must not run while the first holds the market');
        } finally {
            $lock->release();
            Cache::flush();
        }
    }

    #[Test]
    public function a_feed_queued_twice_is_ingested_once(): void
    {
        Queue::fake();
        Cache::flush();

        try {
            IngestFeed::dispatch(7);
            IngestFeed::dispatch(7);
            IngestFeed::dispatch(8);

            // The lock is taken at dispatch, before the queue sees the job, so
            // a faked queue still shows the second push being refused.
            Queue::assertPushed(IngestFeed::class, 2);
        } finally {
            // The fake never runs the job, so nothing releases the lock.
            Cache::flush();
        }
    }

    #[Test]
    public function a_failed_ingestion_marks_its_own_tracker_and_nobody_elses(): void
    {
        $mine = Feed::create([
            'source' => Source::Awin,
            'external_feed_id' => '18755',
            'market' => Market::BeNl,
            'label' => 'Mine',
            'enabled' => true,
        ]);

        // An advertiser whose external id happens to equal my feed's internal
        // id — the collision the old `LIKE '%:{id}:%'` match walked into.
        $other = Feed::create([
            'source' => Source::Awin,
            'external_feed_id' => (string) $mine->id,
            'market' => Market::NlNl,
            'label' => 'Somebody else',
            'enabled' => true,
        ]);

        foreach ([$mine, $other] as $feed) {
            IngestionJob::create([
                'job_key' => $feed->jobKey(),
                'source' => $feed->source->value,
                'market' => $feed->market->value,
                'status' => JobStatus::Running,
            ]);
        }

        (new IngestFeed($mine->id))->failed(new Exception('upstream 404'));

        $this->assertSame(JobStatus::Failed, IngestionJob::query()->where('job_key', $mine->jobKey())->firstOrFail()->status);
        $this->assertSame(JobStatus::Running, IngestionJob::query()->where('job_key', $other->jobKey())->firstOrFail()->status);
    }
}

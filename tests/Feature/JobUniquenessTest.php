<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\Market;
use App\Enums\Source;
use App\Jobs\GroupProducts;
use App\Jobs\IngestFeed;
use App\Jobs\PullPopularCharts;
use App\Models\Feed;
use App\Models\IngestionJob;
use Exception;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        foreach ([IngestFeed::class, GroupProducts::class, PullPopularCharts::class] as $job) {
            $this->assertTrue(
                is_subclass_of($job, ShouldBeUnique::class),
                "{$job} defines uniqueId(), which counts for nothing unless it implements ShouldBeUnique",
            );
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

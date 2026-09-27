<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\JobStatus;
use App\Enums\ProductStatus;
use App\Models\Feed;
use App\Models\IngestionJob;
use App\Services\Connectors\ConnectorRegistry;
use App\Services\Connectors\SourceSwitch;
use App\Services\Ingestion\OfferUpserter;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ingest one advertiser feed for one market.
 *
 * Chunked and resumable by construction. A feed runs to hundreds of megabytes
 * and tens of thousands of rows; it cannot be done in one transaction, and a
 * redeploy mid-run must not lose the work. Each chunk commits and then records
 * its position, so the stored cursor always trails committed work — never leads
 * it, which would silently skip rows if the process died in between.
 */
#[Queue('batch')]
class IngestFeed implements ShouldBeUnique, ShouldQueue
{
    // Batchable: the nightly catalogue run ingests a market's feeds as one
    // batch and groups the market when the batch is done. See CatalogueRun.
    use Batchable, Queueable;

    /** Long, because a large feed legitimately takes a while. */
    public int $timeout = 3600;

    /**
     * How long the uniqueness lock outlives a job that never released it.
     *
     * `uniqueId()` was here from the start and did nothing: Laravel only reads
     * it on a job that implements `ShouldBeUnique`, and this one did not. Two
     * runs for one feed would interleave their cursor writes and skip or
     * replay rows. The scheduler's `withoutOverlapping()` did not help — it
     * guards the closure that dispatches, which is done in milliseconds.
     * Matched to the timeout, so a crashed worker frees the feed within the
     * hour rather than blocking it until somebody notices.
     */
    public int $uniqueFor = 3600;

    /**
     * One retry. A feed that fails twice is a configuration or upstream
     * problem, and hammering a 404 for an hour helps nobody.
     *
     * Counted in exceptions, not attempts, since 2026-09-28: the job now puts
     * itself back on the queue when a deploy stops the worker, and each of
     * those is an attempt. With `$tries = 2` a run paused by one deploy and
     * failing once would have had no retry left. Five attempts leave room for
     * a few restarts in one night; two exceptions is still one retry.
     */
    public int $tries = 5;

    public int $maxExceptions = 2;

    public function __construct(
        public readonly int $feedId,
    ) {}

    /** One ingestion per feed at a time, however many times it gets queued. */
    public function uniqueId(): string
    {
        return 'ingest-feed-'.$this->feedId;
    }

    /**
     * And one at a time while RUNNING, which uniqueness does not cover for a
     * batch: Laravel checks `ShouldBeUnique` only when a job is dispatched on
     * its own, never for the jobs of a batch. The nightly run ingests in
     * batches, so an "Ingest now" pressed during it could otherwise run the
     * same feed twice at once and interleave the cursor writes. The second
     * one is dropped, not retried: the first is doing the same work.
     *
     * The lock expires a minute after the job's own timeout, so a worker
     * killed outright frees the feed before `retry_after` (3900s) hands the
     * killed job to another worker; a lock still held then would drop the
     * retry.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('ingest-feed-'.$this->feedId))
                ->dontRelease()
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(ConnectorRegistry $registry, OfferUpserter $upserter): void
    {
        $feed = Feed::query()->find($this->feedId);

        if ($feed === null || ! $feed->enabled || $this->batch()?->cancelled()) {
            return;
        }

        /*
         * The per-market source switch, checked here rather than left to
         * `supports()`.
         *
         * Nothing on this path ever calls `supports()`. The scheduler dispatches
         * straight from `Feed::query()->enabled()`, and so do `bc:ingest` and the
         * "Ingest now" button — so a source switched off for a market in the
         * panel would keep downloading its feeds on the usual timetable, which is
         * the one thing switching it off is meant to stop. This is the choke
         * point all three share.
         *
         * Deliberately a silent return, like the disabled-feed check above it: an
         * administrator turning a source off is not a fault, and a job that
         * failed here would retry twice and land in the failed table for doing
         * exactly what it was told.
         */
        if (! app(SourceSwitch::class)->isEnabled($feed->source, $feed->market)) {
            return;
        }

        // Resolved from the feed's own source, so adding an advertiser network
        // never touches this job.
        $connector = $registry->feed($feed->source);

        $tracker = IngestionJob::query()->firstOrCreate(
            ['job_key' => $feed->jobKey()],
            ['source' => $feed->source->value, 'market' => $feed->market->value],
        );

        $tracker->markRunning();

        $chunkSize = (int) config('giftcoves.connectors.awin.chunk_size');
        $buffer = [];
        $cursor = (array) ($tracker->cursor ?? []);
        $processed = (int) ($cursor['row'] ?? 0);
        $written = 0;
        $skipped = 0;

        /*
         * The run's own start, kept in the cursor so a resumed attempt keeps
         * it (2026-09-28).
         *
         * It used to be `now()` on every attempt. A run cut off by a deploy
         * resumed at row 40,000 with a new start time, skipped the first
         * 40,000 rows (the cursor said they were done) and then retired them
         * as "not seen since this run began": every offer the first attempt
         * had committed went stale. The start is written before the first
         * chunk, so it is there to find however early the run is cut off.
         */
        if (! isset($cursor['run_started_at'])) {
            // A run from the top: forget what the last run saw.
            DB::table('ingestion_seen_offers')->where('feed_id', $feed->id)->delete();

            $cursor = ['run_started_at' => now()->toIso8601String(), 'seen' => 0];
            $processed = 0;
            $tracker->update(['cursor' => $cursor, 'processed' => 0]);
        }

        $seen = (int) ($cursor['seen'] ?? 0);

        self::$stopRequested = false;
        $previousHandler = $this->listenForStop();

        try {
            foreach ($connector->stream($feed, $cursor) as $offer) {
                $buffer[] = $offer;

                if (count($buffer) < $chunkSize) {
                    continue;
                }

                $result = $upserter->upsert($buffer, $feed);
                $written += $result['written'];
                $skipped += $result['skipped'];
                $seen += $this->recordSeen($feed, $result['external_ids']);
                $processed += count($buffer);
                $buffer = [];

                // Recorded AFTER the chunk is committed, so a crash re-reads a
                // chunk rather than skipping one. Re-reading is harmless: the
                // writes are upserts, and so is the seen list.
                $tracker->update([
                    'cursor' => [
                        ...$connector->cursor(),
                        'run_started_at' => $cursor['run_started_at'],
                        'seen' => $seen,
                    ],
                    'processed' => $processed,
                    'total' => $connector->total(),
                ]);

                /*
                 * A deploy is stopping the worker. The chunk above is
                 * committed and its place recorded, so stop here and go back
                 * on the queue at once: the new Horizon picks the job up and
                 * resumes from the cursor. Without this the worker finished
                 * the whole feed or was killed at the grace period, and a
                 * killed job waited `retry_after` (65 minutes) to be retried.
                 */
                if (self::$stopRequested) {
                    $tracker->update(['status' => JobStatus::Pending]);
                    $this->release();

                    Log::info('Feed ingest paused for a restart', ['feed' => $feed->jobKey(), 'row' => $processed]);

                    return;
                }
            }

            if ($buffer !== []) {
                $result = $upserter->upsert($buffer, $feed);
                $written += $result['written'];
                $skipped += $result['skipped'];
                $seen += $this->recordSeen($feed, $result['external_ids']);
                $processed += count($buffer);
            }

            $this->markStaleProducts($feed, $seen);

            $tracker->update(['processed' => $processed]);
            $tracker->markCompleted();

            $feed->update([
                'last_run_at' => now(),
                'last_row_count' => $written,
                'last_error' => null,
            ]);

            Log::info('Feed ingested', [
                'feed' => $feed->jobKey(),
                'written' => $written,
                'skipped' => $skipped,
            ]);
        } catch (Throwable $e) {
            // The cursor is deliberately left where it is so a retry resumes
            // rather than restarting a partially-ingested feed.
            $tracker->markFailed($e->getMessage());
            $feed->update(['last_error' => mb_substr($e->getMessage(), 0, 500)]);

            throw $e;
        } finally {
            $this->stopListening($previousHandler);
        }
    }

    /**
     * Note a chunk's offers as listed in this run.
     *
     * `ON CONFLICT DO NOTHING`, so a chunk re-read after a crash adds nothing
     * twice. Returns how many were new, which the cursor keeps as a running
     * count; see markStaleProducts() for why.
     *
     * @param  list<string>  $externalIds
     */
    private function recordSeen(Feed $feed, array $externalIds): int
    {
        if ($externalIds === []) {
            return 0;
        }

        return DB::table('ingestion_seen_offers')->insertOrIgnore(array_map(
            fn (string $id) => ['feed_id' => $feed->id, 'external_id' => $id],
            array_values(array_unique($externalIds)),
        ));
    }

    /**
     * Retire rows this run did not see.
     *
     * Marked stale, never deleted: a wishlist item or a published guide may
     * still point at them, and a dead link is worse than an out-of-stock badge.
     * Only rows from this feed are touched, so one advertiser's outage cannot
     * retire another's catalogue.
     *
     * "Did not see" is an anti-join against `ingestion_seen_offers`, not a
     * date. Since the ingest writes only offers that changed, an unchanged
     * offer keeps yesterday's `last_seen_at`, and the old test (`last_seen_at`
     * before the run began) would retire every offer that did not change.
     *
     * The seen list is an UNLOGGED table, which Postgres empties after a crash.
     * `$recorded` is how many rows this run put there, counted in the cursor,
     * which is an ordinary logged row. If the table holds fewer, a crash has
     * emptied it mid-run, and retiring by it would retire offers the feed
     * still lists; so this run retires nothing, and the next one catches up.
     */
    private function markStaleProducts(Feed $feed, int $recorded): void
    {
        $present = DB::table('ingestion_seen_offers')->where('feed_id', $feed->id)->count();

        if ($present < $recorded) {
            Log::warning('Feed ingest: the seen list is shorter than recorded; retiring nothing this run', [
                'feed' => $feed->jobKey(),
                'recorded' => $recorded,
                'present' => $present,
            ]);
        } else {
            DB::statement(
                <<<'SQL'
                    UPDATE products p
                    SET status = ?, updated_at = now()
                    WHERE p.feed_id = ?
                      AND p.status = ?
                      AND NOT EXISTS (
                          SELECT 1 FROM ingestion_seen_offers s
                          WHERE s.feed_id = p.feed_id AND s.external_id = p.external_id
                      )
                SQL,
                [ProductStatus::Stale->value, $feed->id, ProductStatus::Active->value],
            );
        }

        // Kept for a run's length only.
        DB::table('ingestion_seen_offers')->where('feed_id', $feed->id)->delete();
    }

    /**
     * Set by the SIGTERM handler below, read between chunks.
     *
     * Static rather than a property: a property would be serialised into the
     * job payload, and a job released with the flag set would stop again at
     * its first chunk after the restart.
     */
    private static bool $stopRequested = false;

    /**
     * Hear a deploy's SIGTERM without taking it from the worker.
     *
     * The queue worker installs its own SIGTERM handler, which lets the
     * current job finish and then exits. This one is installed on top for the
     * length of the job and calls the worker's too, so the worker still quits
     * afterwards; the job only learns to stop at the next chunk boundary.
     * No-op where pcntl is missing (Windows, where the dev stack runs).
     *
     * @return callable|int|null the handler to put back
     */
    private function listenForStop(): callable|int|null
    {
        if (! function_exists('pcntl_signal') || ! function_exists('pcntl_signal_get_handler')) {
            return null;
        }

        $previous = pcntl_signal_get_handler(SIGTERM);

        pcntl_signal(SIGTERM, function (int $signal, mixed $info = null) use ($previous): void {
            self::$stopRequested = true;

            if (is_callable($previous)) {
                $previous($signal, $info);
            }
        });

        return $previous;
    }

    private function stopListening(callable|int|null $previous): void
    {
        if ($previous === null || ! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_signal(SIGTERM, $previous);
    }

    /** For tests: behave as if the worker had been sent SIGTERM. */
    public static function requestStop(): void
    {
        self::$stopRequested = true;
    }

    public function failed(Throwable $e): void
    {
        /*
         * The tracker is keyed on `Feed::jobKey()` — `source:external_id:
         * market` — and this used to match `%:{feedId}:%` against it. The feed
         * id is the primary key, which never appears in that string, so a
         * permanently failed run marked nothing as failed; or, where some
         * advertiser's external id happened to equal this feed's internal
         * one, marked the wrong feed's tracker.
         */
        $feed = Feed::query()->find($this->feedId);

        if ($feed === null) {
            return;
        }

        IngestionJob::query()
            ->where('job_key', $feed->jobKey())
            ->update(['status' => JobStatus::Failed->value, 'last_error' => mb_substr($e->getMessage(), 0, 500)]);
    }
}

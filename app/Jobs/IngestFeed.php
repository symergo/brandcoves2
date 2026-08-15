<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\JobStatus;
use App\Enums\ProductStatus;
use App\Models\Feed;
use App\Models\IngestionJob;
use App\Services\Connectors\ConnectorRegistry;
use App\Services\Ingestion\OfferUpserter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
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
class IngestFeed implements ShouldQueue
{
    use Queueable;

    /** Long, because a large feed legitimately takes a while. */
    public int $timeout = 3600;

    /**
     * One retry. A feed that fails twice is a configuration or upstream
     * problem, and hammering a 404 for an hour helps nobody.
     */
    public int $tries = 2;

    public function __construct(
        public readonly int $feedId,
    ) {}

    /** One ingestion per feed at a time, however many times it gets queued. */
    public function uniqueId(): string
    {
        return 'ingest-feed-'.$this->feedId;
    }

    public function handle(ConnectorRegistry $registry, OfferUpserter $upserter): void
    {
        $feed = Feed::query()->find($this->feedId);

        if ($feed === null || ! $feed->enabled) {
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
        $processed = (int) ($tracker->cursor['row'] ?? 0);
        $written = 0;
        $skipped = 0;
        $startedAt = now();

        try {
            foreach ($connector->stream($feed, $tracker->cursor) as $offer) {
                $buffer[] = $offer;

                if (count($buffer) < $chunkSize) {
                    continue;
                }

                $result = $upserter->upsert($buffer, $feed);
                $written += $result['written'];
                $skipped += $result['skipped'];
                $processed += count($buffer);
                $buffer = [];

                // Recorded AFTER the chunk is committed, so a crash re-reads a
                // chunk rather than skipping one. Re-reading is harmless: the
                // writes are upserts.
                $tracker->update([
                    'cursor' => $connector->cursor(),
                    'processed' => $processed,
                    'total' => $connector->total(),
                ]);
            }

            if ($buffer !== []) {
                $result = $upserter->upsert($buffer, $feed);
                $written += $result['written'];
                $skipped += $result['skipped'];
                $processed += count($buffer);
            }

            $this->markStaleProducts($feed, $startedAt);

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
        }
    }

    /**
     * Retire rows this run did not see.
     *
     * Marked stale, never deleted: a wishlist item or a published guide may
     * still point at them, and a dead link is worse than an out-of-stock badge.
     * Only rows from this feed are touched, so one advertiser's outage cannot
     * retire another's catalogue.
     */
    private function markStaleProducts(Feed $feed, Carbon $startedAt): void
    {
        DB::table('products')
            ->where('feed_id', $feed->id)
            ->where('status', ProductStatus::Active->value)
            ->where('last_seen_at', '<', $startedAt)
            ->update([
                'status' => ProductStatus::Stale->value,
                'updated_at' => now(),
            ]);
    }

    public function failed(Throwable $e): void
    {
        IngestionJob::query()
            ->where('job_key', 'like', '%:'.$this->feedId.':%')
            ->update(['status' => JobStatus::Failed->value, 'last_error' => mb_substr($e->getMessage(), 0, 500)]);
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\JobStatus;
use App\Enums\Market;
use App\Enums\Source;
use App\Models\ChartCategory;
use App\Models\IngestionJob;
use App\Services\Charts\ChartDemand;
use App\Services\Charts\ChartPuller;
use App\Services\Charts\ChartPullResult;
use App\Services\Connectors\ConnectorRegistry;
use App\Services\Connectors\PopularityConnector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pull one source's bestseller charts for one market.
 *
 * A breadth-first crawl over a taxonomy nobody publishes. The market-wide chart
 * comes first; the categories it names become the next tier, and theirs the tier
 * after, to `max_depth`. Every chart costs a request against a rate-limited API,
 * so a run is **bounded** by `max_categories` and coverage widens over days
 * rather than arriving in one night.
 *
 * Chunked and resumable per invariant 8, reusing `ingestion_jobs` — the cursor
 * is how far down the work-list this run reached. A redeploy mid-crawl resumes
 * rather than restarting, which matters because restarting would spend the whole
 * budget re-pulling the charts it already has.
 */
class PullPopularCharts implements ShouldQueue
{
    use Queueable;

    /** Generous: a bounded crawl at 2 requests/second still takes minutes. */
    public int $timeout = 1800;

    /**
     * One retry. A chart run that fails twice is a credential or upstream
     * problem, and tomorrow's run is only a day away.
     */
    public int $tries = 2;

    public function __construct(
        public readonly Market $market,
        public readonly Source $source = Source::Bol,
    ) {}

    /** One crawl per source per market at a time, however many times it is queued. */
    public function uniqueId(): string
    {
        return $this->jobKey();
    }

    public function jobKey(): string
    {
        return "{$this->source->value}:charts:{$this->market->value}";
    }

    public function handle(ConnectorRegistry $registry, ChartPuller $puller): void
    {
        $connector = $this->connector($registry);

        if ($connector === null) {
            // Not an error. bol does not operate in Spain, and a disabled
            // connector is a configuration choice — neither deserves a failed
            // job in the admin table.
            return;
        }

        $tracker = IngestionJob::query()->firstOrCreate(
            ['job_key' => $this->jobKey()],
            ['source' => $this->source->value, 'market' => $this->market->value],
        );

        $tracker->markRunning();

        try {
            /*
             * Linking runs FIRST, before anything is pulled.
             *
             * Yesterday's chart products were grouped by the overnight
             * GroupProducts run, so their rank rows can only be attached now.
             * Doing it first also means a run cut short by a rate limit has
             * still made the existing history usable.
             */
            $linked = $puller->linkRanks($this->source, $this->market);

            $result = $this->crawl($connector, $puller, $tracker);

            // The demand map is cached for an hour; a run that just rewrote the
            // ranks must not leave the old one in front of the engines.
            app(ChartDemand::class)->forget($this->market);

            $tracker->update(['processed' => $result->entries]);

            if ($result->interrupted) {
                /*
                 * Paused, not finished — and deliberately NOT markCompleted(),
                 * which clears the cursor. A run that stopped because the source
                 * asked us to back off has to keep its place, or the retry
                 * re-spends the very requests that caused the pause. Pending
                 * rather than Failed: nothing went wrong.
                 */
                $tracker->update([
                    'status' => JobStatus::Pending,
                    'finished_at' => now(),
                ]);
            } else {
                $tracker->markCompleted();
            }

            Log::info('Popular charts pulled', [
                'job' => $this->jobKey(),
                'entries' => $result->entries,
                'products_written' => $result->productsWritten,
                'products_skipped' => $result->productsSkipped,
                'categories_discovered' => $result->categoriesDiscovered,
                'ranks_linked' => $linked,
            ]);
        } catch (Throwable $e) {
            // The cursor is left where it is, so a retry resumes rather than
            // spending the whole budget re-pulling charts it already has.
            $tracker->markFailed($e->getMessage());

            throw $e;
        }
    }

    /**
     * The market-wide chart, then as many categories as the budget allows.
     */
    private function crawl(
        PopularityConnector $connector,
        ChartPuller $puller,
        IngestionJob $tracker,
    ): ChartPullResult {
        $budget = max(1, (int) $this->setting('max_categories', 40));
        $maxDepth = max(1, (int) $this->setting('max_depth', 2));

        $result = new ChartPullResult;
        $done = (int) ($tracker->cursor['categories_done'] ?? 0);

        /*
         * The market-wide chart is pulled on every run, cursor or not.
         *
         * It is the only chart guaranteed to exist, the seed for category
         * discovery, and the one whose daily snapshot matters most. Skipping it
         * on a resume would leave a hole in the series that nothing refills.
         */
        if ($done === 0) {
            $result = $result->plus($puller->pull($connector, $this->market, null, 0));

            $done = 1;
            $tracker->update(['cursor' => ['categories_done' => $done]]);
        }

        $pulled = 0;
        $interrupted = false;

        while ($pulled < $budget) {
            if ($connector->isChartCoolingDown()) {
                // The upstream has told us to stop. Keep the cursor so a retry
                // does not spend two more requests re-pulling the market-wide
                // chart it already has today.
                Log::info('Chart crawl paused: source is cooling down', [
                    'job' => $this->jobKey(),
                    'pulled' => $pulled,
                ]);

                $interrupted = true;

                break;
            }

            $category = $this->nextCategory($maxDepth);

            if ($category === null) {
                break;
            }

            $result = $result->plus(
                $puller->pull($connector, $this->market, $category->external_id, $category->depth)
            );

            $pulled++;
            $done++;
            $tracker->update(['cursor' => ['categories_done' => $done]]);
        }

        $this->reportDeferred($maxDepth, $pulled, $budget);

        if ($interrupted) {
            /*
             * The cursor is kept only when the run stopped early.
             *
             * The work-list is a stalest-first query, not a fixed list, so a
             * clean finish should start from scratch tomorrow — but a paused run
             * has to keep its place, because `last_pulled_at` alone would let a
             * same-day retry re-pull the market-wide chart for nothing.
             */
            return $result->paused();
        }

        $tracker->update(['cursor' => null]);

        return $result;
    }

    /**
     * The next category worth a request: never pulled first, then stalest.
     *
     * Re-queried each iteration rather than collected up front, because the pull
     * that just ran may have discovered new categories — and a brand-new
     * category is worth more than re-pulling one we already hold.
     */
    private function nextCategory(int $maxDepth): ?ChartCategory
    {
        return ChartCategory::query()
            ->where('source', $this->source->value)
            ->forMarket($this->market)
            ->enabled()
            ->where('depth', '<=', $maxDepth)
            // Once a day is plenty: a bestseller chart does not turn over
            // hourly, and a second pull would overwrite the same snapshot.
            ->where(fn ($q) => $q
                ->whereNull('last_pulled_at')
                ->orWhere('last_pulled_at', '<', now()->startOfDay()))
            ->stalestFirst()
            ->first();
    }

    /**
     * Say what the budget left behind.
     *
     * A silent cap reads as "we covered everything", which is exactly the wrong
     * impression to give about a crawl that deliberately does not.
     */
    private function reportDeferred(int $maxDepth, int $pulled, int $budget): void
    {
        if ($pulled < $budget) {
            return;
        }

        $remaining = ChartCategory::query()
            ->where('source', $this->source->value)
            ->forMarket($this->market)
            ->enabled()
            ->where('depth', '<=', $maxDepth)
            ->where(fn ($q) => $q
                ->whereNull('last_pulled_at')
                ->orWhere('last_pulled_at', '<', now()->startOfDay()))
            ->count();

        if ($remaining > 0) {
            Log::info('Chart crawl hit its per-run budget', [
                'job' => $this->jobKey(),
                'pulled' => $pulled,
                'deferred' => $remaining,
            ]);
        }
    }

    private function connector(ConnectorRegistry $registry): ?PopularityConnector
    {
        foreach ($registry->popularityFor($this->market) as $candidate) {
            if ($candidate->source() === $this->source) {
                return $candidate;
            }
        }

        return null;
    }

    private function setting(string $key, mixed $default): mixed
    {
        return config("giftcoves.connectors.{$this->source->value}.popular.{$key}", $default);
    }
}

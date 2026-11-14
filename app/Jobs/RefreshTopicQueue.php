<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Market;
use App\Services\Guides\SeasonalTopics;
use App\Services\Guides\TopicMiner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Facades\Log;

/**
 * Refresh one market's guide-topic queue: mine the search log, then seed the
 * seasonal topics in season now.
 *
 * The "Refresh queue" button on the guide-topics screen dispatches one per
 * market. It used to run both passes for all five markets inside the web
 * request, which is a search-log mine plus an available-products count per
 * candidate topic, five times over, while the admin waited on a spinner. Work
 * that size belongs on the queue, where a slow market delays nobody.
 *
 * Both passes are idempotent and never overturn a decision made on the
 * screen, so a double click or a retry costs time, not correctness.
 *
 * Since 2026-11-14 the schedule runs it too, every morning after the Daily
 * builds. Until then the mining was the first step of `BuildDailyEdition`,
 * and a slow count there cost the day's Cove (see that job).
 */
#[Queue('editorial')]
class RefreshTopicQueue implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    /** Once: a run that ran out of time has already saved every topic it reached. */
    public int $tries = 1;

    /** One per market at a time; released after the timeout at the latest. */
    public int $uniqueFor = 900;

    public function uniqueId(): string
    {
        return $this->market->value;
    }

    public function __construct(public Market $market) {}

    public function handle(TopicMiner $miner, SeasonalTopics $seasonal): void
    {
        // The calendar first: it is quick, and it must not wait behind a mine
        // that runs out of time.
        $inSeason = $seasonal->seed($this->market);
        $candidates = $miner->mine($this->market);

        Log::info('Topic queue refreshed', [
            'market' => $this->market->value,
            'topic_candidates' => $candidates,
            'topics_in_season' => $inSeason,
        ]);
    }
}

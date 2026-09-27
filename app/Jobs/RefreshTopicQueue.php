<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Market;
use App\Services\Guides\SeasonalTopics;
use App\Services\Guides\TopicMiner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;

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
 */
#[Queue('editorial')]
class RefreshTopicQueue implements ShouldQueue
{
    use Queueable;

    public function __construct(public Market $market) {}

    public function handle(TopicMiner $miner, SeasonalTopics $seasonal): void
    {
        $miner->mine($this->market);
        $seasonal->seed($this->market);
    }
}

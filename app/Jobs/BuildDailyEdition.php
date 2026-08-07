<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Market;
use App\Services\Cove\EditionBuilder;
use App\Services\Guides\TopicMiner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Builds one market's Daily Cove edition.
 *
 * The only place the daily theme and the guide's editorial copy touch a model,
 * and it is a queued job under a daily cap — the AI invariant holding in the
 * feature most tempting to violate it, because "generate a theme for today"
 * reads like something a page could do on demand. It cannot: that would put
 * model latency and model cost on every visitor, seven markets over.
 */
class BuildDailyEdition implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 2;

    public function __construct(public Market $market) {}

    public function handle(TopicMiner $miner, EditionBuilder $builder): void
    {
        // Mine first: the edition asks for the ripest topic, and yesterday's
        // searches are what ripen one.
        $candidates = $miner->mine($this->market);

        $edition = $builder->build($this->market);

        Log::info('Daily Cove built', [
            'market' => $this->market->value,
            'topic_candidates' => $candidates,
            'edition' => $edition?->id,
            'picks' => $edition?->picks()->count() ?? 0,
            'has_challenge' => $edition?->challenge_group_id !== null,
            'has_guide' => $edition?->guide_id !== null,
        ]);
    }
}

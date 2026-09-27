<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Market;
use App\Services\Ingestion\CatalogueRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;

/**
 * The hand-over from one market to the next in the catalogue run.
 *
 * The last link of a market's chain, and what the chain's `catch` dispatches
 * when a step failed, so exactly one of the two starts the next market. A job
 * rather than a closure so the hand-over shows up in Horizon by name. See
 * App\Services\Ingestion\CatalogueRun.
 */
#[Queue('batch')]
class ContinueCatalogueRun implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    /** @param list<Market> $markets the markets still to do */
    public function __construct(
        public readonly array $markets,
        public readonly bool $morning,
    ) {}

    public function handle(): void
    {
        CatalogueRun::start($this->markets, $this->morning);
    }
}

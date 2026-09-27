<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * One run at a time per key, decided when the job RUNS, not when it is queued.
 *
 * For the steps of the nightly catalogue run (App\Services\Ingestion\
 * CatalogueRun), which must not overlap a second copy of themselves (two
 * groupings of one market fight over the same rows) and must not be
 * `ShouldBeUnique` either. Laravel checks uniqueness when the next job of a
 * chain is dispatched, and a job it refuses there is dropped WITH the rest of
 * the chain: one "Group now" pressed in the admin at the wrong moment would
 * have silently cancelled grouping, classification and brand statistics for
 * every market still to come that night.
 *
 * Here the second copy starts, finds the lock taken, and is deleted without
 * running (`dontRelease`): the copy holding the lock is doing the same work.
 * A job deleted this way still counts as done, so a chain moves on to its
 * next step.
 *
 * The lock expires a minute after the job's own timeout, so a worker killed
 * outright cannot hold it until the next night.
 */
trait RunsOneAtATime
{
    /** What must not run twice at once, e.g. the market. */
    abstract protected function overlapKey(): string;

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->overlapKey()))
                ->dontRelease()
                ->expireAfter(($this->timeout ?? 900) + 60),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Market;
use App\Services\Identity\MatchFinder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Facades\Log;

/**
 * Propose products that may be one, for a person to confirm.
 *
 * Runs after GroupProducts, because it reads the groups and their titles as the
 * grouper leaves them. Writes `match_candidates` only: nothing here merges.
 * See App\Services\Identity\MatchFinder for the rules and
 * docs/features/match-review.md for why every pair goes to a person.
 */
#[Queue('batch')]
class FindMatchCandidates implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    /**
     * @param  bool  $full  compare every product's title, not only the ones
     *                      first seen recently. For the first run on an
     *                      environment; the nightly run is incremental.
     */
    public function __construct(
        public readonly Market $market,
        public readonly bool $full = false,
    ) {}

    public function uniqueId(): string
    {
        return 'find-match-candidates-'.$this->market->value;
    }

    public function handle(MatchFinder $finder): void
    {
        $found = $finder->run($this->market, $this->full);

        Log::info('Match candidates found', ['market' => $this->market->value, 'full' => $this->full, ...$found]);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Cove;

use App\Models\CovePlan;

/**
 * What a run of {@see PlanDrafter} produced, and why it produced no more.
 *
 * The shortfall is the reason this is an object rather than a list. Asking for
 * twenty personas in a market that has four interests left unplanned is not an
 * error and must not read as one — but "4 drafted" with no explanation reads as
 * a bug, and the next thing anyone does is press the button again. The sentence
 * is written where the exhaustion is discovered, because that is the only place
 * that knows which source ran dry.
 */
final readonly class DraftedPlans
{
    /**
     * @param  list<CovePlan>  $plans
     * @param  int  $suggested  products pre-filled onto those plans, across all of them
     * @param  string|null  $shortfall  why the run stopped short, in a sentence, or null if it did not
     */
    public function __construct(
        public array $plans = [],
        public int $suggested = 0,
        public ?string $shortfall = null,
    ) {}

    /** Nothing could be drafted at all, and here is why. */
    public static function none(string $shortfall): self
    {
        return new self(shortfall: $shortfall);
    }

    public function count(): int
    {
        return count($this->plans);
    }

    /** @return list<int> */
    public function ids(): array
    {
        return array_map(fn (CovePlan $plan) => $plan->id, $this->plans);
    }
}

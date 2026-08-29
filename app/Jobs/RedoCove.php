<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CovePlan;
use App\Services\Cove\EditionBuilder;
use App\Services\Cove\RedoOptions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Redo one Cove: new products, a newly written article, same URL.
 *
 * Queued for the same reason every build is — the writing pass calls a model,
 * and nothing a request can reach may do that. Invariant 1.
 *
 * `tries = 1`, unlike every other build job. A redo is not idempotent by design:
 * each run deliberately excludes what is currently on the page and produces
 * something different, so a retry after a partial failure would be a second
 * redo, not a resumption of the first. Better to leave the Cove as it stands and
 * have somebody press the button again knowing what happened.
 */
class RedoCove implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(
        public int $planId,
        public bool $reselect = true,
    ) {}

    public function handle(EditionBuilder $builder): void
    {
        $plan = CovePlan::query()->find($this->planId);

        if ($plan === null) {
            Log::info('Redo skipped: plan is gone', ['plan' => $this->planId]);

            return;
        }

        $edition = $builder->redo(
            $plan,
            $this->reselect ? RedoOptions::reselect() : RedoOptions::rewrite(),
        );

        Log::info('Cove redone', [
            'plan' => $plan->id,
            'kind' => $plan->kind->value,
            'reselect' => $this->reselect,
            'edition' => $edition?->id,
            'picks' => $edition?->picks()->count() ?? 0,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CovePlan;
use App\Services\Cove\EditionBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Builds one gift persona from its plan.
 *
 * Its own job rather than a flag on BuildDailyEdition, because that job does
 * two things a persona has no use for: it mines yesterday's searches for guide
 * topics and it seeds the seasonal ones. Both are about the day, and a persona
 * has no day — running them here would make an editor pressing "build" also
 * advance the guide queue, which is not what the button says.
 *
 * Still a queued job, and for the same reason as every other build: the
 * editorial pass calls a model, and nothing a request can reach may do that.
 */
class BuildPersonaCove implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 2;

    public function __construct(public int $planId) {}

    public function handle(EditionBuilder $builder): void
    {
        $plan = CovePlan::query()->find($this->planId);

        if ($plan === null) {
            // Deleted between the button and the worker. Nothing to build and
            // nothing wrong.
            Log::info('Persona build skipped: plan is gone', ['plan' => $this->planId]);

            return;
        }

        $edition = $builder->buildPersona($plan);

        Log::info('Gift persona built', [
            'plan' => $plan->id,
            'market' => $plan->market->value,
            'slug' => $plan->slug,
            'edition' => $edition?->id,
            'picks' => $edition?->picks()->count() ?? 0,
        ]);
    }
}

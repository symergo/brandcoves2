<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CovePlan;
use App\Services\Cove\EditionBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Build one planned Cove, whatever kind it is.
 *
 * Replaces the branch every caller used to carry — `isPersona() ? A : B` in the
 * curation screen, the resource, the API controller and the console command,
 * four copies of one decision that had to grow a third arm each time a kind was
 * added. The kind is on the plan; the plan is all a caller should need to name.
 *
 * Still a queued job, and for the same reason every build is: the editorial pass
 * calls a model, and nothing a request can reach may do that. Invariant 1.
 *
 * A Daily is the one kind this does not schedule. `BuildDailyEdition` remains
 * the 06:00 entry point because it does two things about the *day* rather than
 * about a plan — mining yesterday's searches for topics, and seeding the
 * seasonal ones — and an editor pressing "build" on next Tuesday should not
 * advance the topic queue.
 */
class BuildCove implements ShouldQueue
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
            Log::info('Cove build skipped: plan is gone', ['plan' => $this->planId]);

            return;
        }

        $edition = match (true) {
            $plan->kind->isArticle() => $builder->buildArticle($plan),
            $plan->isPersona() => $builder->buildPersona($plan),
            // A Daily is addressed by its date, and it is the plan's own date
            // that matters: dispatching without one built today's edition from a
            // plan written for next Tuesday, so the button appeared to do
            // nothing at all.
            default => $plan->drop_date === null
                ? null
                : $builder->build($plan->market, $plan->drop_date->toImmutable()),
        };

        Log::info('Cove built', [
            'plan' => $plan->id,
            'kind' => $plan->kind->value,
            'market' => $plan->market->value,
            'edition' => $edition?->id,
            'picks' => $edition?->picks()->count() ?? 0,
        ]);
    }
}

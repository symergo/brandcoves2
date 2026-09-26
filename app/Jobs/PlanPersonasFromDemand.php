<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Market;
use App\Services\Cove\PersonaDemandPlanner;
use App\Services\Gift\GiftSearchDemand;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Draft gift personas from tonight's search demand, for one market.
 *
 * The decisions are PersonaDemandPlanner's; this runs it and trims the
 * demand counts past a year. Drafts only, never an approval or a build, and
 * no AI. See docs/features/persona-demand.md.
 */
class PlanPersonasFromDemand implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $uniqueFor = 3600;

    public function __construct(public Market $market) {}

    public function uniqueId(): string
    {
        return $this->market->value;
    }

    public function handle(PersonaDemandPlanner $planner, GiftSearchDemand $demand): void
    {
        $result = $planner->plan($this->market);
        $pruned = $demand->prune();

        Log::info('Personas drafted from search demand', [
            'market' => $this->market->value,
            'drafted' => array_column($result['drafted'], 'slug'),
            'skipped' => $result['skipped'],
            'pruned' => $pruned,
        ]);
    }
}

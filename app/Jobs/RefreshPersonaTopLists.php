<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Market;
use App\Jobs\Concerns\RunsOneAtATime;
use App\Models\DailyPickSet;
use App\Services\Gift\PersonaTopTen;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Facades\Log;

/**
 * This week's top 10 for every published persona in one market.
 *
 * One engine run and two small aggregate queries per persona, no AI. Weekly,
 * on Monday. See App\Services\Gift\PersonaTopTen and
 * docs/features/persona-top-ten.md.
 */
#[Queue('batch')]
class RefreshPersonaTopLists implements ShouldQueue
{
    use Queueable, RunsOneAtATime;

    public int $timeout = 1800;

    public function __construct(public Market $market) {}

    protected function overlapKey(): string
    {
        return $this->market->value;
    }

    /** @return array{personas: int, lists: int} */
    public function handle(PersonaTopTen $top): array
    {
        $personas = DailyPickSet::query()
            ->forMarket($this->market)
            ->personas()
            ->published()
            ->with('plan', 'picks.group')
            ->get();

        $lists = 0;

        foreach ($personas as $persona) {
            if ($top->refresh($persona) !== []) {
                $lists++;
            }
        }

        $result = ['personas' => $personas->count(), 'lists' => $lists];

        Log::info('Persona top lists refreshed', ['market' => $this->market->value, ...$result]);

        return $result;
    }
}

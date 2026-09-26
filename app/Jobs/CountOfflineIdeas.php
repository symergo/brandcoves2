<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Ideas\OfflineIdeaCounter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Once a night: what people typed onto their lists by hand, counted, and
 * proposed as gift ideas once enough different people wrote the same thing.
 * Nothing is shown until a person approves it. See OfflineIdeaCounter and
 * docs/features/offline-ideas.md.
 */
class CountOfflineIdeas implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $uniqueFor = 3600;

    public function handle(OfflineIdeaCounter $counter): void
    {
        Log::info('offline ideas counted', $counter->run());
    }
}

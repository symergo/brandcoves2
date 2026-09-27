<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Market;
use App\Jobs\Concerns\RunsOneAtATime;
use App\Services\Gift\GiftLandingPlanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Record tonight's gift landing pages for one market.
 *
 * The decisions are GiftLandingPlanner's; this only runs it and clears the
 * sitemap, which lists the pages. About 400 engine runs per market, each a few
 * database queries and no AI. See docs/features/gift-landing-pages.md.
 *
 * A step of the morning catalogue run since 2026-09-28, after the market's
 * brand statistics. One run per market at a time, checked when it runs rather
 * than with ShouldBeUnique; see RunsOneAtATime.
 */
#[Queue('batch')]
class PlanGiftLandingPages implements ShouldQueue
{
    use Queueable, RunsOneAtATime;

    public int $timeout = 1800;

    public function __construct(public Market $market) {}

    protected function overlapKey(): string
    {
        return $this->market->value;
    }

    public function handle(GiftLandingPlanner $planner): void
    {
        $result = $planner->plan($this->market);

        // The sitemap is cached for an hour; a page that opened or closed
        // tonight should be listed, or dropped, by the morning crawl.
        Cache::forget("bc:sitemap:{$this->market->value}:1");

        Log::info('Gift landing pages planned', ['market' => $this->market->value, ...$result]);
    }
}

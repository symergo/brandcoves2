<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Market;
use App\Http\Controllers\SitemapController;
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

    /**
     * An hour, the most the `batch` queue allows (config/horizon.php). It was
     * half an hour, and on 2026-11-15 three of the four markets ran out of it:
     * about 400 engine runs, each then four to five seconds. The engine's
     * query is three to four times faster since that day, so this is room,
     * not the fix.
     */
    public int $timeout = 3600;

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
        Cache::forget(SitemapController::cacheKey($this->market));

        Log::info('Gift landing pages planned', ['market' => $this->market->value, ...$result]);
    }
}

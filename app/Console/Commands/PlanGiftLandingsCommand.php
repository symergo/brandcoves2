<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Market;
use App\Jobs\PlanGiftLandingPages;
use App\Models\GiftLanding;
use Illuminate\Console\Command;

/**
 * Record the gift landing pages now, rather than at 05:40.
 *
 * For a new environment, where no landing page exists until the first run,
 * and for checking what a market would get. Runs inline; no AI is involved.
 * See docs/features/gift-landing-pages.md.
 */
class PlanGiftLandingsCommand extends Command
{
    protected $signature = 'bc:plan-gift-landings
        {--market= : One market (be-nl, be-fr, en, nl-nl); every published one by default}';

    protected $description = 'Record which gift landing pages (/gift-ideas/for/...) the catalogue can fill';

    public function handle(): int
    {
        $option = $this->option('market');
        $market = is_string($option) ? Market::tryFrom($option) : null;

        if (is_string($option) && $market === null) {
            $this->error("Unknown market \"{$option}\". Known: ".implode(', ', Market::values()));

            return self::FAILURE;
        }

        foreach ($market === null ? Market::published() : [$market] as $each) {
            PlanGiftLandingPages::dispatchSync($each);

            $pages = GiftLanding::query()->forMarket($each)->orderByDesc('product_count')->get();

            $this->info("{$each->value}: {$pages->count()} pages");

            foreach ($pages->take(10) as $page) {
                $this->line("  {$page->product_count}  {$page->path}");
            }
        }

        return self::SUCCESS;
    }
}

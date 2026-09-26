<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Market;
use App\Services\Cove\PersonaDemandPlanner;
use Illuminate\Console\Command;

/**
 * Draft gift personas from search demand now, rather than at 06:30.
 *
 * A dry run unless --write, like the other commands that change content.
 * Writes drafts only; a person approves them in the Cove planner. No AI.
 * See docs/features/persona-demand.md.
 */
class PlanDemandPersonasCommand extends Command
{
    protected $signature = 'bc:plan-demand-personas
        {--market= : One market (be-nl, be-fr, en, nl-nl); every published one by default}
        {--write : Write the drafts; without it, only say what would be drafted}';

    protected $description = 'Draft gift personas from what people search gifts for (drafts only)';

    public function handle(PersonaDemandPlanner $planner): int
    {
        $option = $this->option('market');
        $market = is_string($option) ? Market::tryFrom($option) : null;

        if (is_string($option) && $market === null) {
            $this->error("Unknown market \"{$option}\". Known: ".implode(', ', Market::values()));

            return self::FAILURE;
        }

        $write = (bool) $this->option('write');

        foreach ($market === null ? Market::published() : [$market] as $each) {
            $result = $planner->plan($each, dryRun: ! $write);

            $this->info($each->value.': '.count($result['drafted']).($write ? ' drafted' : ' would be drafted'));

            foreach ($result['drafted'] as $draft) {
                $this->line("  {$draft['searches']} searches on {$draft['days']} days  {$draft['slug']}  \"{$draft['title']}\"");
            }

            $this->line('  skipped: '.http_build_query($result['skipped'], '', ', '));
        }

        if (! $write) {
            $this->comment('Dry run. Add --write to create the drafts.');
        }

        return self::SUCCESS;
    }
}

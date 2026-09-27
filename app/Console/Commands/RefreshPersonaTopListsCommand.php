<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Market;
use App\Jobs\RefreshPersonaTopLists;
use App\Services\Gift\PersonaTopTen;
use Illuminate\Console\Command;

/**
 * Work out every persona's top 10 now, rather than on Monday.
 *
 * For a new environment, where no list exists until the first Monday, and
 * after publishing new personas. Runs inline; no AI is involved.
 * See docs/features/persona-top-ten.md.
 */
class RefreshPersonaTopListsCommand extends Command
{
    protected $signature = 'bc:refresh-persona-tops
        {--market= : One market (be-nl, be-fr, en, nl-nl); every published one by default}';

    protected $description = "Work out this week's top 10 for every published gift persona";

    public function handle(PersonaTopTen $top): int
    {
        $option = $this->option('market');
        $market = is_string($option) ? Market::tryFrom($option) : null;

        if (is_string($option) && $market === null) {
            $this->error("Unknown market \"{$option}\". Known: ".implode(', ', Market::values()));

            return self::FAILURE;
        }

        foreach ($market === null ? Market::published() : [$market] as $each) {
            $result = (new RefreshPersonaTopLists($each))->handle($top);

            $this->info("{$each->value}: {$result['lists']} of {$result['personas']} personas have a top 10 (".PersonaTopTen::MINIMUM.' products needed)');
        }

        return self::SUCCESS;
    }
}

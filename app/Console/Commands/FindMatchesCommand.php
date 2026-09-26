<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Market;
use App\Jobs\FindMatchCandidates;
use App\Services\Identity\MatchFinder;
use Illuminate\Console\Command;

/**
 * Fill the match review queue by hand.
 *
 * The scheduler runs the incremental pass after every grouping. This is for a
 * first run on an environment (`--full`, which compares every product's title
 * rather than only recent ones) and for measuring the rules on a scrubbed copy
 * of production before anyone decides a rule may merge without a person.
 * It proposes pairs only; nothing is merged.
 */
class FindMatchesCommand extends Command
{
    protected $signature = 'bc:find-matches
        {--market= : Limit to one market (be-nl, be-fr, en, es, nl-nl)}
        {--full : Compare every product, not only the ones first seen recently}
        {--sync : Run inline instead of queueing, and print what was found}';

    protected $description = 'Propose products that may be the same one, for the match review queue';

    public function handle(MatchFinder $finder): int
    {
        $market = $this->option('market');
        if ($market !== null && Market::tryFrom($market) === null) {
            $this->error("Unknown market \"{$market}\". Known: ".implode(', ', Market::values()));

            return self::FAILURE;
        }

        $markets = $market !== null ? [Market::from($market)] : Market::cases();
        $full = (bool) $this->option('full');

        foreach ($markets as $m) {
            if (! $this->option('sync')) {
                FindMatchCandidates::dispatch($m, $full);
                $this->line("→ {$m->value} queued");

                continue;
            }

            $found = $finder->run($m, $full);
            $this->line(sprintf(
                '→ %s: %d by barcode, %d by model number, %d by similar title',
                $m->value,
                $found['barcode'],
                $found['model'],
                $found['title'],
            ));
        }

        return self::SUCCESS;
    }
}

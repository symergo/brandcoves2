<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Market;
use App\Services\Gift\AutoTagger;
use Illuminate\Console\Command;

/**
 * Give unjudged products the interest their category implies.
 *
 * See App\Services\Gift\AutoTagger and docs/features/gift-tags.md.
 */
class AutoTagCommand extends Command
{
    protected $signature = 'bc:auto-tag
        {--market= : One market (be-nl, be-fr, en, nl-nl); every published one by default}
        {--write : Write the tags. Without it, only count what would change}
        {--rules : List the categories that imply an interest}';

    protected $description = 'Tag unjudged products with the interest most judged products in their category carry';

    public function handle(AutoTagger $tagger): int
    {
        $option = $this->option('market');
        $market = is_string($option) ? Market::tryFrom($option) : null;

        if (is_string($option) && $market === null) {
            $this->error("Unknown market \"{$option}\". Known: ".implode(', ', Market::values()));

            return self::FAILURE;
        }

        $write = (bool) $this->option('write');

        foreach ($market === null ? Market::published() : [$market] as $each) {
            if ($this->option('rules')) {
                foreach ($tagger->rules($each) as $category => $tags) {
                    $this->line("  {$category}: ".implode(', ', $tags));
                }
            }

            $result = $tagger->apply($each, $write);

            $this->info(sprintf(
                '%s: %d categories imply an interest; %d products %s, %d %s',
                $each->value,
                $result['rules'],
                $result['tagged'],
                $write ? 'tagged' : 'would be tagged',
                $result['cleared'],
                $write ? 'cleared' : 'would be cleared',
            ));
        }

        if (! $write) {
            $this->comment('Dry run. Add --write to tag.');
        }

        return self::SUCCESS;
    }
}

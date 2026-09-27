<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Models\DailyPickSet;
use App\Services\Cove\CoveProse;
use Illuminate\Console\Command;

/**
 * Store the rendered prose on Coves built before it was stored.
 *
 * Since the "Cove pages" speed work, `EditionBuilder` stores a Cove's prose as
 * the page shows it (`daily_pick_sets.rendered_prose`, see CoveProse). A Cove
 * built before that, or edited since outside the builder, renders its prose
 * on the page and caches that for a day, so the first visitor of the day pays
 * for it. This renders and stores them once.
 *
 * Only that one column is written, never the text and never `updated_at`, so
 * it is safe on a Cove a person edited and does not move a sitemap date. A dry
 * run unless `--write`. A Cove with a link that does not resolve yet (a guide
 * not published, a brand without a page) is reported and left to render live,
 * for the reason CoveProse gives.
 */
class StoreCoveProseCommand extends Command
{
    protected $signature = 'bc:store-cove-prose
        {--market= : Only this market. Every market by default}
        {--write : Store the prose. Without this it only reports}';

    protected $description = 'Store the rendered prose on published Coves that do not have it, or whose text changed since';

    public function handle(CoveProse $prose): int
    {
        $market = $this->option('market') !== null ? Market::tryFrom((string) $this->option('market')) : null;

        if ($this->option('market') !== null && $market === null) {
            $this->error('Unknown market: '.$this->option('market'));

            return self::FAILURE;
        }

        $write = (bool) $this->option('write');
        $stale = 0;
        $stored = 0;

        DailyPickSet::query()
            ->where('status', PublishStatus::Published->value)
            ->when($market !== null, fn ($q) => $q->where('market', $market->value))
            ->with('picks.group')
            ->orderBy('id')
            // A few hundred Coves per market, each with a dozen picks: in
            // pages, so the whole archive is never in memory at once.
            ->chunkById(100, function ($coves) use ($prose, $write, &$stale, &$stored): void {
                foreach ($coves as $cove) {
                    $current = is_array($cove->rendered_prose)
                        && ($cove->rendered_prose['print'] ?? null) === $prose->fingerprint($cove);

                    if ($current) {
                        continue;
                    }

                    $stale++;

                    if (! $write) {
                        $this->line(sprintf('%s %s %s: would render', $cove->market->value, $cove->kind->value, $cove->slug));

                        continue;
                    }

                    $prose->store($cove);

                    if ($cove->rendered_prose !== null) {
                        $stored++;
                    } else {
                        $this->line(sprintf('%s %s %s: left live (a link that may resolve later)', $cove->market->value, $cove->kind->value, $cove->slug));
                    }
                }
            });

        $this->info($write
            ? sprintf('%d Coves rendered, %d stored.', $stale, $stored)
            : sprintf('%d Coves would be rendered (dry run, add --write).', $stale));

        return self::SUCCESS;
    }
}

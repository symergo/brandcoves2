<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Models\DailyPickSet;
use App\Services\Cove\EntityLinks;
use Illuminate\Console\Command;

/**
 * Store the link list on shop and brand Coves built before it was stored.
 *
 * Since 2026-09-27 `EditionBuilder::buildArticle()` stores a shop or brand
 * Cove's link list (the categories its prose may link to) in
 * `daily_pick_sets.link_categories`, so the page never works it out (4.4 s for
 * bol.com on production). Coves built before that have null there and fall back
 * to a list cached for a day, which the first visitor of the day pays for. This
 * fills them in once, so no visitor pays (owner, 2026-09-27: yes to a backfill).
 *
 * Only the list is written, never the prose, so it is safe on a Cove a person
 * edited. A dry run unless `--write`; `--all` also recomputes lists already
 * stored, e.g. after a shop's range changed a lot.
 */
class StoreEntityLinksCommand extends Command
{
    protected $signature = 'bc:store-entity-links
        {--market= : Only this market. Every market by default}
        {--all : Also recompute Coves that already have a stored list}
        {--write : Store the lists. Without this it only reports}';

    protected $description = 'Store the link list on shop and brand Coves built before it was stored';

    public function handle(EntityLinks $links): int
    {
        $market = $this->option('market') !== null ? Market::tryFrom((string) $this->option('market')) : null;

        if ($this->option('market') !== null && $market === null) {
            $this->error('Unknown market: '.$this->option('market'));

            return self::FAILURE;
        }

        $write = (bool) $this->option('write');

        $coves = DailyPickSet::query()
            ->whereIn('kind', [CoveKind::Shop->value, CoveKind::Brand->value])
            ->when($market !== null, fn ($q) => $q->where('market', $market->value))
            ->when(! $this->option('all'), fn ($q) => $q->whereNull('link_categories'))
            ->orderBy('id')
            ->get();

        foreach ($coves as $cove) {
            $list = $links->compute($cove->kind, $cove->market, (string) $cove->slug);

            $this->line(sprintf('%s %s %s: %d categories', $cove->market->value, $cove->kind->value, $cove->slug, count($list)));

            if ($write) {
                // Only the one column: the prose and everything else stay as they are.
                $cove->forceFill(['link_categories' => $list])->saveQuietly();
            }
        }

        $this->info(sprintf('%d Coves %s.', $coves->count(), $write ? 'updated' : 'would be updated (dry run, add --write)'));

        return self::SUCCESS;
    }
}

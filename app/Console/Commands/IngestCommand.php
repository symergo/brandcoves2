<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Market;
use App\Jobs\GroupProducts;
use App\Jobs\IngestFeed;
use App\Models\Feed;
use App\Models\IngestionJob;
use Illuminate\Console\Command;

/**
 * Trigger ingestion by hand.
 *
 * The scheduler does this hourly; this exists for the first run of a new feed,
 * for reproducing a bad ingest, and for the Phase 1 verification in the plan.
 */
class IngestCommand extends Command
{
    protected $signature = 'bc:ingest
        {--market= : Limit to one market (be-nl, be-fr, en, es, nl-nl)}
        {--feed= : Limit to one feed id}
        {--sync : Run inline instead of queueing, so you can watch it}
        {--fresh : Discard any saved cursor and ingest from the top}';

    protected $description = 'Ingest product feeds into the catalogue';

    public function handle(): int
    {
        $market = $this->option('market');
        if ($market !== null && Market::tryFrom($market) === null) {
            $this->error("Unknown market \"{$market}\". Known: ".implode(', ', Market::values()));

            return self::FAILURE;
        }

        $feeds = Feed::query()
            ->enabled()
            ->when($market, fn ($q) => $q->where('market', $market))
            ->when($this->option('feed'), fn ($q, $id) => $q->where('id', $id))
            ->get();

        if ($feeds->isEmpty()) {
            $this->warn('No enabled feeds match. Add one in the admin panel first.');

            return self::SUCCESS;
        }

        if ($this->option('fresh')) {
            IngestionJob::query()
                ->whereIn('job_key', $feeds->map->jobKey()->all())
                ->update(['cursor' => null, 'processed' => 0]);
            $this->line('Cursors cleared.');
        }

        foreach ($feeds as $feed) {
            $this->line("→ {$feed->label} ({$feed->jobKey()})");

            $this->option('sync')
                ? IngestFeed::dispatchSync($feed->id)
                : IngestFeed::dispatch($feed->id);
        }

        // Grouping is per market and runs once after ingestion, not per chunk:
        // a group's cheapest offer computed from a half-loaded catalogue would
        // simply be wrong.
        foreach ($feeds->pluck('market')->unique() as $feedMarket) {
            $this->option('sync')
                ? GroupProducts::dispatchSync($feedMarket)
                : GroupProducts::dispatch($feedMarket);
        }

        $this->info($this->option('sync')
            ? 'Ingestion complete.'
            : "Queued {$feeds->count()} feed(s). Watch progress in the admin panel.");

        return self::SUCCESS;
    }
}

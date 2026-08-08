<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Market;
use App\Jobs\BuildDailyEdition;
use App\Jobs\ClassifyGiftability;
use App\Jobs\RefreshBrandStats;
use App\Jobs\ScoreSerendipity;
use Illuminate\Console\Command;

/**
 * Run the discovery pipeline by hand.
 *
 * The three jobs behind every discovery surface run on a schedule, which means
 * a fresh deploy has empty surfaces until the next window — up to twelve hours
 * of a site that looks broken to anyone checking it. This is how you fill them
 * now.
 *
 * Order matters and is enforced rather than documented: serendipity's quality
 * gate reads the giftability verdict, and the edition builder reads the
 * serendipity scores. Running them out of order produces a plausible-looking
 * result computed from stale inputs, which is worse than an obvious failure.
 */
class RefreshDiscoveryCommand extends Command
{
    protected $signature = 'bc:refresh-discovery
        {--market= : One market, e.g. be-nl. Omit for all of them.}
        {--skip-edition : Classify and score, but do not build today\'s edition.}
        {--queue : Dispatch to the queue instead of running inline.}';

    protected $description = 'Classify giftability, score serendipity and build today\'s edition.';

    public function handle(): int
    {
        $markets = $this->markets();

        if ($markets === []) {
            $this->error('Unknown market. Valid: '.implode(', ', Market::values()));

            return self::FAILURE;
        }

        foreach ($markets as $market) {
            $this->components->task(
                "{$market->value} · giftability",
                fn () => $this->fire(new ClassifyGiftability($market)),
            );

            $this->components->task(
                "{$market->value} · serendipity",
                fn () => $this->fire(new ScoreSerendipity($market)),
            );

            // Brand pages read nothing but these numbers, so a fresh deploy
            // whose brand_stats are empty has a whole URL space that 404s.
            $this->components->task(
                "{$market->value} · brand stats",
                fn () => $this->fire(new RefreshBrandStats($market)),
            );

            if (! $this->option('skip-edition')) {
                $this->components->task(
                    "{$market->value} · daily edition",
                    fn () => $this->fire(new BuildDailyEdition($market)),
                );
            }
        }

        if ($this->option('queue')) {
            $this->components->info('Dispatched to the queue — watch Horizon for completion.');
        }

        return self::SUCCESS;
    }

    /**
     * Inline by default.
     *
     * Someone running this by hand wants to know whether it worked, and a
     * dispatched job reports success the moment it is *queued* — which is a
     * green tick that means nothing. `--queue` is there for a large catalogue
     * where the console session would otherwise be held open for minutes.
     */
    private function fire(object $job): bool
    {
        $this->option('queue') ? dispatch($job) : dispatch_sync($job);

        return true;
    }

    /** @return list<Market> */
    private function markets(): array
    {
        $requested = $this->option('market');

        if ($requested === null) {
            return Market::cases();
        }

        $market = Market::tryFrom((string) $requested);

        return $market === null ? [] : [$market];
    }
}

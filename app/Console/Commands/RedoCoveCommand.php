<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Market;
use App\Models\CovePlan;
use App\Services\Cove\EditionBuilder;
use App\Services\Cove\RedoOptions;
use Illuminate\Console\Command;

/**
 * Do a Cove again from the command line.
 *
 * The admin has a button for this; the command exists because the case you
 * actually hit is "eleven guides came out wrong after a bad feed import", and
 * clicking through eleven confirmation modals is how people start editing the
 * database by hand instead.
 *
 * Deliberately not idempotent and deliberately not schedulable. This is the one
 * operation in the codebase whose whole purpose is to produce a different result
 * from the same inputs, and a cron entry pointing at it would quietly rewrite
 * the site every night.
 */
class RedoCoveCommand extends Command
{
    protected $signature = 'bc:redo-cove
        {market : Market code, e.g. be-nl}
        {--date= : A Daily Cove, as Y-m-d}
        {--slug= : A persona, guide, seasonal or advice Cove}
        {--keep-items : Keep the curated shortlist and rewrite only the prose}
        {--force : Skip the confirmation}';

    protected $description = 'Reselect the products and rewrite a Cove, keeping its URL';

    public function handle(EditionBuilder $builder): int
    {
        $market = Market::tryFrom((string) $this->argument('market'));

        if ($market === null) {
            $this->error('Unknown market. One of: '.implode(', ', Market::values()));

            return self::FAILURE;
        }

        $date = $this->option('date');
        $slug = $this->option('slug');

        if (($date === null) === ($slug === null)) {
            // A Daily is addressed by its date and everything else by a slug.
            // Neither, or both, is not a Cove anyone can name.
            $this->error('Give exactly one of --date or --slug.');

            return self::FAILURE;
        }

        $plan = CovePlan::query()
            ->where('market', $market->value)
            ->when($date !== null, fn ($q) => $q->whereDate('drop_date', $date))
            ->when($slug !== null, fn ($q) => $q->where('slug', $slug))
            ->first();

        if ($plan === null) {
            $this->error('No plan for that Cove. Every published Cove has one; if this is older than the backfill, check the market.');

            return self::FAILURE;
        }

        $keep = (bool) $this->option('keep-items');

        $this->line("<comment>{$plan->kind->label()}</comment>: {$plan->title}");
        $this->line($keep
            ? 'The curated shortlist stays. The prose is discarded and rewritten.'
            : 'The shortlist and the prose are both discarded. New products, new words.');

        /*
         * Named out loud because it cannot be undone and nothing else on the
         * page implies it: the picks are deleted, and their reactions go with
         * them on the cascade.
         */
        $this->warn('Reader reactions on this Cove will be deleted. The URL does not change.');

        if (! $this->option('force') && ! $this->confirm('Redo it?')) {
            return self::SUCCESS;
        }

        $edition = $builder->redo($plan, $keep ? RedoOptions::rewrite() : RedoOptions::reselect());

        if ($edition === null) {
            $this->error('Nothing was published. The plan may not be approved, or the catalogue may no longer fill it.');

            return self::FAILURE;
        }

        $this->info("Redone: edition {$edition->id}, ".$edition->picks()->count().' product(s).');

        return self::SUCCESS;
    }
}

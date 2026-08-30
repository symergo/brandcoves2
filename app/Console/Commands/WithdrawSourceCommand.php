<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Services\Connectors\ConnectorRegistry;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Suppress the offers a source left behind when it stopped serving a market.
 *
 * ## Why stopping a connector is not enough
 *
 * `Market::bolCountry()` returning null stops bol being *asked* about `en`. It
 * does nothing about the 3,424 bol offers already sitting in `products` for that
 * market, because stored search filters on market alone — see
 * `SearchService::storedQuery()`, which never consults the registry. Those rows
 * stay `active`, keep being returned by search, guides and Coves, keep their
 * working affiliate links, and are now frozen forever: the one job that would
 * refresh them is the one that no longer runs.
 *
 * That is the worst of the three states. A market with no supply is honest. A
 * market with fresh supply is fine. A market serving prices that stopped moving
 * on a date nobody recorded looks exactly like the first two from the outside.
 *
 * ## Excluded, not deleted
 *
 * {@see ProductStatus::Excluded} already means "deliberately suppressed — bad
 * data, hazmat, or an admin decision", which is precisely what this is. Deleting
 * would cascade into `price_history` and strand the wish lists and published
 * editorial that point at the groups these offers belong to — the case
 * {@see ProductStatus::Stale} exists to prevent. Excluding is one UPDATE, and
 * `--restore` is the other.
 *
 * `Stale` would be the wrong word: it means "fell out of a feed", which is a
 * fact about the merchant. This is a decision about the market.
 *
 * ## One caveat worth knowing before you rely on --restore
 *
 * `excluded` carries no reason. Nothing in the schema distinguishes an offer
 * withdrawn by this command from one an admin suppressed for hazmat, so
 * `--restore` reactivates **every** excluded offer for that market and source,
 * whatever put it there. Harmless today — the status is unused, `select status,
 * count(*) from products` returns only active and stale — and a trap once it is
 * not. The dry run prints the count so the blast radius is visible before the
 * write; if that number ever exceeds what you withdrew, stop and add a reason
 * column rather than running it.
 */
class WithdrawSourceCommand extends Command
{
    protected $signature = 'bc:withdraw-source
        {--market= : Market whose stored offers to withdraw}
        {--source= : The source that no longer serves it}
        {--write : Apply the change. Without this it only reports}
        {--restore : Put them back to active instead}
        {--force : Proceed even though the connector still serves this market}';

    protected $description = 'Suppress (or restore) the stored offers of a source that no longer serves a market.';

    public function handle(ConnectorRegistry $registry): int
    {
        $market = Market::tryFrom((string) $this->option('market'));
        $source = Source::tryFrom((string) $this->option('source'));

        if ($market === null || $source === null) {
            $this->error('Need --market and --source.');
            $this->line('  Markets: '.implode(', ', Market::values()));
            $this->line('  Sources: '.implode(', ', Source::values()));

            return self::FAILURE;
        }

        $restoring = (bool) $this->option('restore');

        if (! $restoring && $this->stillServes($registry, $market, $source)) {
            /*
             * The guard that makes this safe to run.
             *
             * Withdrawing offers from a source that is still serving achieves
             * nothing and hides the fact: the next search or ingestion re-adds
             * them as active, so the operator sees the count go down and come
             * back with no error anywhere. Refusing beats churning.
             */
            $this->components->error(
                "{$source->label()} still serves {$market->value} — withdrawing would be undone by the next run."
            );
            $this->line('  Stop the source for this market first, or pass --force if you mean it.');

            return self::FAILURE;
        }

        [$from, $to] = $restoring
            ? [ProductStatus::Excluded, ProductStatus::Active]
            : [ProductStatus::Active, ProductStatus::Excluded];

        $affected = $this->scope($market, $source, $from);
        $count = (clone $affected)->count();

        $this->components->twoColumnDetail('Market', $market->value.' — '.$market->label());
        $this->components->twoColumnDetail('Source', $source->label());
        $this->components->twoColumnDetail(
            $restoring ? 'Offers to restore' : 'Offers to withdraw',
            number_format($count).' ('.$from->value.' -> '.$to->value.')'
        );

        if ($count === 0) {
            $this->newLine();
            $this->components->info('Nothing to do.');

            return self::SUCCESS;
        }

        $this->reportBlastRadius($market, $source, $restoring);

        if (! $this->option('write')) {
            $this->newLine();
            $this->components->warn('Dry run. Re-run with --write to apply.');

            return self::SUCCESS;
        }

        $written = $affected->update([
            'status' => $to->value,
            'updated_at' => now(),
        ]);

        $this->newLine();
        $this->components->info(number_format($written).' offer(s) now '.$to->value.'.');

        // Grouping reads offer status when it recomputes a group's cheapest
        // price and offer count, so those aggregates are wrong until it runs.
        $this->line('  Run <fg=cyan>php artisan bc:refresh-discovery</> to rebuild the affected group aggregates.');

        return self::SUCCESS;
    }

    /**
     * What else notices.
     *
     * A group is what search, gift picks and guides operate on, so the number
     * that matters is not "how many offers" but "how many products lose their
     * last offer" — those disappear from the site entirely. And a wish list
     * pointing at one belongs to a person who put it there.
     */
    private function reportBlastRadius(Market $market, Source $source, bool $restoring): void
    {
        if ($restoring) {
            return;
        }

        $orphaned = DB::table('product_groups as g')
            ->where('g.market', $market->value)
            ->whereExists(fn ($q) => $q->from('products as p')
                ->whereColumn('p.group_id', 'g.id')
                ->where('p.source', $source->value)
                ->where('p.status', ProductStatus::Active->value))
            ->whereNotExists(fn ($q) => $q->from('products as p')
                ->whereColumn('p.group_id', 'g.id')
                ->where('p.source', '<>', $source->value)
                ->where('p.status', ProductStatus::Active->value))
            ->count();

        $listed = DB::table('wishlist_items as wi')
            ->join('product_groups as g', 'g.id', '=', 'wi.group_id')
            ->where('g.market', $market->value)
            ->count();

        $this->components->twoColumnDetail(
            'Products left with no offer',
            $orphaned === 0 ? '0' : "<fg=yellow>{$orphaned}</> — these vanish from search and editorial"
        );

        $this->components->twoColumnDetail(
            'Wishlist items in this market',
            $listed === 0 ? '0' : "<fg=yellow>{$listed}</> — kept, but may show nothing to buy"
        );
    }

    /** @return Builder */
    private function scope(Market $market, Source $source, ProductStatus $from)
    {
        return DB::table('products')
            ->where('market', $market->value)
            ->where('source', $source->value)
            ->where('status', $from->value);
    }

    /**
     * Whether the connector would still answer for this market.
     *
     * Asks the registry rather than re-deriving the rule, so this cannot
     * disagree with what search actually does. A source with no connector at
     * all — Amazon — is not serving anything, so withdrawing its leftovers is
     * always allowed.
     */
    private function stillServes(ConnectorRegistry $registry, Market $market, Source $source): bool
    {
        if ($this->option('force')) {
            return false;
        }

        if ($source->isFeed()) {
            return in_array($source, $registry->feedSources(), true)
                && $registry->feed($source)->supports($market);
        }

        return $registry->live($source)?->supports($market) ?? false;
    }
}

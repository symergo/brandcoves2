<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Market;
use App\Enums\Source;
use App\Jobs\FetchEbayItemDetails;
use App\Services\Connectors\Ebay\EbayConnector;
use App\Services\Connectors\Ebay\EbayItemDetails;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Queue item details for eBay offers stored without a product identity (2026-11-22).
 *
 * A search queues details for the listings it stores itself (SearchService::fold); this is for the
 * offers stored before, which otherwise wait until somebody searches for them again. Newest first,
 * up to what is left of today's cap (`connectors.ebay.details_per_day`), in jobs of 50, market by
 * market in enum order, skipping markets eBay is not asked for; `--market` puts one first. Run it
 * again on later days for the rest: a listing asked once is never asked again. Reports unless `--write`.
 *
 * `--leftover` (2026-11-23, scheduled at 23:30 Pacific) spends instead what eBay itself says is
 * left of its day, less `connectors.ebay.leftover_reserve`, past our cap: calls eBay resets unused
 * at midnight Pacific are worth nothing after it. Only in the last hour of eBay's day, so a run by
 * hand at noon cannot spend the calls live search needs for the afternoon.
 */
class EbayDetailsCommand extends Command
{
    protected $signature = 'bc:ebay-details
        {--market= : Only this market. Every market eBay serves by default}
        {--limit= : At most this many listings, below what is left of today\'s cap}
        {--leftover : Spend what eBay says is left of its day instead, past the cap. Last hour before its reset only}
        {--write : Queue the jobs. Without this it only counts}';

    protected $description = 'Queue eBay item details for stored eBay offers without a product identity';

    public function handle(EbayItemDetails $details, EbayConnector $ebay): int
    {
        $market = $this->option('market') !== null ? Market::tryFrom((string) $this->option('market')) : null;

        if ($this->option('market') !== null && $market === null) {
            $this->error('Unknown market: '.$this->option('market'));

            return self::FAILURE;
        }

        $until = null;

        if ($this->option('leftover')) {
            $reset = now(EbayItemDetails::EBAY_DAY_ZONE)->startOfDay()->addDay();

            if (now()->diffInMinutes($reset) > 60) {
                $this->warn('Not in the last hour of eBay\'s day (it resets at '.$reset->utc()->format('H:i').' UTC); nothing done.');

                return self::SUCCESS;
            }

            $left = $ebay->callsLeft();

            if ($left === null) {
                $this->warn('eBay would not say how many calls are left; nothing done.');

                return self::SUCCESS;
            }

            $budget = max(0, $left - (int) config('giftcoves.connectors.ebay.leftover_reserve', 300));
            $until = $reset->getTimestamp();
            $this->line("eBay has {$left} calls left until its reset; this run takes at most {$budget}.");
        } else {
            $budget = $details->remainingToday();
            $this->line("Left of today's cap: {$budget}; this run takes at most ".min($budget, $this->limit() ?? $budget).'.');
        }

        if ($this->limit() !== null) {
            $budget = min($budget, $this->limit());
        }

        foreach ($market === null ? Market::cases() : [$market] as $each) {
            // A market eBay is not asked for (switched off, no marketplace) would spend the budget on nothing
            if (! $ebay->supports($each)) {
                $this->line("{$each->value}: eBay is not asked for this market; skipped.");

                continue;
            }

            $waiting = DB::table('products')
                ->where('source', Source::Ebay->value)
                ->where('market', $each->value)
                ->whereNull('identity_key')
                ->whereNull('ean')
                ->whereNotExists(fn ($q) => $q->from('ebay_item_details')->whereColumn('ebay_item_details.external_id', 'products.external_id'));

            $count = (clone $waiting)->count();

            if ($count === 0) {
                continue;
            }

            $ids = $budget > 0 ? (clone $waiting)->orderByDesc('id')->limit($budget)->pluck('external_id')->all() : [];
            $budget -= count($ids);

            $this->line(sprintf('%s: %d eBay offers without identity never asked; %s %d.', $each->value, $count, $this->option('write') ? 'queued' : 'would queue', count($ids)));

            if ($this->option('write')) {
                foreach (array_chunk($ids, 50) as $chunk) {
                    FetchEbayItemDetails::dispatch($each, array_values($chunk), leftoverUntil: $until);
                }
            }
        }

        if (! $this->option('write')) {
            $this->comment('Dry run. Nothing queued; add --write.');
        }

        return self::SUCCESS;
    }

    private function limit(): ?int
    {
        return $this->option('limit') !== null ? max(0, (int) $this->option('limit')) : null;
    }
}

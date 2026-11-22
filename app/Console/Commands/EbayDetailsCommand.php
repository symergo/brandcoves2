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
 */
class EbayDetailsCommand extends Command
{
    protected $signature = 'bc:ebay-details
        {--market= : Only this market. Every market eBay serves by default}
        {--limit= : At most this many listings, below what is left of today\'s cap}
        {--write : Queue the jobs. Without this it only counts}';

    protected $description = 'Queue eBay item details for stored eBay offers without a product identity';

    public function handle(EbayItemDetails $details, EbayConnector $ebay): int
    {
        $market = $this->option('market') !== null ? Market::tryFrom((string) $this->option('market')) : null;

        if ($this->option('market') !== null && $market === null) {
            $this->error('Unknown market: '.$this->option('market'));

            return self::FAILURE;
        }

        $budget = $details->remainingToday();

        if ($this->option('limit') !== null) {
            $budget = min($budget, max(0, (int) $this->option('limit')));
        }

        $this->line("Left of today's cap: {$details->remainingToday()}; this run takes at most {$budget}.");

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
                    FetchEbayItemDetails::dispatch($each, array_values($chunk));
                }
            }
        }

        if (! $this->option('write')) {
            $this->comment('Dry run. Nothing queued; add --write.');
        }

        return self::SUCCESS;
    }
}

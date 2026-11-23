<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Amazon\AsinDataClient;
use App\Services\Amazon\AsinDataUnavailable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ask ASIN Data API which ASIN each of these barcodes is on one storefront, and keep
 * the answer in `amazon_matches` (App\Services\Amazon\AmazonMatches).
 *
 * At most `connectors.asindata.lookups_per_day` lookups a day across storefronts,
 * counted from the rows written today (UTC): a lookup that was answered is a credit
 * spent. A hiccup ("you have not been charged") writes nothing and the barcode is
 * asked again when a page next queues it. A bad key, no credits or too many requests
 * stops every lookup until tomorrow, rather than spending the rest of the queue
 * finding that out one barcode at a time.
 */
class LookUpAmazonMatches implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    /** @param  list<string>  $gtins */
    public function __construct(
        public readonly string $storefront,
        public readonly array $gtins,
    ) {}

    public function handle(AsinDataClient $client): void
    {
        $stopKey = 'asindata:stopped:'.now()->toDateString();

        if (! $client->configured() || Cache::has($stopKey)) {
            return;
        }

        $cap = (int) config('giftcoves.connectors.asindata.lookups_per_day', 1000);
        $recheck = now()->subDays((int) config('giftcoves.connectors.asindata.recheck_days', 60));

        foreach ($this->gtins as $gtin) {
            if (DB::table('amazon_matches')->where('checked_at', '>=', now()->startOfDay())->count() >= $cap) {
                return;
            }

            // Another job may have answered it since this one was queued.
            $fresh = DB::table('amazon_matches')
                ->where('storefront', $this->storefront)
                ->where('gtin', $gtin)
                ->where('checked_at', '>=', $recheck)
                ->exists();

            if ($fresh) {
                continue;
            }

            try {
                $answer = $client->lookup($this->storefront, $gtin);
            } catch (AsinDataUnavailable $e) {
                if ($e->stopForToday) {
                    Cache::put($stopKey, true, now()->endOfDay());
                    Log::warning('asindata: lookups stopped for today', ['reason' => $e->getMessage()]);

                    return;
                }

                continue;
            }

            DB::table('amazon_matches')->upsert(
                [['storefront' => $this->storefront, 'gtin' => $gtin, 'asin' => $answer->asin, 'checked_at' => now()]],
                ['storefront', 'gtin'],
                ['asin', 'checked_at'],
            );
        }
    }
}

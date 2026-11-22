<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Market;
use App\Enums\Source;
use App\Services\Connectors\Ebay\EbayConnector;
use App\Services\Connectors\Ebay\EbayItemDetails;
use App\Services\Identity\IdentityResolver;
use App\Services\Ingestion\IncomingGrouper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Facades\DB;

/**
 * Ask eBay's item detail for brand, barcode and part number, then make products of what it gives.
 *
 * Queued by SearchService for the eBay listings a search just stored without either a brand or a
 * barcode, and by `bc:ebay-details` for those stored before 2026-11-22. In the background, never
 * while a visitor waits; one request per listing, within the daily cap (EbayItemDetails). A listing
 * asked before is skipped; one eBay will not answer for now (rate limit, an error) stops the run,
 * unrecorded, so it is asked again next time.
 *
 * What an answer changes on the stored offer: the brand eBay states replaces one read from the
 * title (a knock-off marked "no brand" keeps none), the barcode and part number fill empty fields,
 * and an offer without an identity gets one if those now allow it (IdentityResolver: barcode, else
 * brand and title). Then it is grouped, and appears in search.
 */
#[Queue('default')]
class FetchEbayItemDetails implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    /** @param  list<string>  $externalIds */
    public function __construct(public readonly Market $market, public readonly array $externalIds) {}

    public function handle(EbayConnector $ebay, EbayItemDetails $details, IncomingGrouper $grouper): void
    {
        $grouped = [];

        foreach ($details->unknown($this->externalIds) as $externalId) {
            if ($details->remainingToday() <= 0) {
                break;
            }

            $item = $ebay->itemDetail($externalId, $this->market);

            if ($item === null) {
                break;
            }

            $found = EbayItemDetails::parse($item);
            $details->remember($externalId, $found);

            if ($this->applyTo($externalId, $found)) {
                $grouped[] = $externalId;
            }
        }

        $grouper->attachIds($this->market, Source::Ebay->value, $grouped);
    }

    /**
     * Write the details onto the stored offer. True when it now has an identity to group by.
     *
     * @param  array{brand: ?string, gtin: ?string, mpn: ?string}  $found
     */
    private function applyTo(string $externalId, array $found): bool
    {
        $offer = DB::table('products')
            ->where('source', Source::Ebay->value)
            ->where('external_id', $externalId)
            ->where('market', $this->market->value)
            ->first(['id', 'title', 'brand', 'ean', 'mpn', 'identity_key']);

        if ($offer === null) {
            return false;
        }

        $changes = array_filter([
            // eBay's own word beats the brand read from the start of the title
            'brand' => $found['brand'],
            'ean' => $offer->ean === null ? $found['gtin'] : null,
            'mpn' => $offer->mpn === null ? $found['mpn'] : null,
        ], fn (?string $value): bool => $value !== null);

        if ($offer->identity_key === null) {
            $identity = IdentityResolver::resolve($found['gtin'] ?? $offer->ean, $found['brand'], (string) $offer->title);

            if ($identity !== null) {
                $changes['identity_key'] = $identity->key;
                $changes['identity_kind'] = $identity->kind->value;
            }
        }

        if ($changes !== []) {
            DB::table('products')->where('id', $offer->id)->update($changes + ['updated_at' => now()]);
        }

        return isset($changes['identity_key']);
    }
}

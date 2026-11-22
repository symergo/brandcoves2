<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\IdentityKind;
use App\Enums\Market;
use App\Enums\Source;
use App\Models\ProductGroup;
use App\Services\Connectors\Ebay\EbayConnector;
use App\Services\Connectors\Ebay\EbayItemDetails;
use App\Services\Identity\GroupMerger;
use App\Services\Identity\IdentityResolver;
use App\Services\Ingestion\IncomingGrouper;
use App\Services\Search\SearchGenerations;
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

    /**
     * @param  list<string>  $externalIds
     * @param  string|null  $term  The search that queued this, whose cached result is retired once
     *                             products appear; null from bc:ebay-details, which waits for the nightly grouping
     * @param  list<string>  $brands  That search's brand filter, retired the same way
     */
    public function __construct(
        public readonly Market $market,
        public readonly array $externalIds,
        public readonly ?string $term = null,
        public readonly array $brands = [],
    ) {}

    public function handle(EbayConnector $ebay, EbayItemDetails $details, IncomingGrouper $grouper, GroupMerger $merger): void
    {
        $grouped = [];
        /** @var array<string, string> $moved listing key => the real identity it now has */
        $moved = [];

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

            // An empty answer is an ended listing (404): no product for that
            $applied = $this->applyTo($externalId, $found, live: $item !== []);

            if ($applied !== null) {
                $grouped[] = $externalId;

                if ($applied['from'] !== null) {
                    $moved[$applied['from']] = $applied['to'];
                }
            }
        }

        $grouper->attachIds($this->market, Source::Ebay->value, $grouped);

        /*
         * A listing that was a product of its own (IdentityKind::Listing) now has its real one, and its
         * offer has just moved there. Its old product is merged into the real one rather than left
         * empty: whatever somebody saved to it, an alert or a Cove pick moves along, and its address
         * answers 301 (GroupMerger). Without retiring every cached search of the market per merge.
         */
        foreach ($moved as $from => $to) {
            $loser = ProductGroup::query()->forMarket($this->market)->where('identity_key', $from)->whereNull('merged_into_id')->first();
            $winner = ProductGroup::query()->forMarket($this->market)->where('identity_key', $to)->whereNull('merged_into_id')->first();

            if ($loser !== null && $winner !== null && $loser->id !== $winner->id) {
                $merger->merge($loser, $winner, null, 'eBay item detail gave the listing its product', retireMarketSearches: false);
            }
        }

        /*
         * The search that queued this cached its result when the listings were stored, before they
         * had products: still empty, and current for up to `results_cache_ttl` (12 hours). Retire
         * it so the next visitor sees the products (2026-11-22). Only that search: retiring every
         * cached search of the market at once is what overloaded the site on 2026-10-25.
         */
        if ($grouped !== [] && $this->term !== null && trim($this->term) !== '') {
            SearchGenerations::bumpTerm($this->market, $this->term);
            SearchGenerations::bumpBrands($this->market, $this->brands);
        }
    }

    /**
     * Write the details onto the stored offer. Null when it has no new identity to group by; else the
     * listing key it had (null if none) and the identity it has now.
     *
     * @param  array{brand: ?string, gtin: ?string, mpn: ?string}  $found
     * @return array{from: ?string, to: string}|null
     */
    private function applyTo(string $externalId, array $found, bool $live): ?array
    {
        $offer = DB::table('products')
            ->where('source', Source::Ebay->value)
            ->where('external_id', $externalId)
            ->where('market', $this->market->value)
            ->first(['id', 'title', 'brand', 'ean', 'mpn', 'identity_key', 'identity_kind']);

        if ($offer === null) {
            return null;
        }

        $changes = array_filter([
            // eBay's own word beats the brand read from the start of the title
            'brand' => $found['brand'],
            'ean' => $offer->ean === null ? $found['gtin'] : null,
            'mpn' => $offer->mpn === null ? $found['mpn'] : null,
        ], fn (?string $value): bool => $value !== null);

        $wasListing = $offer->identity_kind === IdentityKind::Listing->value;

        // Nothing yet, or only the listing itself: the detail may give the real product
        if ($offer->identity_key === null || $wasListing) {
            $identity = IdentityResolver::resolve($found['gtin'] ?? $offer->ean, $found['brand'], (string) $offer->title)
                // Stored before listings became products of their own (2026-11-22) and still nothing to
                // group by: its own listing product, as a search would make it now. Not for an ended one.
                ?? ($offer->identity_key === null && $live ? IdentityResolver::listing(Source::Ebay->value, $externalId) : null);

            if ($identity !== null) {
                $changes['identity_key'] = $identity->key;
                $changes['identity_kind'] = $identity->kind->value;
            }
        }

        if ($changes !== []) {
            DB::table('products')->where('id', $offer->id)->update($changes + ['updated_at' => now()]);
        }

        return isset($changes['identity_key'])
            ? ['from' => $wasListing ? (string) $offer->identity_key : null, 'to' => $changes['identity_key']]
            : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\Feed;
use App\Models\Merchant;
use App\Services\Connectors\Offer;
use App\Services\Identity\IdentityResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writes a chunk of offers into the catalogue.
 *
 * Bulk upserts, not per-row saves. The row-at-a-time version of this is the
 * difference between an ingestion run taking minutes and taking hours, and a
 * feed is tens of thousands of rows.
 */
class OfferUpserter
{
    /** @var array<string, int> merchant external_id => id, per run */
    private array $merchantCache = [];

    /**
     * @param  list<Offer>  $offers
     * @return array{written: int, skipped: int}
     */
    public function upsert(array $offers, ?Feed $feed = null): array
    {
        if ($offers === []) {
            return ['written' => 0, 'skipped' => 0];
        }

        $now = Carbon::now();
        $rows = [];
        $skipped = 0;

        foreach ($offers as $offer) {
            if (! $offer->isValid()) {
                // Overwhelmingly a missing affiliate URL or a non-https scheme.
                // Expected in real feeds, so counted rather than raised.
                $skipped++;

                continue;
            }

            $identity = IdentityResolver::resolve($offer->ean, $offer->brand, $offer->title);

            $rows[] = [
                'source' => $offer->source->value,
                'external_id' => $offer->externalId,
                'market' => $offer->market->value,
                'merchant_id' => $this->merchantId($offer),
                'feed_id' => $feed?->id,
                'title' => $offer->title,
                'description' => $offer->description,
                'brand' => $offer->brand,
                'merchant_category' => $offer->merchantCategory,
                'price' => $offer->price,
                'reference_price' => $offer->referencePrice,
                'currency' => $offer->currency,
                'image_url' => $offer->imageUrl,
                'affiliate_url' => $offer->affiliateUrl,
                'merchant_deep_link' => $offer->merchantDeepLink,
                'availability' => $offer->availability->value,
                'ean' => $offer->ean,
                'commission_rate' => $offer->commissionRate,
                'identity_key' => $identity?->key,
                'identity_kind' => $identity?->kind->value,
                'status' => ProductStatus::Active->value,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return ['written' => 0, 'skipped' => $skipped];
        }

        DB::transaction(function () use ($rows, $now): void {
            DB::table('products')->upsert(
                $rows,
                ['source', 'external_id', 'market'],
                [
                    // first_seen_at and created_at are deliberately absent: a
                    // product that has been in the catalogue for a year must not
                    // look new because today's run touched it. Freshness is a
                    // real signal for Daily Picks.
                    'merchant_id', 'feed_id', 'title', 'description', 'brand',
                    'merchant_category', 'price', 'reference_price', 'currency',
                    'image_url', 'affiliate_url', 'merchant_deep_link',
                    'availability', 'ean', 'commission_rate',
                    'identity_key', 'identity_kind', 'status',
                    'last_seen_at', 'updated_at',
                ],
            );

            $this->recordPriceHistory($rows, $now);
        });

        return ['written' => count($rows), 'skipped' => $skipped];
    }

    /**
     * One price sample per product per day.
     *
     * Ingestion runs hourly; without the daily key this table would take 24
     * rows per product per day, which across a 60k catalogue is roughly half a
     * billion rows a year to support a sparkline.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function recordPriceHistory(array $rows, Carbon $now): void
    {
        // Prices are recorded for every source, Amazon included: storing a
        // price is not the restricted act. What Amazon prohibits is building a
        // price-tracking feature on top — that is gated on the read side by
        // Source::allowsPriceTracking(). See docs/features/amazon-compliance.md.
        $priced = array_values(array_filter(
            $rows,
            fn (array $r) => $r['price'] !== null && Source::from($r['source'])->allowsPriceStorage(),
        ));

        if ($priced === []) {
            return;
        }

        // Grouped by (source, market) rather than assuming the whole batch
        // shares one. A feed chunk does, but the live search path upserts bol
        // offers alongside whatever else it found — and an earlier version
        // keyed the lookup off the first row, silently dropping the price
        // history of every other source in the batch.
        $bySourceAndMarket = [];
        foreach ($priced as $row) {
            $bySourceAndMarket[$row['source'].'|'.$row['market']][] = $row['external_id'];
        }

        $products = collect();
        foreach ($bySourceAndMarket as $key => $externalIds) {
            [$source, $market] = explode('|', $key, 2);

            $products = $products->merge(
                DB::table('products')
                    ->select('id', 'price', 'availability')
                    ->where('source', $source)
                    ->where('market', $market)
                    ->whereIn('external_id', $externalIds)
                    ->get()
            );
        }

        $history = [];
        foreach ($products as $product) {
            if ($product->price === null) {
                continue;
            }

            $history[] = [
                'product_id' => $product->id,
                'price' => $product->price,
                'availability' => $product->availability,
                'captured_at' => $now,
                'captured_on' => $now->toDateString(),
            ];
        }

        if ($history === []) {
            return;
        }

        DB::table('price_history')->upsert(
            $history,
            ['product_id', 'captured_on'],
            // Last write in a day wins: the most recent price is the truest
            // answer, and a sparkline wants one point per day either way.
            ['price', 'availability', 'captured_at'],
        );
    }

    /**
     * Merchants are created on sight.
     *
     * Onboarding is a config action, not a code change, and the domain comes
     * from the merchant's own deep link — never from the affiliate tracking
     * URL, which points at the network and would give every merchant the same
     * favicon.
     */
    private function merchantId(Offer $offer): ?int
    {
        $externalId = $offer->merchantExternalId;
        if ($externalId === null || $externalId === '') {
            return null;
        }

        $cacheKey = $offer->source->value.':'.$externalId;
        if (isset($this->merchantCache[$cacheKey])) {
            return $this->merchantCache[$cacheKey];
        }

        $merchant = Merchant::query()->firstOrCreate(
            ['source' => $offer->source->value, 'external_id' => $externalId],
            [
                'name' => $offer->merchantName ?? $externalId,
                'domain' => $offer->merchantDomain(),
            ],
        );

        // A merchant's domain only becomes knowable once a row carrying a deep
        // link arrives, which may not be the first row we see from them.
        if ($merchant->domain === null && $offer->merchantDomain() !== null) {
            $merchant->update(['domain' => $offer->merchantDomain()]);
        }

        return $this->merchantCache[$cacheKey] = $merchant->id;
    }
}

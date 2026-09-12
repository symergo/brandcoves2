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
                // Three prices instead of a history (2026-09-12). On insert the
                // first price is today's and there is no previous one; on update
                // the expressions below decide, because only the database knows
                // what the row said before this chunk.
                'first_price' => $offer->price,
                'previous_price' => null,
                'price_changed_at' => null,
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

        $rows = $this->deduplicate($rows);

        DB::transaction(function () use ($rows): void {
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
                    /*
                     * The three prices. `products.*` is the row as it was,
                     * `excluded.*` the row arriving. The first price is kept
                     * once set; the previous price and the moment of change
                     * move only when the price actually differs, so a chunk
                     * that repeats yesterday's price leaves both alone. This
                     * replaced a daily sample per offer in `price_history`.
                     */
                    'first_price' => DB::raw('COALESCE(products.first_price, excluded.first_price)'),
                    'previous_price' => DB::raw('CASE WHEN products.price IS DISTINCT FROM excluded.price THEN products.price ELSE products.previous_price END'),
                    'price_changed_at' => DB::raw('CASE WHEN products.price IS DISTINCT FROM excluded.price THEN excluded.updated_at ELSE products.price_changed_at END'),
                ],
            );

        });

        return ['written' => count($rows), 'skipped' => $skipped];
    }

    /**
     * One row per `(source, external_id, market)` before the upsert sees them.
     *
     * Postgres refuses an `INSERT … ON CONFLICT DO UPDATE` whose own batch
     * contains two rows with the same constrained key:
     *
     *     SQLSTATE[21000]: ON CONFLICT DO UPDATE command cannot affect row a
     *     second time
     *
     * and it refuses the **whole statement**, so one duplicated product loses
     * the entire chunk — tens of thousands of rows — and the run dies. Which is
     * exactly what it did on a bol feed: the same product listed under two
     * categories arrives twice in one page, and nothing upstream had any reason
     * to notice.
     *
     * Fixed here rather than in the connector because this is the single choke
     * point every caller goes through — the feed job, the chart puller and the
     * live search path — and a feed is third-party input. Assuming a supplier
     * will not repeat itself is the same class of mistake as trusting an
     * affiliate URL's scheme.
     *
     * **Last occurrence wins**, matching what the upsert would have done had the
     * rows arrived in two separate statements: later in the file is the more
     * recent record of the same offer.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function deduplicate(array $rows): array
    {
        $byKey = [];

        foreach ($rows as $row) {
            $byKey[$row['source'].'|'.$row['market'].'|'.$row['external_id']] = $row;
        }

        return array_values($byKey);
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

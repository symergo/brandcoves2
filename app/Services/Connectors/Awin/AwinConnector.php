<?php

declare(strict_types=1);

namespace App\Services\Connectors\Awin;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\Source;
use App\Models\Feed;
use App\Services\Connectors\FeedConnector;
use App\Services\Connectors\Offer;
use App\Services\Identity\Gtin;
use Generator;
use Illuminate\Support\Facades\Http;
use League\Csv\Reader;
use RuntimeException;

/**
 * Awin product feed ingestion.
 *
 * A single advertiser feed runs to hundreds of megabytes and tens of thousands
 * of rows, so everything here streams: the file is never fully downloaded into
 * memory and never fully parsed into an array. The job that drives this commits
 * in chunks and records its position, so a redeploy mid-run resumes rather than
 * starting over.
 */
class AwinConnector implements FeedConnector
{
    /**
     * Columns requested from Awin, in order.
     *
     * `merchant_deep_link` is not optional decoration: `aw_deep_link` is an
     * awin1.com tracking redirect, so it is useless for identifying the
     * merchant's own domain. Without the deep link every merchant would end up
     * showing Awin's favicon instead of their own.
     */
    private const COLUMNS = [
        'aw_product_id',
        'merchant_product_id',
        'merchant_id',
        'merchant_name',
        'product_name',
        'description',
        'brand_name',
        'search_price',
        'rrp_price',
        'currency',
        'aw_image_url',
        'merchant_image_url',
        'aw_deep_link',
        'merchant_deep_link',
        /*
         * THREE barcode columns, because advertisers disagree about which one
         * to use and Awin does not normalise them.
         *
         * Measured on the live feeds: Krefel populates `ean` for 9,121 of 9,140
         * rows and leaves `upc` empty; Coolblue populates `upc` for 17,482 of
         * 17,504 and leaves `ean` empty. Reading only `ean` meant every Coolblue
         * product fell back to title-based identity, so the two shops occupied
         * separate identity spaces and produced ZERO comparable products —
         * despite sharing 1,955 barcodes.
         *
         * Whichever column a merchant uses, the value is a GTIN and
         * Gtin::normalise() validates it.
         */
        'ean',
        'upc',
        'product_GTIN',
        'in_stock',
        'merchant_category',
        'commission_group',
    ];

    private int $row = 0;

    private ?int $total = null;

    public function source(): Source
    {
        return Source::Awin;
    }

    public function supports(Market $market): bool
    {
        return (bool) config('giftcoves.connectors.awin.enabled')
            && filled(config('giftcoves.connectors.awin.api_token'))
            && Feed::query()->enabled()->where('market', $market->value)->exists();
    }

    /**
     * @param  array<string, mixed>|null  $cursor
     * @return Generator<int, Offer>
     */
    public function stream(Feed $feed, ?array $cursor = null): Generator
    {
        $resumeAt = (int) ($cursor['row'] ?? 0);
        $this->row = 0;

        $handle = $this->open($feed);

        try {
            $reader = Reader::createFromStream($handle);
            $reader->setHeaderOffset(0);

            foreach ($reader->getRecords() as $record) {
                $this->row++;

                // Resume by skipping rows already committed. The feed order is
                // stable within a run, so this lands where the last chunk left
                // off. Cheap relative to the network cost of the download.
                if ($this->row <= $resumeAt) {
                    continue;
                }

                $offer = $this->normalise($record, $feed);
                if ($offer !== null && $offer->isValid()) {
                    yield $offer;
                }
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /** @return array<string, mixed> */
    public function cursor(): array
    {
        return ['row' => $this->row];
    }

    public function total(): ?int
    {
        // Awin exposes no cheap row count, and counting means downloading the
        // feed twice. Progress is reported as rows processed instead.
        return $this->total;
    }

    /**
     * Advertiser feeds available to this publisher, for the admin picker.
     *
     * @return list<array{id: string, name: string, market: string|null}>
     */
    public function availableFeeds(Market $market): array
    {
        $token = (string) config('giftcoves.connectors.awin.api_token');
        $publisher = (string) config('giftcoves.connectors.awin.publisher_id');

        if ($token === '' || $publisher === '') {
            return [];
        }

        $response = Http::timeout(30)->get(
            "https://productdata.awin.com/datafeed/list/apikey/{$token}/"
        );

        if ($response->failed()) {
            return [];
        }

        $csv = Reader::createFromString($response->body());
        $csv->setHeaderOffset(0);

        $feeds = [];
        foreach ($csv->getRecords() as $record) {
            // Only feeds this publisher is actually approved for; the list
            // otherwise includes every advertiser on the network.
            if (($record['Membership Status'] ?? '') !== 'active') {
                continue;
            }

            $feeds[] = [
                'id' => (string) ($record['Feed ID'] ?? ''),
                'name' => (string) ($record['Advertiser Name'] ?? ''),
                'market' => $record['Primary Region'] ?? null,
            ];
        }

        return $feeds;
    }

    /**
     * @return resource
     */
    protected function open(Feed $feed)
    {
        $url = $this->downloadUrl($feed);

        // compress.zlib:// decompresses as it reads, so a 400 MB gzipped feed
        // never lands on disk or in memory in full.
        $handle = @fopen($url, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Could not open Awin feed {$feed->external_feed_id}");
        }

        return $handle;
    }

    protected function downloadUrl(Feed $feed): string
    {
        // An explicit override lets admin point a feed at a mirror, and lets
        // tests use a local fixture without stubbing the HTTP layer.
        $override = $feed->column_map['url'] ?? null;
        if (is_string($override) && $override !== '') {
            return $override;
        }

        // The feed's own account, not a global token: a different affiliate
        // member id sees a different advertiser list entirely, so the wrong key
        // returns 401 or an empty file rather than a partial result.
        $token = $feed->apiToken();
        if ($token === null) {
            throw new RuntimeException(
                "No Awin API token configured for account \"{$feed->account}\" (feed {$feed->external_feed_id})"
            );
        }

        $columns = implode(',', self::COLUMNS);
        $language = $feed->market->language();

        return 'compress.zlib://https://productdata.awin.com/datafeed/download'
            ."/apikey/{$token}"
            ."/language/{$language}"
            ."/fid/{$feed->external_feed_id}"
            ."/columns/{$columns}"
            .'/format/csv/delimiter/%2C/compression/gzip/adultcontent/0/';
    }

    /** @param array<string, string|null> $record */
    private function normalise(array $record, Feed $feed): ?Offer
    {
        $externalId = trim((string) ($record['aw_product_id'] ?? ''));
        $title = trim((string) ($record['product_name'] ?? ''));

        if ($externalId === '' || $title === '') {
            return null;
        }

        // aw_deep_link carries the affiliate tracking; merchant_deep_link is
        // the merchant's own URL and the only reliable source of their domain.
        $affiliateUrl = trim((string) ($record['aw_deep_link'] ?? ''));
        $merchantDeepLink = trim((string) ($record['merchant_deep_link'] ?? '')) ?: null;

        // Prefer the merchant's own image. Awin's proxy has historically
        // returned a 70x70 placeholder with HTTP 200 for merchants whose CDN
        // URLs it cannot fetch, which no onerror handler can catch.
        $image = trim((string) ($record['merchant_image_url'] ?? ''))
            ?: trim((string) ($record['aw_image_url'] ?? ''));

        return new Offer(
            source: Source::Awin,
            externalId: $externalId,
            market: $feed->market,
            title: $title,
            affiliateUrl: $affiliateUrl,
            price: $this->parsePrice($record['search_price'] ?? null),
            description: trim((string) ($record['description'] ?? '')) ?: null,
            brand: trim((string) ($record['brand_name'] ?? '')) ?: null,
            merchantName: trim((string) ($record['merchant_name'] ?? '')) ?: null,
            merchantExternalId: trim((string) ($record['merchant_id'] ?? '')) ?: null,
            merchantDeepLink: $merchantDeepLink,
            merchantCategory: trim((string) ($record['merchant_category'] ?? '')) ?: null,
            imageUrl: $image ?: null,
            ean: $this->barcode($record),
            referencePrice: $this->parsePrice($record['rrp_price'] ?? null),
            currency: strtoupper(trim((string) ($record['currency'] ?? 'EUR'))) ?: 'EUR',
            availability: Availability::fromFeedValue($record['in_stock'] ?? null),
            commissionRate: $this->parseCommission($record['commission_group'] ?? null),
        );
    }

    /**
     * The product's barcode, from whichever column the advertiser used.
     *
     * Tried in order of how likely the column is to hold a real GTIN. Each
     * candidate is validated rather than trusted: `Gtin::normalise()` checks the
     * length and the GS1 check digit, so a part number that happens to sit in
     * the wrong column is rejected instead of becoming a bogus identity that
     * merges unrelated products.
     *
     * `mpn` is deliberately excluded. Some advertisers copy the EAN into it, but
     * it is a manufacturer part number by definition, and two manufacturers can
     * legitimately use the same one.
     *
     * @param  array<string, string|null>  $record
     */
    private function barcode(array $record): ?string
    {
        foreach (['ean', 'upc', 'product_GTIN'] as $column) {
            $raw = trim((string) ($record[$column] ?? ''));

            if ($raw !== '' && Gtin::isValid($raw)) {
                return $raw;
            }
        }

        return null;
    }

    /**
     * Feed prices arrive as "12.99", "12,99" and occasionally "1.299,00".
     * Stored as integer cents — floats accumulate error across the min and
     * median aggregates that drive "cheapest offer" and discount badges.
     */
    private function parsePrice(?string $raw): ?int
    {
        if ($raw === null) {
            return null;
        }

        $cleaned = preg_replace('/[^\d.,-]/', '', trim($raw)) ?? '';
        if ($cleaned === '' || str_contains($cleaned, '-')) {
            return null;
        }

        $lastComma = strrpos($cleaned, ',');
        $lastDot = strrpos($cleaned, '.');

        // Whichever separator appears last is the decimal separator. That rule
        // handles both "1.299,00" (European) and "1,299.00" (Anglo) correctly.
        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            $normalised = str_replace(',', '.', str_replace('.', '', $cleaned));
        } else {
            $normalised = str_replace(',', '', $cleaned);
        }

        if (! is_numeric($normalised)) {
            return null;
        }

        $value = (float) $normalised;

        return $value < 0 ? null : (int) round($value * 100);
    }

    private function parseCommission(?string $raw): ?float
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        preg_match('/([\d.]+)\s*%?/', $raw, $matches);

        return isset($matches[1]) ? (float) $matches[1] : null;
    }
}

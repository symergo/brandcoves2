<?php

declare(strict_types=1);

namespace App\Services\PageReading;

use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\AmazonProduct;
use App\Models\Merchant;
use App\Models\ProductGroup;
use App\Services\Connectors\ConnectorRegistry;
use App\Services\Ingestion\BolPageImport;
use App\Services\Ingestion\IncomingGrouper;
use App\Services\Ingestion\OfferUpserter;
use App\Services\Search\AmazonLink;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Our own data and connectors first; the shop's page last.
 *
 * The owner's rule (2026-09-26): **a link to a site we hold in the feed
 * database or reach through an API connector is resolved through that, never
 * by fetching and parsing the page.** Our data is better — a live price, stock,
 * other shops to compare — it is what the programme allows, and it costs the
 * shop nothing. Only a link nothing below recognises may be read.
 *
 * In order:
 *
 * 1. **Amazon**, by the ASIN in the URL, against what we already know about
 *    that ASIN. Never fetched, not even a short link (expanding `amzn.to` is a
 *    request to Amazon). Nothing from Amazon is stored (invariant 6).
 * 2. **bol**, by the product id in `/p/{slug}/{id}/`: our catalogue first, then
 *    bol's API through {@see BolPageImport}, the same path the editors' browser
 *    extension uses.
 * 3. **eBay**, by the item number in `/itm/…/{id}`: catalogue, then connector.
 * 4. **A feed merchant**, by host, then by the product's stored deep link. A
 *    merchant we know but a product we do not falls through to reading the
 *    page: the merchant's feed simply has not got it.
 *
 * `$useConnectors` is off when the caller cannot afford an upstream request.
 */
class LinkRouter
{
    public function __construct(
        private readonly BolPageImport $bol,
        private readonly ConnectorRegistry $connectors,
        private readonly OfferUpserter $upserter,
        private readonly IncomingGrouper $grouper,
    ) {}

    public function resolve(string $url, Market $market, bool $useConnectors = true): LinkResolution
    {
        $host = self::host($url);

        if ($host === null) {
            return LinkResolution::unknown();
        }

        if (($amazon = AmazonLink::parse($url)) !== null) {
            return $this->amazon($amazon, $market);
        }

        if (preg_match('/(^|\.)bol\.com$/', $host)) {
            return $this->bol($url, $market, $useConnectors);
        }

        if (preg_match('/(^|\.)ebay\.[a-z.]{2,6}$/', $host)) {
            return $this->ebay($url, $market, $useConnectors);
        }

        return $this->feedMerchant($url, $host, $market) ?? LinkResolution::unknown();
    }

    /** The host without `www.`, lowercased, or null for something that is not a web address. */
    public static function host(string $url): ?string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower($host);

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    private function amazon(AmazonLink $link, Market $market): LinkResolution
    {
        /*
         * `amazon_products.identity_key` is the bridge to the group other
         * shops' offers hang off — the same one SearchController follows for a
         * pasted link in the search box. Market-scoped, per invariant 2.
         */
        $key = $link->asin === null
            ? null
            : AmazonProduct::query()->where('asin', $link->asin)->value('identity_key');

        $group = $key === null
            ? null
            : ProductGroup::query()->forMarket($market)->where('identity_key', $key)->first();

        return $group !== null
            ? LinkResolution::group($group, Source::Amazon)
            : LinkResolution::known(Source::Amazon);
    }

    private function bol(string $url, Market $market, bool $useConnectors): LinkResolution
    {
        if (! preg_match('#/p/([^/]+)/(\d{6,})#', (string) parse_url($url, PHP_URL_PATH), $m)) {
            return LinkResolution::known(Source::Bol);
        }

        [, $slug, $id] = $m;

        if (($group = $this->storedGroup(Source::Bol, $id, $market)) !== null) {
            return LinkResolution::group($group, Source::Bol);
        }

        if ($useConnectors) {
            /*
             * bol's API cannot look a product up by the id in its own URLs
             * (see BolPageImport's docblock, measured 2026-09-14), so the slug
             * stands in for the title and the id comparison is exact.
             */
            try {
                $this->bol->import($market, [['productId' => $id, 'title' => str_replace('-', ' ', $slug)]]);
            } catch (Throwable $e) {
                report($e);
            }

            if (($group = $this->storedGroup(Source::Bol, $id, $market)) !== null) {
                return LinkResolution::group($group, Source::Bol);
            }
        }

        return LinkResolution::known(Source::Bol);
    }

    private function ebay(string $url, Market $market, bool $useConnectors): LinkResolution
    {
        if (! preg_match('#/itm/(?:[^/]+/)?(\d{9,15})#', (string) parse_url($url, PHP_URL_PATH), $m)) {
            return LinkResolution::known(Source::Ebay);
        }

        // The Browse API's id for a listing without variations. The same form
        // the connector stores in `external_id`.
        $id = "v1|{$m[1]}|0";

        if (($group = $this->storedGroup(Source::Ebay, $id, $market)) !== null) {
            return LinkResolution::group($group, Source::Ebay);
        }

        $connector = $useConnectors ? $this->connectors->live(Source::Ebay) : null;

        if ($connector === null || ! $connector->supports($market)) {
            return LinkResolution::known(Source::Ebay);
        }

        $offer = $connector->fetchById($id, $market);

        if ($offer === null || ! $offer->isValid()) {
            return LinkResolution::known(Source::Ebay);
        }

        // Stored and grouped exactly as a live search result would be.
        $this->upserter->upsert([$offer]);
        $this->grouper->attach($market, [$offer]);

        $group = $this->storedGroup(Source::Ebay, $offer->externalId, $market);

        return $group !== null ? LinkResolution::group($group, Source::Ebay) : LinkResolution::offer($offer);
    }

    /**
     * A shop whose feed we ingest, found by the product's own URL.
     *
     * `merchants.domain` is written from the feed's deep links without `www.`
     * (Offer::merchantDomain), which is how the host is compared here. The
     * deep link is compared on host and path only: feeds and visitors add
     * different tracking parameters to the same page.
     */
    private function feedMerchant(string $url, string $host, Market $market): ?LinkResolution
    {
        $merchantIds = Merchant::query()->where('domain', $host)->pluck('id')->all();

        if ($merchantIds === []) {
            return null;
        }

        $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');

        if ($path === '') {
            return null;
        }

        $escaped = addcslashes($path, '%_\\');

        $groupId = DB::table('products')
            ->whereIn('merchant_id', $merchantIds)
            ->where('market', $market->value)
            ->where('status', ProductStatus::Active->value)
            ->whereNotNull('group_id')
            ->where(function ($q) use ($host, $escaped): void {
                foreach (["https://{$host}", "https://www.{$host}", "http://{$host}", "http://www.{$host}"] as $origin) {
                    $q->orWhere('merchant_deep_link', 'like', $origin.$escaped)
                        ->orWhere('merchant_deep_link', 'like', $origin.$escaped.'/%')
                        ->orWhere('merchant_deep_link', 'like', $origin.$escaped.'?%')
                        ->orWhere('merchant_deep_link', 'like', $origin.$escaped.'#%');
                }
            })
            ->value('group_id');

        $group = $groupId === null ? null : ProductGroup::query()->find($groupId);

        return $group === null ? null : LinkResolution::group($group);
    }

    private function storedGroup(Source $source, string $externalId, Market $market): ?ProductGroup
    {
        $groupId = DB::table('products')
            ->where('source', $source->value)
            ->where('external_id', $externalId)
            ->where('market', $market->value)
            ->whereNotNull('group_id')
            ->value('group_id');

        return $groupId === null ? null : ProductGroup::query()->find($groupId);
    }
}

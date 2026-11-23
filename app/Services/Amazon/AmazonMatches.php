<?php

declare(strict_types=1);

namespace App\Services\Amazon;

use App\Enums\Market;
use App\Jobs\LookUpAmazonMatches;
use App\Models\ProductGroup;
use App\Support\Crawlers;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Bekijk op Amazon": a direct link to the same product on Amazon, without a price
 * (owner, 2026-11-23). See docs/features/amazon-links.md.
 *
 * A product with a barcode is matched to its ASIN on the market's Amazon storefront
 * (amazon.com.be for be-nl and be-fr, amazon.nl for nl-nl), in the background by
 * LookUpAmazonMatches, and the match is kept in `amazon_matches`: the ASIN only.
 * Pages read the matches they have and queue the barcodes they lack; they never
 * call the API themselves, so a page never waits on Amazon.
 */
class AmazonMatches
{
    /** The storefronts this asks about. Each holds an Associates tag of ours. */
    public const STOREFRONTS = ['amazon.com.be', 'amazon.nl'];

    /** At most this many barcodes per queued job, so one page cannot queue a run of hundreds. */
    private const PER_JOB = 30;

    /** A barcode queued in the last hour is not queued again by the next page view. */
    private const QUEUED_SECONDS = 3600;

    public function __construct(private readonly AsinDataClient $client) {}

    /** The storefront host (no www) for a market, or null where we link to none. */
    public function storefront(Market $market): ?string
    {
        $host = preg_replace('/^www\./', '', mb_strtolower((string) config("giftcoves.amazon_search.markets.{$market->value}.host", '')));

        return in_array($host, self::STOREFRONTS, true) ? $host : null;
    }

    /**
     * Barcode => Amazon product link, for the barcodes among these that have a match.
     * One query. The link carries the storefront's Associates tag.
     *
     * @param  iterable<ProductGroup>  $groups
     * @return array<string, string>
     */
    public function links(Market $market, iterable $groups): array
    {
        $storefront = $this->storefront($market);
        $gtins = $this->gtins($groups);

        if ($storefront === null || $gtins === []) {
            return [];
        }

        $tag = trim((string) config("giftcoves.amazon_search.markets.{$market->value}.tag", ''));

        return DB::table('amazon_matches')
            ->where('storefront', $storefront)
            ->whereIn('gtin', $gtins)
            ->whereNotNull('asin')
            ->pluck('asin', 'gtin')
            ->map(fn (string $asin) => 'https://www.'.$storefront.'/dp/'.$asin.($tag !== '' ? '?tag='.rawurlencode($tag) : ''))
            ->all();
    }

    /**
     * The product page's Amazon row, drawn like every other shop's (owner,
     * 2026-11-23): the storefront as the shop's name (amazon.com.be), Amazon's own
     * favicon as its logo, a title under it and the tagged link. Amazon's title when
     * a search saved one for this ASIN, else ours. Null without a match.
     *
     * @return array{url: string, host: string, icon: string, title: string}|null
     */
    public function offer(Market $market, ProductGroup $group): ?array
    {
        $url = $this->links($market, [$group])[$group->identity_key] ?? null;
        $storefront = $this->storefront($market);

        if ($url === null || $storefront === null) {
            return null;
        }

        $asin = (string) DB::table('amazon_matches')->where('storefront', $storefront)->where('gtin', $group->identity_key)->value('asin');
        $title = trim((string) DB::table('amazon_products')->where('asin', $asin)->value('classified_title'));

        return [
            'url' => $url,
            'host' => $storefront,
            // Amazon's own favicon from Amazon's site, as the Amazon banner on search
            // uses (AmazonSearchLink::iconUrl()): the trademark rules allow Amazon's
            // marks only in the forms Amazon supplies.
            'icon' => 'https://www.'.$storefront.'/favicon.ico',
            'title' => $title !== '' ? $title : $group->displayTitle(),
        ];
    }

    /**
     * Queue a lookup for the barcodes among these that were never asked, or asked
     * longer than `recheck_days` ago. Nothing for a crawler: a credit is spent on a
     * product a person looked at. Nothing without an API key.
     *
     * @param  iterable<ProductGroup>  $groups
     */
    public function queueMissing(Market $market, iterable $groups, ?string $userAgent): void
    {
        $storefront = $this->storefront($market);

        if ($storefront === null || ! $this->client->configured() || Crawlers::looksLikeOne($userAgent)) {
            return;
        }

        $gtins = $this->gtins($groups);

        if ($gtins === []) {
            return;
        }

        $known = DB::table('amazon_matches')
            ->where('storefront', $storefront)
            ->whereIn('gtin', $gtins)
            ->where('checked_at', '>=', now()->subDays((int) config('giftcoves.connectors.asindata.recheck_days', 60)))
            ->pluck('gtin')
            ->all();

        $missing = array_values(array_filter(
            array_diff($gtins, $known),
            fn (string $gtin) => Cache::add('amazon-match:queued:'.$storefront.':'.$gtin, true, self::QUEUED_SECONDS),
        ));

        foreach (array_chunk($missing, self::PER_JOB) as $chunk) {
            LookUpAmazonMatches::dispatch($storefront, $chunk);
        }
    }

    /**
     * The barcodes of the groups identified by one (the others have no barcode to ask about).
     *
     * @param  iterable<ProductGroup>  $groups
     * @return list<string>
     */
    private function gtins(iterable $groups): array
    {
        $gtins = [];

        foreach ($groups as $group) {
            if ($group->identity_kind?->value === 'ean' && is_string($group->identity_key) && $group->identity_key !== '') {
                $gtins[$group->identity_key] = true;
            }
        }

        // Strings again: PHP turns a numeric array key into an int, and a barcode
        // is text (a leading zero matters, and the column is varchar).
        return array_map('strval', array_keys($gtins));
    }
}

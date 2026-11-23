<?php

declare(strict_types=1);

namespace App\Services\Amazon;

use App\Enums\Market;
use App\Support\Crawlers;
use Illuminate\Support\Facades\Cache;

/**
 * Amazon's products for a search, shown under our results without a price (owner,
 * 2026-11-23: "just do a search with the api on amazon when a search is made").
 * See docs/features/amazon-links.md.
 *
 * Asked through ASIN Data API in the search page's deferred follow-up request, so
 * the page is never held up by Amazon. Each term's answer is kept for
 * `search_cache_days` per storefront, so a repeated search costs nothing; at most
 * `searches_per_day` new searches a day; never for a crawler. A failed search is
 * remembered for an hour so a burst of visitors does not repeat it.
 *
 * What is kept is the ASIN, the title and Amazon's picture address, in the cache,
 * never in the catalogue (invariant 6), and never a price.
 */
class AmazonSearch
{
    /** How many Amazon products the section shows. */
    public const SHOWN = 8;

    public function __construct(
        private readonly AsinDataClient $client,
        private readonly AmazonMatches $matches,
    ) {}

    /** Whether this request may show Amazon's products at all. */
    public function applies(Market $market, ?string $term, ?string $userAgent): bool
    {
        return $this->matches->storefront($market) !== null
            && $this->client->configured()
            && trim((string) $term) !== ''
            && ! Crawlers::looksLikeOne($userAgent);
    }

    /**
     * Up to SHOWN products: ASIN, title, picture and our tagged link. Empty when
     * Amazon found nothing, the day's searches are used up, or the API failed.
     *
     * @return list<array{asin: string, title: string, image: string|null, url: string}>
     */
    public function results(Market $market, string $term): array
    {
        $storefront = $this->matches->storefront($market);

        if ($storefront === null) {
            return [];
        }

        $term = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $term) ?? $term));
        $key = 'amazon-search:v1:'.$storefront.':'.sha1($term);
        $rows = Cache::get($key);

        if (! is_array($rows)) {
            $rows = $this->ask($storefront, $term, $key);
        }

        $tag = trim((string) config("giftcoves.amazon_search.markets.{$market->value}.tag", ''));

        return array_map(fn (array $row): array => $row + [
            'url' => 'https://www.'.$storefront.'/dp/'.$row['asin'].($tag !== '' ? '?tag='.rawurlencode($tag) : ''),
        ], array_slice($rows, 0, self::SHOWN));
    }

    /** @return list<array{asin: string, title: string, image: string|null}> */
    private function ask(string $storefront, string $term, string $key): array
    {
        $day = now()->toDateString();

        if (Cache::has('asindata:stopped:'.$day)) {
            return [];
        }

        // Two visitors searching the same new term at once ask once.
        $lock = Cache::lock($key.':lock', 30);

        if (! $lock->get()) {
            return [];
        }

        try {
            $used = 'asindata:searches:'.$day;
            Cache::add($used, 0, now()->endOfDay());

            if ((int) Cache::get($used, 0) >= (int) config('giftcoves.connectors.asindata.searches_per_day', 100)) {
                return [];
            }

            Cache::increment($used);

            try {
                $rows = $this->client->search($storefront, $term);
            } catch (AsinDataUnavailable $e) {
                if ($e->stopForToday) {
                    Cache::put('asindata:stopped:'.$day, true, now()->endOfDay());
                }

                Cache::put($key, [], 3600);

                return [];
            }

            $rows = array_slice($rows, 0, self::SHOWN);
            Cache::put($key, $rows, now()->addDays((int) config('giftcoves.connectors.asindata.search_cache_days', 7)));

            return $rows;
        } finally {
            $lock->release();
        }
    }
}

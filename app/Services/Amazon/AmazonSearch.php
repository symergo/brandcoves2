<?php

declare(strict_types=1);

namespace App\Services\Amazon;

use App\Enums\Market;
use App\Models\AmazonProduct;
use App\Support\Crawlers;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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

        $term = mb_substr(mb_strtolower(trim(preg_replace('/\s+/u', ' ', $term) ?? $term)), 0, 200);
        $key = 'amazon-search:v1:'.$storefront.':'.sha1($term);
        $rows = $this->stored($storefront, $term) ?? (Cache::has($key.':failed') ? [] : $this->ask($storefront, $term, $key));

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

                Cache::put($key.':failed', true, 3600);

                return [];
            }

            $rows = array_slice($rows, 0, self::SHOWN);
            $this->save($storefront, $term, $rows);

            return $rows;
        } finally {
            $lock->release();
        }
    }

    /**
     * The term's stored answer while it is younger than `search_cache_days`, as rows
     * read back from `amazon_products` in the stored order; null when there is none.
     *
     * @return list<array{asin: string, title: string, image: string|null}>|null
     */
    private function stored(string $storefront, string $term): ?array
    {
        $search = DB::table('amazon_searches')
            ->where('storefront', $storefront)
            ->where('term', $term)
            ->where('searched_at', '>=', now()->subDays((int) config('giftcoves.connectors.asindata.search_cache_days', 7)))
            ->first();

        if ($search === null) {
            return null;
        }

        $asins = (array) json_decode((string) $search->asins, true);
        $products = AmazonProduct::query()->whereIn('asin', $asins)->get(['asin', 'classified_title', 'image_url'])->keyBy('asin');
        $rows = [];

        foreach ($asins as $asin) {
            $product = $products->get($asin);

            if ($product !== null && trim((string) $product->classified_title) !== '') {
                $rows[] = ['asin' => $asin, 'title' => (string) $product->classified_title, 'image' => $product->image_url];
            }
        }

        return $rows;
    }

    /**
     * Keep the products in `amazon_products` (ASIN, title, picture address, the
     * storefront it was seen on; never a price) and the term's answer in
     * `amazon_searches` (owner, 2026-11-23). A title or picture a person imported
     * from the product's own page is not overwritten by the search's shorter one.
     *
     * @param  list<array{asin: string, title: string, image: string|null}>  $rows
     */
    private function save(string $storefront, string $term, array $rows): void
    {
        DB::transaction(function () use ($storefront, $term, $rows): void {
            foreach ($rows as $row) {
                $product = AmazonProduct::query()->firstOrNew(['asin' => $row['asin']]);
                $seen = array_values(array_unique([...(array) ($product->seen_in_locales ?? []), $storefront]));

                $product->fill([
                    'classified_title' => $product->classified_title ?: $row['title'],
                    'image_url' => $product->image_url ?: $row['image'],
                    'seen_in_locales' => $seen,
                    'imported_from' => $product->imported_from ?: 'asindata:search',
                ]);

                if (! $product->exists) {
                    $product->first_seen_at = now();
                }

                $product->save();
            }

            DB::table('amazon_searches')->upsert(
                [['storefront' => $storefront, 'term' => $term, 'asins' => json_encode(array_column($rows, 'asin')), 'searched_at' => now()]],
                ['storefront', 'term'],
                ['asins', 'searched_at'],
            );
        });
    }
}

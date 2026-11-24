<?php

declare(strict_types=1);

namespace App\Services\Amazon;

use App\Enums\Market;
use App\Models\AmazonProduct;
use App\Services\Search\SearchQuery;
use App\Support\Crawlers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Amazon's products for a search, shown under our results without a price (owner,
 * 2026-11-23: "just do a search with the api on amazon when a search is made").
 * See docs/features/amazon-links.md.
 *
 * Asked through ASIN Data API in the search page's deferred follow-up request, so
 * the page is never held up by Amazon, in the market's language (amazon.com.be
 * answers French unless asked for Dutch). Each answer is saved (owner, same day:
 * "save the amazon products to database, without price"): the products in
 * `amazon_products`, the term's answer as shown in `amazon_searches`, which then
 * answers the same term for `search_cache_days`. At most `searches_per_day` new
 * searches a day; never for a crawler. A failed search is remembered for an hour so
 * a burst of visitors does not repeat it.
 *
 * Never a price: the client does not read one and no table has a column for one.
 */
class AmazonSearch
{
    /** How many Amazon products the section shows. */
    public const SHOWN = 8;

    /**
     * The language Amazon is asked in, per market. The `language` parameter is not
     * in ASIN Data API's documentation; the owner supplied it (2026-11-23) and a test
     * search with nl_BE on amazon.com.be came back in Dutch.
     */
    private const LANGUAGES = ['be-nl' => 'nl_BE', 'be-fr' => 'fr_BE', 'nl-nl' => 'nl_NL'];

    public function __construct(
        private readonly AsinDataClient $client,
        private readonly AmazonMatches $matches,
    ) {}

    /**
     * The shop filter's value for Amazon (owner, 2026-11-24: "Amazon is not in the
     * filters"). Not a merchant id: Amazon is no merchant of ours. SearchQuery reads
     * `merchant` as ids and drops it, so our results ignore it.
     */
    public const FILTER = 'amazon';

    /** Whether the request's shop filter has Amazon ticked. */
    public static function picked(Request $request): bool
    {
        return in_array(self::FILTER, array_map('strval', (array) $request->query('merchant', [])), true);
    }

    /**
     * What Amazon is searched for: the term, with the brand in front when the page is
     * filtered to one brand that the term does not already name (2026-11-24). Amazon
     * has no brand filter we can pass, so "koptelefoon" filtered to Sony asks for
     * "Sony koptelefoon" rather than mixing every brand's headphones into a Sony list.
     * Several brands ticked: the term alone, as before.
     */
    public static function termFor(SearchQuery $query): string
    {
        $term = trim($query->term);

        if (count($query->brands) !== 1) {
            return $term;
        }

        $brand = trim($query->brands[0]);

        return $brand === '' || str_contains(mb_strtolower($term), mb_strtolower($brand))
            ? $term
            : trim($brand.' '.$term);
    }

    /**
     * Whether Amazon's products join the page, given its shop filter: always without
     * one, and with one only when Amazon is ticked too. Ticking only other shops is
     * asking for those shops.
     */
    public static function shownWith(SearchQuery $query, bool $picked): bool
    {
        return $query->merchantIds === [] || $picked;
    }

    /**
     * The page's filters with Amazon back among the ticked shops, so the checkbox and
     * its chip stay ticked.
     *
     * @param  array<string, mixed>  $filters  SearchQuery::toArray()
     * @return array<string, mixed>
     */
    public static function filtersWith(array $filters, bool $picked): array
    {
        if ($picked) {
            $filters['merchant'] = [...($filters['merchant'] ?? []), self::FILTER];
        }

        return $filters;
    }

    /** Whether this request may show Amazon's products at all. */
    public function applies(Market $market, ?string $term, ?string $userAgent): bool
    {
        return $this->matches->storefront($market) !== null
            && isset(self::LANGUAGES[$market->value])
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
        $language = self::LANGUAGES[$market->value] ?? null;

        if ($storefront === null || $language === null) {
            return [];
        }

        $term = mb_substr(mb_strtolower(trim(preg_replace('/\s+/u', ' ', $term) ?? $term)), 0, 200);
        $key = 'amazon-search:v2:'.$storefront.':'.$language.':'.sha1($term);
        $rows = $this->stored($storefront, $language, $term)
            ?? (Cache::has($key.':failed') ? [] : $this->ask($storefront, $language, $term, $key));

        $tag = trim((string) config("giftcoves.amazon_search.markets.{$market->value}.tag", ''));

        return array_map(fn (array $row): array => [
            'asin' => $row['asin'],
            'title' => $row['title'],
            'image' => $row['image'] ?? null,
            'url' => 'https://www.'.$storefront.'/dp/'.$row['asin'].($tag !== '' ? '?tag='.rawurlencode($tag) : ''),
        ], array_slice($rows, 0, self::SHOWN));
    }

    /** @return list<array{asin: string, title: string, image: string|null}> */
    private function ask(string $storefront, string $language, string $term, string $key): array
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
                $rows = $this->client->search($storefront, $term, $language, (string) config('giftcoves.connectors.asindata.search_sort', ''));
            } catch (AsinDataUnavailable $e) {
                if ($e->stopForToday) {
                    Cache::put('asindata:stopped:'.$day, true, now()->endOfDay());
                }

                Cache::put($key.':failed', true, 3600);

                return [];
            }

            $rows = array_slice($rows, 0, self::SHOWN);
            $this->save($storefront, $language, $term, $rows);

            return $rows;
        } finally {
            $lock->release();
        }
    }

    /**
     * The term's saved answer while it is younger than `search_cache_days`; null when
     * there is none.
     *
     * @return list<array{asin: string, title: string, image: string|null}>|null
     */
    private function stored(string $storefront, string $language, string $term): ?array
    {
        $items = DB::table('amazon_searches')
            ->where('storefront', $storefront)
            ->where('language', $language)
            ->where('term', $term)
            ->where('searched_at', '>=', now()->subDays((int) config('giftcoves.connectors.asindata.search_cache_days', 7)))
            ->value('items');

        return $items === null ? null : array_values((array) json_decode((string) $items, true));
    }

    /**
     * Keep the products in `amazon_products` (ASIN, title, picture address, the
     * storefront it was seen on; never a price) and the term's answer, titles in the
     * language asked, in `amazon_searches`. A title or picture a person imported
     * from the product's own page is not overwritten by the search's.
     *
     * @param  list<array{asin: string, title: string, image: string|null}>  $rows
     */
    private function save(string $storefront, string $language, string $term, array $rows): void
    {
        DB::transaction(function () use ($storefront, $language, $term, $rows): void {
            foreach ($rows as $row) {
                $product = AmazonProduct::query()->firstOrNew(['asin' => $row['asin']]);

                $product->fill([
                    'classified_title' => $product->classified_title ?: $row['title'],
                    'classified_locale' => $product->classified_locale ?: $language,
                    'image_url' => $product->image_url ?: $row['image'],
                    'seen_in_locales' => array_values(array_unique([...(array) ($product->seen_in_locales ?? []), $storefront])),
                    'imported_from' => $product->imported_from ?: 'asindata:search',
                ]);

                if (! $product->exists) {
                    $product->first_seen_at = now();
                }

                $product->save();
            }

            DB::table('amazon_searches')->upsert(
                [['storefront' => $storefront, 'language' => $language, 'term' => $term, 'items' => json_encode(array_values($rows)), 'searched_at' => now()]],
                ['storefront', 'language', 'term'],
                ['items', 'searched_at'],
            );
        });
    }
}

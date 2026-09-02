<?php

declare(strict_types=1);

namespace App\Services\Connectors\Tradedoubler;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\Source;
use App\Services\Connectors\LiveConnector;
use App\Services\Connectors\Offer;
use App\Services\Connectors\RateLimiter;
use App\Services\Connectors\SourceSwitch;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tradedoubler's Open Product API, queried live.
 *
 * The first source here that is a **network** rather than a shop. bol is bol;
 * eBay is eBay; Tradedoubler is thousands of advertisers behind one endpoint,
 * and its payload reflects that — a product carries a LIST of offers, one per
 * advertiser selling it.
 *
 * That shape is unusually kind to this codebase. `products` rows are offers and
 * `product_groups` rows are physical products (invariant 3), and Tradedoubler
 * hands us exactly that split already made: one payload product becomes several
 * Offers, each with its own merchant, and they group together on the barcode
 * they share. It is the first source that delivers a real price comparison in a
 * single request instead of assembling one over weeks of ingestion.
 *
 * Live rather than ingested because Tradedoubler's feeds are per-advertiser:
 * taking them means joining programmes one at a time and running an ingestion
 * job per programme, which is precisely Awin's shape and Awin already occupies
 * it. The API is the whole network in one call.
 *
 * Every public method degrades instead of throwing, per the LiveConnector
 * contract.
 *
 * ## The field names here are UNVERIFIED
 *
 * Every other connector in this directory was written against a live response.
 * This one could not be: the credential it was built for is REJECTED — HTTP 403,
 * `{"message":"Invalid token, Request not Authorised","statuscode":"4001"}` — so
 * the mapping below follows Tradedoubler's documented shape and no more.
 *
 * That is exactly the situation the Awin barcode-column bug and bol's
 * `results`-vs-`products` envelope came out of, so the code is written to FAIL
 * LOUDLY rather than quietly: an unrecognised envelope logs the keys it actually
 * received, and `bc:check-tradedoubler --raw` prints a real payload field by
 * field once a working token exists.
 *
 * Confirm it against a live response before trusting this in production. Every
 * field read below has an explicit fallback chain for that reason, and the ones
 * that carry money or identity are the ones to check first.
 */
class TradedoublerConnector implements LiveConnector
{
    private const API_BASE = 'https://api.tradedoubler.com/1.0';

    public function source(): Source
    {
        return Source::Tradedoubler;
    }

    public function supports(Market $market): bool
    {
        return app(SourceSwitch::class)->isEnabled(Source::Tradedoubler, $market)
            && (bool) config('giftcoves.connectors.tradedoubler.enabled')
            && filled(config('giftcoves.connectors.tradedoubler.token'))
            // No scoping for this market means skip. Never "ask unscoped" —
            // see the note on tradedoublerQuery(): an unrecognised filter is
            // ignored rather than rejected, so an unscoped call returns the
            // whole European network and every result looks plausible.
            && $market->tradedoublerQuery() !== null;
    }

    public function isCoolingDown(): bool
    {
        return $this->limiter('search')->isCoolingDown();
    }

    /** @return list<Offer> */
    public function search(string $query, Market $market, int $limit = 24): array
    {
        $query = trim($query);
        if ($query === '' || ! $this->supports($market)) {
            return [];
        }

        $cacheKey = sprintf('bc:td:search:%s:%s:%d', $market->value, sha1(mb_strtolower($query)), $limit);

        // Raw payload in the cache, never Offer objects — a serialised domain
        // object in a shared cache breaks on the redeploy that changes its
        // shape, which cost bol a 500 on every warm search.
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $this->offersFrom($cached, $market, $limit);
        }

        $products = $this->fetchSearch($query, $market, $limit);

        // An empty result is not cached: every degraded path returns one, so
        // caching it blanks the source long after the cause has cleared.
        if ($products !== []) {
            Cache::put($cacheKey, $products, (int) config('giftcoves.search.live_cache_ttl'));
        }

        return $this->offersFrom($products, $market, $limit);
    }

    /**
     * Re-check one offer.
     *
     * The Open Product API has no single-product endpoint, so this searches on
     * the identifier and matches exactly. That is a real limitation and not a
     * shortcut: a search can return a *different* advertiser's listing of the
     * same product, so anything but an exact external-id match is discarded
     * rather than accepted as "close enough". A wishlist item silently
     * repointed at another shop's offer is worse than one that fails to refresh.
     */
    public function fetchById(string $externalId, Market $market): ?Offer
    {
        if (! $this->supports($market) || trim($externalId) === '') {
            return null;
        }

        // A separate bucket from search, so a busy hour of searching cannot
        // starve a re-check — the same split bol and eBay make.
        if (! $this->limiter('product')->attempt()) {
            return null;
        }

        // The product half of our composite id is the searchable part; the
        // program half identifies which advertiser's offer we want back.
        [$productId] = $this->splitExternalId($externalId);

        $response = $this->request($market, 'product', ['q' => $productId]);

        if ($response === null) {
            return null;
        }

        foreach ($this->offersFrom($this->products($response, $market), $market, 50) as $offer) {
            if ($offer->externalId === $externalId) {
                return $offer;
            }
        }

        return null;
    }

    /**
     * Payload products to Offers, fanned out over each product's advertisers.
     *
     * @param  list<array<string, mixed>>  $products
     * @return list<Offer>
     */
    private function offersFrom(array $products, Market $market, int $limit): array
    {
        $offers = [];

        /*
         * One payload can carry the same (product, advertiser) pair twice —
         * paging overlap, or two listings of one product by one shop.
         *
         * `products` is unique on (source, external_id, market), and
         * OfferUpserter writes a batch in a single upsert, so a duplicate does
         * not merely waste a row: Postgres refuses the whole statement with
         * "ON CONFLICT DO UPDATE command cannot affect row a second time" and
         * the entire search's worth of offers is lost. bol hit exactly this on
         * its first live chart run.
         *
         * First sighting wins, which also keeps the network's own relevance
         * order intact.
         *
         * @var array<string, true> $seen
         */
        $seen = [];

        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }

            foreach ($this->productOffers($product) as $raw) {
                if (count($offers) >= $limit) {
                    return $offers;
                }

                $offer = $this->normalise($product, $raw, $market);

                if (! $offer?->isValid() || isset($seen[$offer->externalId])) {
                    continue;
                }

                $seen[$offer->externalId] = true;
                $offers[] = $offer;
            }
        }

        return $offers;
    }

    /**
     * The advertiser offers attached to one payload product.
     *
     * A product with no offers block is a catalogue entry nobody is currently
     * selling. Skipped rather than stored unpriced — there is nothing to click.
     *
     * @param  array<string, mixed>  $product
     * @return list<array<string, mixed>>
     */
    private function productOffers(array $product): array
    {
        $offers = $product['offers'] ?? $product['offer'] ?? [];

        // A single offer object rather than a list, which some responses use
        // when a product has exactly one advertiser.
        if (is_array($offers) && $offers !== [] && ! array_is_list($offers)) {
            $offers = [$offers];
        }

        return is_array($offers) ? array_values(array_filter($offers, 'is_array')) : [];
    }

    /**
     * Tradedoubler's raw product rows.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchSearch(string $query, Market $market, int $limit): array
    {
        if (! $this->limiter('search')->attempt()) {
            Log::info('tradedoubler search skipped: rate limited', ['market' => $market->value]);

            return [];
        }

        $response = $this->request($market, 'search', [
            'q' => $query,
            // Products, not offers. One product can fan out into several
            // offers, so asking for `limit` products and capping the offers
            // afterwards is what keeps a single popular product from filling
            // the whole page with one shop's neighbours.
            'limit' => $limit,
        ]);

        return $response === null ? [] : $this->products($response, $market);
    }

    /**
     * The product rows out of a response envelope.
     *
     * The key is `products`. It is checked rather than assumed, and a miss is
     * logged with the envelope's real keys, because a wrong key here fails
     * SILENTLY — an empty array is indistinguishable from "the network has
     * nothing for this query", which is how a broken connector survives a green
     * test suite for months. This connector was written without a live response
     * to read, so this is the single most likely thing to be wrong about it.
     *
     * @return list<array<string, mixed>>
     */
    private function products(Response $response, Market $market): array
    {
        foreach (['products', 'product', 'results'] as $key) {
            $rows = $response->json($key);

            if (is_array($rows)) {
                return array_values(array_filter($rows, 'is_array'));
            }
        }

        Log::warning('tradedoubler returned an unrecognised envelope', [
            'market' => $market->value,
            // Keys only. The payload is large and holds nothing secret, but
            // logging it wholesale turns one bad response into a megabyte of log.
            'keys' => array_keys((array) $response->json()),
        ]);

        return [];
    }

    /** @param array<string, mixed> $params */
    private function request(Market $market, string $bucket, array $params = []): ?Response
    {
        $token = (string) config('giftcoves.connectors.tradedoubler.token');

        if ($token === '') {
            return null;
        }

        try {
            $response = Http::timeout(8)
                /*
                 * Retry a transport failure or a 5xx. Never a 4xx.
                 *
                 * The other connectors here retry unconditionally, which is
                 * harmless while their credentials work and measurably not
                 * while they do not: a rejected token answers 403 in
                 * milliseconds, and an unconditional retry asks a second time
                 * for the same refusal on every single search. Caught by a test
                 * that counted requests after the live token came back
                 * `Invalid token, Request not Authorised` — two calls, one
                 * answer, no chance of a different one.
                 *
                 * A ConnectionException is not a RequestException and has no
                 * response, so a timeout still retries, which is the case retry
                 * exists for.
                 */
                ->retry(2, 200, fn (Throwable $e): bool => ! $e instanceof RequestException
                    || $e->response->serverError(), throw: false)
                ->withHeaders(['Accept' => 'application/json'])
                ->get(self::API_BASE.'/products.json', [
                    /*
                     * The token rides in the QUERY STRING, not a header.
                     *
                     * Tradedoubler's Open Product API has no token exchange and
                     * no Authorization header — which means the credential is
                     * in the URL, and therefore in any log line that records
                     * one. Nothing here logs `$r->url()`, and nothing should:
                     * the check command prints the token's length, never the
                     * URL, for this reason.
                     */
                    'token' => $token,
                    ...$params,
                    // Market scoping last so it cannot be overwritten by a
                    // caller's parameter of the same name.
                    ...($market->tradedoublerQuery() ?? []),
                ]);
        } catch (Throwable $e) {
            Log::warning('tradedoubler request failed', ['error' => $e->getMessage()]);

            return null;
        }

        if ($response->status() === 429) {
            $this->limiter($bucket)->penalise(
                (int) config('giftcoves.connectors.tradedoubler.cooldown_seconds')
            );
            Log::warning('tradedoubler rate limited us; backing off', ['bucket' => $bucket]);

            return null;
        }

        if (in_array($response->status(), [401, 403], true)) {
            /*
             * A rejected token backs off exactly like a rate limit, and for a
             * stronger reason.
             *
             * Unlike bol and eBay this connector holds no cached token to
             * discard — the credential IS the config value — so a rejection is
             * not transient. Nothing will change until somebody edits the
             * environment, and every search until then would otherwise spend a
             * fresh request, and up to eight seconds of a visitor's page load,
             * discovering the same thing again. An empty result is not cached
             * either (deliberately, see search()), so there is nothing else to
             * stop the repeat.
             *
             * Observed rather than reasoned about: the first live call with the
             * credential this was written for returned
             * `{"message":"Invalid token, Request not Authorised"}` as a 403,
             * and it did so on every subsequent search.
             *
             * Logged at warning because "search returned fewer results" is
             * otherwise the only symptom of a dead affiliate account.
             */
            $this->limiter($bucket)->penalise(
                (int) config('giftcoves.connectors.tradedoubler.cooldown_seconds')
            );

            Log::warning('tradedoubler rejected the token; backing off', [
                'status' => $response->status(),
                // The body names the reason and holds no secret — the token is
                // in the URL, which is never logged.
                'body' => mb_substr($response->body(), 0, 200),
            ]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('tradedoubler returned an error', ['status' => $response->status()]);

            return null;
        }

        return $response;
    }

    /**
     * One payload product plus one of its advertiser offers, to our Offer.
     *
     * @param  array<string, mixed>  $product
     * @param  array<string, mixed>  $raw
     */
    private function normalise(array $product, array $raw, Market $market): ?Offer
    {
        $title = trim((string) ($product['name'] ?? $product['title'] ?? ''));
        $externalId = $this->externalId($product, $raw);

        if ($title === '' || $externalId === null) {
            return null;
        }

        $price = $this->price($raw, $market, $externalId);

        /*
         * An offer we could not price is dropped, not stored unpriced.
         *
         * A network offer with no price is one the advertiser has stopped
         * populating, not a formatting problem — and this source exists to put
         * a second and third price beside bol's. An unpriced row contributes
         * nothing to that and would still occupy a merchant slot on the card.
         */
        if ($price === null) {
            return null;
        }

        $merchant = trim((string) ($raw['programName'] ?? $raw['advertiserName'] ?? ''));

        return new Offer(
            source: Source::Tradedoubler,
            externalId: $externalId,
            market: $market,
            title: $title,
            // Already tracked: the API returns a clk.tradedoubler.com link
            // because the token carries the affiliate id. There is no id to
            // append, which is why this source has no equivalent of eBay's
            // campaign id or bol's site id.
            affiliateUrl: trim((string) ($raw['productUrl'] ?? $raw['offerUrl'] ?? '')),
            price: $price,
            description: $this->description($product),
            // Tradedoubler DOES return a brand, unlike bol and eBay's search.
            // Taken as given rather than inferred — it is the advertiser's own
            // field, and grouping keys on it whenever the barcode is missing.
            brand: $this->brand($product),
            /*
             * The ADVERTISER is the merchant, not Tradedoubler.
             *
             * The opposite call from eBay, where one merchant row stands for
             * the whole marketplace. There the sellers are millions of
             * individuals and naming them would make the shop directory
             * meaningless; here they are retailers with names a shopper
             * recognises, and collapsing them into "Tradedoubler" would put a
             * company that sells nothing on the buy button.
             *
             * It also throws away the entire point of the source: the offers
             * are only worth having because they are DIFFERENT shops.
             */
            merchantName: $merchant !== '' ? $merchant : null,
            merchantExternalId: $this->merchantId($raw),
            // The advertiser's own product URL, which is the only place their
            // domain can come from — the affiliate URL is the network's.
            merchantDeepLink: $this->merchantDeepLink($raw),
            merchantCategory: $this->category($product),
            imageUrl: $this->image($product),
            ean: $this->ean($product),
            referencePrice: null,
            currency: $market->currency(),
            availability: $this->availability($raw),
        );
    }

    /**
     * A stable id for one advertiser's listing of one product.
     *
     * Composite, because `products` is unique on (source, external_id, market)
     * and a Tradedoubler product is sold by several advertisers at once. Keying
     * on the product alone would make those offers overwrite each other, and
     * the last one written would masquerade as the only price — turning the one
     * source that gives us a real comparison into the one that hides it.
     *
     * @param  array<string, mixed>  $product
     * @param  array<string, mixed>  $raw
     */
    private function externalId(array $product, array $raw): ?string
    {
        $productId = trim((string) (
            $product['productId'] ?? $product['id'] ?? $raw['sourceProductId'] ?? ''
        ));

        $programId = trim((string) ($raw['programId'] ?? $raw['advertiserId'] ?? ''));

        if ($productId === '') {
            return null;
        }

        // A missing program id is survivable — the product id alone is still
        // unique per product — but it collapses that product's advertisers onto
        // one row, so it is worth knowing about.
        return $programId === '' ? $productId : $productId.':'.$programId;
    }

    /** @return array{0: string, 1: ?string} */
    private function splitExternalId(string $externalId): array
    {
        $parts = explode(':', $externalId, 2);

        return [$parts[0], $parts[1] ?? null];
    }

    /**
     * Price in integer cents, or null.
     *
     * ## Two things are guarded here, and the second is the important one
     *
     * `priceHistory` is a LIST, most recent first. Reading the wrong end of it
     * would quote a price from weeks ago, which is the kind of wrong that looks
     * entirely reasonable on a card.
     *
     * And a foreign currency is dropped, never converted — the same rule as
     * eBay's, and more likely to bite here: Tradedoubler is a network spanning
     * every European market at once, so a mis-scoped query returns Swedish
     * kronor listings that look like bargains. `products.price` has no per-row
     * currency, so a converted number enters the min and median aggregates
     * behind "cheapest offer" at a rate nobody recorded.
     *
     * @param  array<string, mixed>  $raw
     */
    private function price(array $raw, Market $market, string $externalId): ?int
    {
        $price = $raw['price'] ?? null;

        if ($price === null) {
            $history = $raw['priceHistory'] ?? [];
            // Most recent first. `reset()` rather than `end()` for that reason:
            // the oldest entry in a price history is the one price that is
            // certainly not current.
            $latest = is_array($history) && $history !== [] ? reset($history) : null;
            $price = is_array($latest) ? ($latest['price'] ?? null) : null;
        }

        if (! is_array($price)) {
            return null;
        }

        $value = $price['value'] ?? $price['amount'] ?? null;
        $currency = strtoupper(trim((string) ($price['currency'] ?? '')));

        if (! is_numeric($value)) {
            return null;
        }

        if ($currency !== '' && $currency !== $market->currency()) {
            Log::info('tradedoubler offer dropped: foreign currency', [
                'market' => $market->value,
                'offer' => $externalId,
                'currency' => $currency,
            ]);

            return null;
        }

        return (int) round(((float) $value) * 100);
    }

    /**
     * The advertiser's stable key, for the merchants table.
     *
     * Prefixed, because `merchants` is unique on (source, external_id) and a
     * Tradedoubler program id is a bare integer that would be indistinguishable
     * from an Awin advertiser id in a diagnostic — and identical to one in a
     * mistaken query that forgot to filter on source.
     *
     * @param  array<string, mixed>  $raw
     */
    private function merchantId(array $raw): ?string
    {
        $programId = trim((string) ($raw['programId'] ?? $raw['advertiserId'] ?? ''));

        if ($programId !== '') {
            return 'td-'.$programId;
        }

        // No id, but a name is still enough to keep two advertisers apart.
        $name = trim((string) ($raw['programName'] ?? $raw['advertiserName'] ?? ''));

        return $name !== '' ? 'td-'.mb_strtolower($name) : null;
    }

    /**
     * The advertiser's own product URL.
     *
     * Deliberately NOT `productUrl`, which is the network's tracking link:
     * deriving a domain from that gives `tradedoubler.com` for every advertiser
     * alike, and therefore Tradedoubler's favicon on every card in the shop
     * directory. The same trap Awin's `merchantDomain()` note describes.
     *
     * Frequently absent, and null is the right answer when it is — a missing
     * favicon is better than the wrong one.
     *
     * @param  array<string, mixed>  $raw
     */
    private function merchantDeepLink(array $raw): ?string
    {
        foreach (['sourceProductUrl', 'advertiserProductUrl', 'productDeepLink'] as $field) {
            $url = trim((string) ($raw[$field] ?? ''));

            if ($url !== '' && str_starts_with($url, 'http')) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Stock, where the advertiser bothered to say.
     *
     * Routed through `Availability::fromFeedValue()`, which is the shared
     * normaliser for exactly this: network advertisers express stock a dozen
     * different ways and anything unrecognised becomes Unknown rather than
     * being optimistically read as in-stock.
     *
     * Unknown and not OutOfStock when the field is missing entirely, which is
     * the opposite of bol's inference — there a price IS the stock signal
     * because bol only returns sellable products. A network advertiser's feed
     * routinely carries priced rows for things it has run out of, so a price
     * here proves nothing.
     *
     * @param  array<string, mixed>  $raw
     */
    private function availability(array $raw): Availability
    {
        $value = $raw['availability'] ?? $raw['inStock'] ?? $raw['stockStatus'] ?? null;

        if ($value === null) {
            return Availability::Unknown;
        }

        return Availability::fromFeedValue(is_bool($value) ? ($value ? '1' : '0') : (string) $value);
    }

    /**
     * The barcode, which is what makes this source worth having.
     *
     * A Tradedoubler offer without one cannot join the group its competitors are
     * in, and an offer that cannot join a group is not a comparison — it is a
     * lone card. Every plausible spelling is tried for that reason.
     *
     * @param  array<string, mixed>  $product
     */
    private function ean(array $product): ?string
    {
        $identifiers = $product['identifiers'] ?? [];

        $candidates = [
            is_array($identifiers) ? ($identifiers['ean'] ?? null) : null,
            is_array($identifiers) ? ($identifiers['upc'] ?? null) : null,
            is_array($identifiers) ? ($identifiers['gtin'] ?? null) : null,
            $product['ean'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $ean = trim((string) ($candidate ?? ''));

            if ($ean !== '') {
                return $ean;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $product */
    private function brand(array $product): ?string
    {
        $brand = $product['brand'] ?? null;

        // Sometimes an object with a name rather than a bare string.
        if (is_array($brand)) {
            $brand = $brand['name'] ?? null;
        }

        $brand = trim((string) ($brand ?? ''));

        return $brand !== '' ? $brand : null;
    }

    /**
     * The most specific category named.
     *
     * Coarse to fine, so the last entry wins — specificity is what the
     * serendipity engine's rarity signal reads.
     *
     * @param  array<string, mixed>  $product
     */
    private function category(array $product): ?string
    {
        $categories = $product['categories'] ?? [];

        if (! is_array($categories) || $categories === []) {
            return null;
        }

        $last = end($categories);

        $name = is_array($last)
            ? trim((string) ($last['name'] ?? ''))
            : trim((string) $last);

        return $name !== '' ? $name : null;
    }

    /** @param array<string, mixed> $product */
    private function description(array $product): ?string
    {
        $raw = trim((string) ($product['description'] ?? ''));

        if ($raw === '') {
            return null;
        }

        // Advertiser copy, pasted from their own site and carrying its markup.
        // Stripped at the boundary so nothing downstream has to decide whether
        // this field is safe to render.
        $text = trim(html_entity_decode(strip_tags(str_replace(['<br />', '<br>', '</li>'], ' ', $raw))));

        return $text !== '' ? mb_substr($text, 0, 2000) : null;
    }

    /** @param array<string, mixed> $product */
    private function image(array $product): ?string
    {
        $single = $product['productImage']['url'] ?? $product['imageUrl'] ?? null;

        if (is_string($single) && $single !== '') {
            return $single;
        }

        foreach ($product['images'] ?? [] as $image) {
            $url = is_array($image) ? ($image['url'] ?? null) : $image;

            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return null;
    }

    /**
     * One bucket per endpoint, as bol and eBay do — here for a weaker reason,
     * and deliberately kept anyway.
     *
     * Tradedoubler documents no per-second limit at all, so there is no known
     * budget to split. The split is still worth having: it is what stops a busy
     * hour of searching from starving a re-check, and if a limit does turn out
     * to exist, per-bucket is the shape that lets it be tuned without touching
     * this class.
     */
    private function limiter(string $endpoint): RateLimiter
    {
        return new RateLimiter(
            bucket: "tradedoubler:{$endpoint}",
            rate: (float) config(
                "giftcoves.connectors.tradedoubler.{$endpoint}.rate",
                config('giftcoves.connectors.tradedoubler.rate'),
            ),
            capacity: (int) config(
                "giftcoves.connectors.tradedoubler.{$endpoint}.burst",
                config('giftcoves.connectors.tradedoubler.burst'),
            ),
        );
    }
}

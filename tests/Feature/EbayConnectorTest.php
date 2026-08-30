<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Services\Connectors\Ebay\EbayConnector;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * eBay is a live source, so the contract is the same one bol is held to: it
 * degrades, never throws. A dead, unconfigured or quota-exhausted eBay must
 * mean a smaller result set, not a broken search page.
 *
 * Two things here are eBay's alone and get their own tests — the currency
 * guard, because a converted price would corrupt the cheapest-offer aggregate,
 * and the campaign-id header, because without it every link works and none of
 * them earns.
 */
class EbayConnectorTest extends TestCase
{
    private EbayConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'giftcoves.connectors.ebay.enabled' => true,
            'giftcoves.connectors.ebay.client_id' => 'test-id',
            'giftcoves.connectors.ebay.client_secret' => 'test-secret',
            'giftcoves.connectors.ebay.marketplace' => [
                'be-nl' => 'EBAY_NL',
                'be-fr' => 'EBAY_FR',
                'nl-nl' => 'EBAY_NL',
                'en' => 'EBAY_NL',
                'es' => 'EBAY_ES',
            ],
            'giftcoves.connectors.ebay.campaign_id' => [
                'EBAY_NL' => '5338111111',
                'EBAY_FR' => '5338222222',
                'EBAY_ES' => null,
            ],
            'giftcoves.connectors.ebay.filter' => 'conditions:{NEW},buyingOptions:{FIXED_PRICE}',
        ]);

        Cache::flush();

        // The cache store is `array` under test, so Cache::flush() does not
        // touch the rate limiter — it talks to Redis directly, on purpose,
        // because its whole job is sharing state across processes. Without
        // this reset the bucket drains part-way through the class and later
        // tests silently make no requests at all.
        $this->resetLimiter();

        $this->connector = new EbayConnector;
    }

    /**
     * Empty the token buckets.
     *
     * Also called mid-test by anything that searches twice. The real burst is
     * ONE — `rate` 5/s, `burst` 1, sized against eBay's daily quota rather than
     * a per-second ceiling — so a second search microseconds after the first is
     * refused, makes no request, and every assertion about that request fails
     * for a reason that has nothing to do with what is being tested.
     */
    private function resetLimiter(): void
    {
        foreach (['search', 'item'] as $bucket) {
            Redis::del("bc:ratelimit:ebay:{$bucket}", "bc:ratelimit:ebay:{$bucket}:cooldown");
        }
    }

    /**
     * The token host and the API host are the same host.
     *
     * Which makes the stub patterns load-bearing, in a way that is not obvious
     * and cost an hour here. `Http::fake()` evaluates EVERY matching stub and
     * keeps the last one's response — it does not stop at the first match — so
     * a broad `api.ebay.com/*` alongside this one still *runs* for the token
     * request. With a plain response that is harmless. With `Http::sequence()`
     * it is not: the token request pops the first queued response and throws it
     * away, and the search then gets the second one. The test reads as "the
     * connector returned the wrong thing" while the connector is fine.
     *
     * So the browse stub is scoped to `api.ebay.com/buy/*`, which cannot match
     * the token URL, rather than being ordered around this one.
     *
     * @return array<string, mixed>
     */
    private function fakeToken(): array
    {
        return ['api.ebay.com/identity/*' => Http::response([
            'access_token' => 'tok',
            'token_type' => 'Application Access Token',
            'expires_in' => 7200,
        ])];
    }

    /**
     * An item summary as Browse returns one.
     *
     * Note what is NOT here: no `brand`, no `gtin`, no `shortDescription`. The
     * search endpoint returns none of the three — only the item endpoint does —
     * and a fixture that invented them would agree with our parser and with
     * nothing else, which is the failure mode BolConnectorTest's fixture note
     * describes.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function item(array $overrides = []): array
    {
        return array_merge([
            'itemId' => 'v1|123456789012|0',
            'title' => 'Sony WH-1000XM5 Draadloze Koptelefoon Zwart',
            'image' => ['imageUrl' => 'https://i.ebayimg.com/images/g/abc/s-l1600.jpg'],
            'price' => ['value' => '329.99', 'currency' => 'EUR'],
            'itemWebUrl' => 'https://www.ebay.nl/itm/123456789012',
            'itemAffiliateWebUrl' => 'https://www.ebay.nl/itm/123456789012?mkcid=1&campid=5338111111',
            'categories' => [
                ['categoryId' => '293', 'categoryName' => 'Consumer Electronics'],
                ['categoryId' => '112529', 'categoryName' => 'Headphones'],
            ],
            'marketingPrice' => ['originalPrice' => ['value' => '399.00', 'currency' => 'EUR']],
            'buyingOptions' => ['FIXED_PRICE'],
            'condition' => 'New',
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>|null  $items
     * @return array<string, mixed>
     */
    private function searchResponse(?array $items = null): array
    {
        $items ??= [$this->item()];

        // A zero-result search omits `itemSummaries` entirely rather than
        // returning an empty array — reproduced here so the parser is tested
        // against the shape eBay actually sends.
        return $items === []
            ? ['href' => 'https://api.ebay.com/buy/browse/v1/item_summary/search', 'total' => 0]
            : ['href' => 'https://api.ebay.com/buy/browse/v1/item_summary/search', 'total' => count($items), 'itemSummaries' => $items];
    }

    #[Test]
    public function it_normalises_a_search_result(): void
    {
        Http::fake([...$this->fakeToken(), 'api.ebay.com/buy/*' => Http::response($this->searchResponse())]);

        $offers = $this->connector->search('koptelefoon', Market::NlNl);

        $this->assertCount(1, $offers);
        $offer = $offers[0];

        $this->assertSame('Sony WH-1000XM5 Draadloze Koptelefoon Zwart', $offer->title);

        // Cents, not floats — 329.99 must not become 32998 or 33000.
        $this->assertSame(32999, $offer->price);
        $this->assertSame(39900, $offer->referencePrice);

        // The RESTful item id, pipes and all: it is what the item endpoint
        // takes back, so a wishlist re-check depends on storing it verbatim.
        $this->assertSame('v1|123456789012|0', $offer->externalId);

        // The deepest category, because specificity is what the rarity signal
        // reads. Coarse-to-fine, so the last entry wins.
        $this->assertSame('Headphones', $offer->merchantCategory);

        // Browse only returns buyable listings, so being returned IS the
        // availability signal — there is no flag to read.
        $this->assertSame(Availability::InStock, $offer->availability);

        /*
         * No brand and no barcode from a search, deliberately.
         *
         * Neither field exists on an item summary. Parsing a brand out of an
         * eBay title — written for eBay's own search engine, in shouting caps
         * with the shipping terms attached — would be a guess, and grouping and
         * the brand facet both key on it. BrandAttribution fills it in where
         * the query itself was a brand name.
         */
        $this->assertNull($offer->brand);
        $this->assertNull($offer->ean);
    }

    #[Test]
    public function one_merchant_row_for_ebay_not_one_per_seller(): void
    {
        Http::fake([...$this->fakeToken(), 'api.ebay.com/buy/*' => Http::response($this->searchResponse())]);

        $offer = $this->connector->search('koptelefoon', Market::NlNl)[0];

        // `merchants` is the shop directory a visitor reads as "who you
        // compare". Keying on the seller would turn a page of six shops into an
        // unbounded one and make every eBay offer a shop nobody has heard of.
        $this->assertSame('ebay', $offer->merchantExternalId);
        $this->assertSame('eBay', $offer->merchantName);

        // The domain comes from the plain listing URL, never from the affiliate
        // one, which points at eBay's click tracker.
        $this->assertSame('ebay.nl', $offer->merchantDomain());
    }

    #[Test]
    public function a_listing_priced_in_another_currency_is_dropped_not_converted(): void
    {
        Http::fake([...$this->fakeToken(), 'api.ebay.com/buy/*' => Http::response($this->searchResponse([
            $this->item(['price' => ['value' => '279.00', 'currency' => 'GBP']]),
            $this->item(['itemId' => 'v1|999|0']),
        ]))]);

        $offers = $this->connector->search('koptelefoon', Market::NlNl);

        /*
         * Every market here is euro and `products.price` has no per-row
         * currency, so a converted number would enter the min and median
         * aggregates behind "cheapest offer" at a rate nobody recorded.
         *
         * £279 stored as 27900 cents undercuts a genuine €289 offer and wins
         * the badge. Dropping it costs one result; keeping it makes the one
         * claim on the page that has to be exactly right wrong.
         */
        $this->assertCount(1, $offers);
        $this->assertSame('v1|999|0', $offers[0]->externalId);
    }

    #[Test]
    public function an_unpriced_listing_is_dropped_rather_than_stored_as_unknown(): void
    {
        Http::fake([...$this->fakeToken(), 'api.ebay.com/buy/*' => Http::response($this->searchResponse([
            $this->item(['price' => null]),
        ]))]);

        // Unlike a feed row, where a missing price is a formatting problem and
        // the product is still real, an unpriced eBay summary is an auction
        // that slipped the filter. A comparison page must not carry it.
        $this->assertSame([], $this->connector->search('koptelefoon', Market::NlNl));
    }

    #[Test]
    public function the_request_carries_the_marketplace_the_filter_and_the_campaign_id(): void
    {
        Http::fake([...$this->fakeToken(), 'api.ebay.com/buy/*' => Http::response($this->searchResponse([]))]);

        $this->connector->search('koptelefoon', Market::NlNl);

        Http::assertSent(function (Request $r): bool {
            if (! str_contains($r->url(), '/item_summary/search')) {
                return false;
            }

            return $r->header('X-EBAY-C-MARKETPLACE-ID')[0] === 'EBAY_NL'
                // Without the campaign id eBay omits itemAffiliateWebUrl and
                // every click earns nothing, silently.
                && str_contains($r->header('X-EBAY-C-ENDUSERCTX')[0], 'affiliateCampaignId=5338111111')
                && str_contains($r->header('X-EBAY-C-ENDUSERCTX')[0], 'affiliateReferenceId=giftcoves-nl-nl')
                // Auctions and used goods are excluded, or the price on screen
                // is a bid rather than a price.
                && str_contains(urldecode($r->url()), 'conditions:{NEW}')
                && str_contains(urldecode($r->url()), 'buyingOptions:{FIXED_PRICE}');
        });
    }

    #[Test]
    public function each_market_reads_its_own_marketplace(): void
    {
        Http::fake([...$this->fakeToken(), 'api.ebay.com/buy/*' => Http::response($this->searchResponse([]))]);

        $this->connector->search('casque', Market::BeFr);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/item_summary/search')
            && $r->header('X-EBAY-C-MARKETPLACE-ID')[0] === 'EBAY_FR'
            && str_contains($r->header('X-EBAY-C-ENDUSERCTX')[0], 'affiliateCampaignId=5338222222'));

        Cache::flush();
        $this->resetLimiter();

        // English has no euro marketplace of its own, so it reads the Dutch one
        // rather than EBAY_GB, whose prices are sterling and would be dropped
        // by the currency guard on every row.
        $this->connector->search('headphones', Market::En);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/item_summary/search')
            && $r->header('X-EBAY-C-MARKETPLACE-ID')[0] === 'EBAY_NL');
    }

    #[Test]
    public function a_market_with_no_marketplace_is_skipped_entirely(): void
    {
        config(['giftcoves.connectors.ebay.marketplace.es' => null]);

        Http::fake($this->fakeToken());

        // A blank marketplace means skip, never "use the default" — a request
        // to the wrong marketplace succeeds and returns priced, buyable,
        // completely irrelevant results.
        $this->assertFalse($this->connector->supports(Market::Es));
        $this->assertSame([], $this->connector->search('auriculares', Market::Es));

        Http::assertNothingSent();
    }

    #[Test]
    public function a_market_with_no_campaign_id_still_searches_and_links(): void
    {
        Http::fake([...$this->fakeToken(), 'api.ebay.com/buy/*' => Http::response($this->searchResponse([
            // No campaign id means eBay omits itemAffiliateWebUrl.
            $this->item(['itemAffiliateWebUrl' => null]),
        ]))]);

        $offers = $this->connector->search('auriculares', Market::Es);

        // The site keeps working without EPN credentials; it just earns
        // nothing, which is why bc:check-ebay reports this in red.
        $this->assertCount(1, $offers);
        $this->assertSame('https://www.ebay.nl/itm/123456789012', $offers[0]->affiliateUrl);
        $this->assertTrue($offers[0]->hasSafeAffiliateUrl());

        Http::assertSent(fn (Request $r) => ! str_contains($r->url(), '/identity/')
            && $r->header('X-EBAY-C-ENDUSERCTX') === []);
    }

    #[Test]
    public function the_affiliate_url_is_preferred_over_the_plain_one(): void
    {
        Http::fake([...$this->fakeToken(), 'api.ebay.com/buy/*' => Http::response($this->searchResponse())]);

        $offer = $this->connector->search('koptelefoon', Market::NlNl)[0];

        // The invisible failure: the plain URL works perfectly, the visitor
        // buys, and the commission goes to nobody.
        $this->assertStringContainsString('campid=5338111111', $offer->affiliateUrl);
        $this->assertTrue($offer->hasSafeAffiliateUrl());
    }

    #[Test]
    public function the_item_endpoint_supplies_the_barcode_a_search_cannot(): void
    {
        Http::fake([...$this->fakeToken(), 'api.ebay.com/buy/*' => Http::response($this->item([
            'gtin' => '4006381333931',
            'brand' => 'Sony',
            'shortDescription' => 'Ruisonderdrukking<br />Kleur: zwart',
            'categoryPath' => 'Consumer Electronics|Portable Audio|Headphones',
        ]))]);

        $offer = $this->connector->fetchById('v1|123456789012|0', Market::NlNl);

        $this->assertNotNull($offer);

        // The barcode is the whole reason a re-check is worth more than a
        // search hit: with it the offer groups against the same product at
        // another shop, without it it sits alone.
        $this->assertSame('4006381333931', $offer->ean);
        $this->assertSame('Sony', $offer->brand);
        $this->assertSame('Headphones', $offer->merchantCategory);

        // Markup stripped at the boundary, so nothing downstream has to decide
        // whether this field is safe to render.
        $this->assertStringNotContainsString('<', (string) $offer->description);

        // The pipes in the id have to survive into the path rather than being
        // read as a delimiter.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/item/')
            && str_contains(urldecode($r->url()), 'v1|123456789012|0'));
    }

    #[Test]
    public function an_ended_listing_returns_null_rather_than_throwing(): void
    {
        Http::fake([...$this->fakeToken(), 'api.ebay.com/buy/*' => Http::response([], 404)]);

        // Listings end. That is the normal case this method exists to catch,
        // not a fault to report.
        $this->assertNull($this->connector->fetchById('v1|123456789012|0', Market::NlNl));
    }

    #[Test]
    public function a_cache_hit_survives_a_round_trip_through_a_real_cache_store(): void
    {
        /*
         * The cache must hold plain arrays, never Offer objects.
         *
         * A serialised App\Services\Connectors\Offer in Redis makes every warm
         * hit depend on the class still being loadable and still having the
         * same shape, and a redeploy breaks both at once — it cost bol a 500 on
         * every cached search. The array cache store used in tests hands the
         * same instance back without serialising, so the suite is blind to it
         * unless a test forces a real round trip.
         */
        Http::fake([...$this->fakeToken(), 'api.ebay.com/buy/*' => Http::response($this->searchResponse())]);

        $this->connector->search('koptelefoon', Market::NlNl);

        $key = sprintf('bc:ebay:search:%s:%s:%d', Market::NlNl->value, sha1('koptelefoon'), 24);
        $cached = Cache::get($key);

        $this->assertIsArray($cached);
        $this->assertNotEmpty($cached);

        foreach ($cached as $entry) {
            $this->assertIsArray($entry, 'A domain object reached the cache.');
        }

        Cache::put($key, unserialize(serialize($cached)), 60);

        // No reset needed: this second search is a cache HIT, so it never
        // reaches the limiter. That is the property being tested.
        $offers = $this->connector->search('koptelefoon', Market::NlNl);

        $this->assertCount(1, $offers);
        $this->assertSame(32999, $offers[0]->price);
    }

    #[Test]
    public function an_empty_result_is_not_cached(): void
    {
        // A sequence, not two fake() calls: Http::fake() MERGES stubs rather
        // than replacing them, so a second registration for the same URL never
        // takes effect and the first response answers both requests.
        Http::fake([
            ...$this->fakeToken(),
            'api.ebay.com/buy/*' => Http::sequence()
                ->push($this->searchResponse([]))
                ->push($this->searchResponse()),
        ]);

        $this->assertSame([], $this->connector->search('niets', Market::NlNl));

        $this->resetLimiter();

        // Every degraded path returns an empty array — a spent quota, a
        // timeout, a dead token. Caching one blanks eBay for this query long
        // after the cause has cleared.
        $this->assertCount(1, $this->connector->search('niets', Market::NlNl));
    }

    #[Test]
    public function a_429_triggers_a_cooldown_rather_than_a_retry_storm(): void
    {
        Http::fake([...$this->fakeToken(), 'api.ebay.com/buy/*' => Http::response([], 429)]);

        $this->assertSame([], $this->connector->search('koptelefoon', Market::NlNl));

        // eBay's Browse limit is a daily quota, so a 429 usually means the day
        // is spent. The registry drops a cooling-down connector out of
        // liveFor(), so search stops asking rather than degrading per request.
        $this->assertTrue($this->connector->isCoolingDown());

        Cache::forget(sprintf('bc:ebay:search:%s:%s:%d', Market::NlNl->value, sha1('koptelefoon'), 24));
        $this->assertSame([], $this->connector->search('koptelefoon', Market::NlNl));
    }

    #[Test]
    public function an_upstream_error_degrades_instead_of_throwing(): void
    {
        Http::fake([...$this->fakeToken(), 'api.ebay.com/buy/*' => Http::response([], 503)]);

        // Search must keep working on the remaining sources.
        $this->assertSame([], $this->connector->search('koptelefoon', Market::NlNl));
    }

    #[Test]
    public function a_failed_token_request_degrades_instead_of_throwing(): void
    {
        Http::fake(['api.ebay.com/identity/*' => Http::response([], 401)]);

        $this->assertSame([], $this->connector->search('koptelefoon', Market::NlNl));
    }

    #[Test]
    public function it_is_inert_without_credentials(): void
    {
        config(['giftcoves.connectors.ebay.client_id' => null]);

        Http::fake();

        // `enabled => true` in config costs nothing on an environment that has
        // not been given keys — which is what lets this ship before the eBay
        // developer account exists.
        $this->assertFalse($this->connector->supports(Market::NlNl));
        $this->assertSame([], $this->connector->search('koptelefoon', Market::NlNl));

        Http::assertNothingSent();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Services\Connectors\Offer;
use App\Services\Connectors\Tradedoubler\TradedoublerConnector;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tradedoubler is a live source, so it degrades and never throws — and it is a
 * NETWORK, which is where its own tests are.
 *
 * The fan-out is the thing to hold onto: one payload product becomes several
 * offers from several advertisers, and every failure specific to this connector
 * is a failure of that. Offers collapsing onto one external id, every offer
 * carrying the network's name instead of the shop's, a foreign-currency listing
 * from a mis-scoped query undercutting a real price — each has a test below,
 * because none of them looks like an error from the outside.
 *
 * The fixture is Tradedoubler's DOCUMENTED shape, not one read off a live
 * response: the outbound probe was blocked when this was written. So these
 * tests prove the connector does what it intends with that shape — they cannot
 * prove the shape. `bc:check-tradedoubler --raw` is what does that, and until
 * it has been run against production this suite being green means less than it
 * usually would.
 */
class TradedoublerConnectorTest extends TestCase
{
    private TradedoublerConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'giftcoves.connectors.tradedoubler.enabled' => true,
            'giftcoves.connectors.tradedoubler.token' => 'test-token',
            'giftcoves.connectors.tradedoubler.query' => [
                'be-nl' => ['language' => 'nl'],
                'be-fr' => ['language' => 'fr'],
                'nl-nl' => ['language' => 'nl'],
                'en' => ['language' => 'nl'],
                'es' => ['language' => 'es'],
            ],
        ]);

        Cache::flush();
        $this->resetLimiter();

        $this->connector = new TradedoublerConnector;
    }

    /**
     * Empty the token buckets.
     *
     * Also called mid-test by anything that searches twice: `burst` is 1, so a
     * second search microseconds after the first is refused, makes no request,
     * and every assertion about that request fails for a reason that has
     * nothing to do with what is being tested.
     */
    private function resetLimiter(): void
    {
        foreach (['search', 'product'] as $bucket) {
            Redis::del("bc:ratelimit:tradedoubler:{$bucket}", "bc:ratelimit:tradedoubler:{$bucket}:cooldown");
        }
    }

    /**
     * One product, sold by two advertisers — the shape this whole connector
     * exists for.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function product(array $overrides = []): array
    {
        return array_merge([
            'productId' => '772211',
            'name' => 'Sony WH-1000XM5 Draadloze Koptelefoon',
            'description' => 'Ruisonderdrukking<br /><ul><li>Kleur: zwart</li></ul>',
            'brand' => 'Sony',
            'productImage' => ['url' => 'https://img.tradedoubler.com/772211.jpg'],
            'identifiers' => ['ean' => '4006381333931', 'mpn' => 'WH1000XM5B'],
            'categories' => [
                ['name' => 'Electronics'],
                ['name' => 'Koptelefoons'],
            ],
            'offers' => [
                [
                    'programId' => '241234',
                    'programName' => 'Coolblue',
                    'productUrl' => 'https://clk.tradedoubler.com/click?p=241234&a=999&url=coolblue',
                    'sourceProductUrl' => 'https://www.coolblue.nl/product/772211',
                    'priceHistory' => [
                        ['price' => ['value' => '329.99', 'currency' => 'EUR']],
                        ['price' => ['value' => '349.00', 'currency' => 'EUR']],
                    ],
                    'availability' => 'in stock',
                ],
                [
                    'programId' => '335566',
                    'programName' => 'MediaMarkt',
                    'productUrl' => 'https://clk.tradedoubler.com/click?p=335566&a=999&url=mediamarkt',
                    'sourceProductUrl' => 'https://www.mediamarkt.nl/product/772211',
                    'priceHistory' => [
                        ['price' => ['value' => '319.00', 'currency' => 'EUR']],
                    ],
                    'availability' => 'in stock',
                ],
            ],
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>|null  $products
     * @return array<string, mixed>
     */
    private function searchResponse(?array $products = null): array
    {
        $products ??= [$this->product()];

        return ['products' => $products, 'totalHits' => count($products)];
    }

    #[Test]
    public function one_product_becomes_one_offer_per_advertiser(): void
    {
        Http::fake(['api.tradedoubler.com/*' => Http::response($this->searchResponse())]);

        $offers = $this->connector->search('koptelefoon', Market::NlNl);

        /*
         * The whole reason this source is here.
         *
         * `products` rows are OFFERS and `product_groups` rows are physical
         * products (invariant 3), and Tradedoubler hands that split over
         * already made. Collapsing these two into one row would throw away the
         * only thing the network gives us that bol and eBay cannot: two shops'
         * prices for one thing, in one request.
         */
        $this->assertCount(2, $offers);

        $this->assertSame(['Coolblue', 'MediaMarkt'], array_map(
            fn (Offer $o): string => (string) $o->merchantName,
            $offers,
        ));

        // Same product, so the same barcode — which is what puts them in one
        // group and makes them a comparison rather than two lone cards.
        $this->assertSame(['4006381333931', '4006381333931'], array_map(
            fn (Offer $o): string => (string) $o->ean,
            $offers,
        ));
    }

    #[Test]
    public function each_advertisers_offer_gets_its_own_external_id(): void
    {
        Http::fake(['api.tradedoubler.com/*' => Http::response($this->searchResponse())]);

        $offers = $this->connector->search('koptelefoon', Market::NlNl);

        /*
         * `products` is unique on (source, external_id, market).
         *
         * Keying on the product alone would make these two offers overwrite
         * each other, and the last one written would masquerade as the only
         * price — turning the one source that gives us a real comparison into
         * the one that hides it.
         */
        $this->assertSame('772211:241234', $offers[0]->externalId);
        $this->assertSame('772211:335566', $offers[1]->externalId);
    }

    #[Test]
    public function the_same_offer_twice_in_one_payload_is_written_once(): void
    {
        // Paging overlap, or one shop listing a product twice.
        Http::fake(['api.tradedoubler.com/*' => Http::response($this->searchResponse([
            $this->product(),
            $this->product(),
        ]))]);

        $offers = $this->connector->search('koptelefoon', Market::NlNl);

        /*
         * OfferUpserter writes a batch in ONE upsert, so a duplicate does not
         * merely waste a row: Postgres refuses the whole statement with "ON
         * CONFLICT DO UPDATE command cannot affect row a second time" and the
         * entire search's worth of offers is lost. bol hit exactly this on its
         * first live chart run.
         */
        $this->assertCount(2, $offers);
        $this->assertSame(
            ['772211:241234', '772211:335566'],
            array_map(fn (Offer $o): string => $o->externalId, $offers),
        );
    }

    #[Test]
    public function it_normalises_the_rest_of_the_fields(): void
    {
        Http::fake(['api.tradedoubler.com/*' => Http::response($this->searchResponse())]);

        $offer = $this->connector->search('koptelefoon', Market::NlNl)[0];

        $this->assertSame('Sony WH-1000XM5 Draadloze Koptelefoon', $offer->title);

        // Cents, not floats — and the FIRST price-history entry, which is the
        // current one. The oldest entry is the one price certainly not current.
        $this->assertSame(32999, $offer->price);

        // The network returns a brand, unlike bol and eBay's search. Taken as
        // given: it is the advertiser's own field, and grouping keys on it
        // whenever the barcode is missing.
        $this->assertSame('Sony', $offer->brand);

        // Deepest category, because specificity is what the rarity signal reads.
        $this->assertSame('Koptelefoons', $offer->merchantCategory);

        $this->assertSame(Availability::InStock, $offer->availability);

        // Markup stripped at the boundary, so nothing downstream has to decide
        // whether this field is safe to render.
        $this->assertStringNotContainsString('<', (string) $offer->description);
    }

    #[Test]
    public function the_merchant_is_the_advertiser_and_the_domain_is_theirs(): void
    {
        Http::fake(['api.tradedoubler.com/*' => Http::response($this->searchResponse())]);

        $offer = $this->connector->search('koptelefoon', Market::NlNl)[0];

        // Prefixed so a Tradedoubler program id cannot be confused with an Awin
        // advertiser id — both are bare integers.
        $this->assertSame('td-241234', $offer->merchantExternalId);

        /*
         * The domain comes from the advertiser's own URL, never from the
         * tracking link.
         *
         * Deriving it from `productUrl` would give tradedoubler.com for every
         * advertiser alike — and therefore the network's favicon on every card
         * in the shop directory, which is exactly the failure Awin's
         * merchantDomain() note describes.
         */
        $this->assertSame('coolblue.nl', $offer->merchantDomain());
        $this->assertStringContainsString('clk.tradedoubler.com', $offer->affiliateUrl);
    }

    #[Test]
    public function a_foreign_currency_offer_is_dropped_not_converted(): void
    {
        Http::fake(['api.tradedoubler.com/*' => Http::response($this->searchResponse([
            $this->product(['offers' => [
                [
                    'programId' => '111',
                    'programName' => 'Elgiganten',
                    'productUrl' => 'https://clk.tradedoubler.com/click?p=111',
                    'priceHistory' => [['price' => ['value' => '3499.00', 'currency' => 'SEK']]],
                ],
                [
                    'programId' => '222',
                    'programName' => 'Coolblue',
                    'productUrl' => 'https://clk.tradedoubler.com/click?p=222',
                    'priceHistory' => [['price' => ['value' => '329.99', 'currency' => 'EUR']]],
                ],
            ]]),
        ]))]);

        $offers = $this->connector->search('koptelefoon', Market::NlNl);

        /*
         * More likely to bite here than anywhere else.
         *
         * Tradedoubler spans every European market at once and ignores a filter
         * parameter it does not recognise, so a mis-scoped query returns Swedish
         * listings that look like bargains. `products.price` has no per-row
         * currency, so 3499 kronor stored as 349900 cents is merely expensive —
         * but 99 kronor stored as 9900 wins "cheapest offer" outright.
         *
         * This guard holds even when the market scoping is wrong, which is the
         * point of having it as well as the scoping.
         */
        $this->assertCount(1, $offers);
        $this->assertSame('Coolblue', $offers[0]->merchantName);
    }

    #[Test]
    public function an_unpriced_offer_is_dropped(): void
    {
        Http::fake(['api.tradedoubler.com/*' => Http::response($this->searchResponse([
            $this->product(['offers' => [
                ['programId' => '111', 'programName' => 'Shop', 'productUrl' => 'https://clk.tradedoubler.com/click?p=111'],
            ]]),
        ]))]);

        // A network offer with no price contributes nothing to a comparison and
        // would still occupy a merchant slot on the card.
        $this->assertSame([], $this->connector->search('koptelefoon', Market::NlNl));
    }

    #[Test]
    public function a_product_nobody_is_selling_is_skipped(): void
    {
        Http::fake(['api.tradedoubler.com/*' => Http::response($this->searchResponse([
            $this->product(['offers' => []]),
        ]))]);

        // A catalogue entry with no advertisers is not buyable and has nothing
        // to click.
        $this->assertSame([], $this->connector->search('koptelefoon', Market::NlNl));
    }

    #[Test]
    public function missing_stock_is_unknown_not_in_stock(): void
    {
        Http::fake(['api.tradedoubler.com/*' => Http::response($this->searchResponse([
            $this->product(['offers' => [
                [
                    'programId' => '111',
                    'programName' => 'Shop',
                    'productUrl' => 'https://clk.tradedoubler.com/click?p=111',
                    'priceHistory' => [['price' => ['value' => '10.00', 'currency' => 'EUR']]],
                ],
            ]]),
        ]))]);

        $offer = $this->connector->search('koptelefoon', Market::NlNl)[0];

        /*
         * The opposite inference from bol's, deliberately.
         *
         * There a price IS the stock signal, because bol only returns products
         * it can sell. A network advertiser's feed routinely carries priced rows
         * for things it has run out of, so a price here proves nothing — and
         * showing a sold-out product as available is the worse failure.
         */
        $this->assertSame(Availability::Unknown, $offer->availability);
    }

    #[Test]
    public function the_request_carries_the_token_and_this_markets_scoping(): void
    {
        Http::fake(['api.tradedoubler.com/*' => Http::response($this->searchResponse([]))]);

        $this->connector->search('casque', Market::BeFr);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.tradedoubler.com')
            && str_contains($r->url(), 'token=test-token')
            && str_contains($r->url(), 'q=casque')
            // Without scoping the network answers for all of Europe at once,
            // and every result looks perfectly plausible.
            && str_contains($r->url(), 'language=fr'));
    }

    #[Test]
    public function a_market_with_no_scoping_is_skipped_entirely(): void
    {
        config(['giftcoves.connectors.tradedoubler.query.es' => []]);

        Http::fake();

        // Never "ask unscoped": an unrecognised filter is ignored rather than
        // rejected, so an unscoped call returns the whole European network and
        // a Spanish visitor gets German offers with nothing reporting a problem.
        $this->assertFalse($this->connector->supports(Market::Es));
        $this->assertSame([], $this->connector->search('auriculares', Market::Es));

        Http::assertNothingSent();
    }

    #[Test]
    public function an_unrecognised_envelope_yields_nothing_rather_than_breaking(): void
    {
        // The failure this connector is most likely to have, since it was
        // written without a live response to read. It has to degrade like any
        // other, and it logs the real keys so the mapping can be corrected.
        Http::fake(['api.tradedoubler.com/*' => Http::response(['items' => [$this->product()]])]);

        $this->assertSame([], $this->connector->search('koptelefoon', Market::NlNl));
    }

    #[Test]
    public function fetch_by_id_returns_only_an_exact_match(): void
    {
        Http::fake(['api.tradedoubler.com/*' => Http::response($this->searchResponse())]);

        $offer = $this->connector->fetchById('772211:335566', Market::NlNl);

        $this->assertNotNull($offer);
        $this->assertSame('MediaMarkt', $offer->merchantName);
        $this->assertSame(31900, $offer->price);
    }

    #[Test]
    public function fetch_by_id_refuses_a_near_miss(): void
    {
        Http::fake(['api.tradedoubler.com/*' => Http::response($this->searchResponse())]);

        /*
         * There is no single-product endpoint, so this is a search with an
         * exact-match filter on top.
         *
         * Accepting "close enough" would silently repoint a wishlist item at a
         * different shop's offer of the same product — a worse outcome than
         * failing to refresh, because nothing about the item would look wrong.
         */
        $this->assertNull($this->connector->fetchById('772211:999999', Market::NlNl));
    }

    #[Test]
    public function a_cache_hit_survives_a_round_trip_through_a_real_cache_store(): void
    {
        // Only plain arrays may reach a shared cache: a serialised domain object
        // breaks on the redeploy that changes its shape, which cost bol a 500 on
        // every warm search.
        Http::fake(['api.tradedoubler.com/*' => Http::response($this->searchResponse())]);

        $this->connector->search('koptelefoon', Market::NlNl);

        $key = sprintf('bc:td:search:%s:%s:%d', Market::NlNl->value, sha1('koptelefoon'), 24);
        $cached = Cache::get($key);

        $this->assertIsArray($cached);
        $this->assertNotEmpty($cached);

        foreach ($cached as $entry) {
            $this->assertIsArray($entry, 'A domain object reached the cache.');
        }

        Cache::put($key, unserialize(serialize($cached)), 60);

        // A cache hit never reaches the limiter, which is why this needs no
        // reset — and is the property being tested.
        $offers = $this->connector->search('koptelefoon', Market::NlNl);

        $this->assertCount(2, $offers);
        $this->assertSame(32999, $offers[0]->price);
    }

    #[Test]
    public function an_empty_result_is_not_cached(): void
    {
        // A sequence, not two fake() calls: Http::fake() MERGES stubs, and
        // every matching stub is evaluated, so a second registration for the
        // same URL never replaces the first.
        Http::fake([
            'api.tradedoubler.com/*' => Http::sequence()
                ->push($this->searchResponse([]))
                ->push($this->searchResponse()),
        ]);

        $this->assertSame([], $this->connector->search('niets', Market::NlNl));

        $this->resetLimiter();

        // Every degraded path returns an empty array — a rejected token, a
        // timeout, a rate limit. Caching one blanks the source for this query
        // long after the cause has cleared.
        $this->assertCount(2, $this->connector->search('niets', Market::NlNl));
    }

    #[Test]
    public function a_429_triggers_a_cooldown_rather_than_a_retry_storm(): void
    {
        Http::fake(['api.tradedoubler.com/*' => Http::response([], 429)]);

        $this->assertSame([], $this->connector->search('koptelefoon', Market::NlNl));

        // Tradedoubler documents no per-second limit, so there is no way to
        // know how long a 429 lasts. Backing off long costs a few minutes of
        // one source; backing off short risks being blocked outright.
        $this->assertTrue($this->connector->isCoolingDown());
    }

    #[Test]
    public function a_rejected_token_backs_off_instead_of_retrying_every_search(): void
    {
        // The real response, observed: Tradedoubler answers 403 with this body
        // for a token it does not recognise.
        Http::fake(['api.tradedoubler.com/*' => Http::response(
            ['message' => 'Invalid token, Request not Authorised', 'statuscode' => '4001'],
            403,
        )]);

        $this->assertSame([], $this->connector->search('koptelefoon', Market::NlNl));

        /*
         * A rejected credential is not transient.
         *
         * There is no cached token to discard here — the credential IS the
         * config value — so nothing changes until somebody edits the
         * environment. Without the back-off every search would spend a fresh
         * request, and up to eight seconds of a visitor's page load,
         * rediscovering that the affiliate account is dead. An empty result is
         * deliberately not cached, so there is nothing else to stop the repeat.
         */
        $this->assertTrue($this->connector->isCoolingDown());

        $this->assertSame([], $this->connector->search('iets anders', Market::NlNl));

        // Exactly one request for both searches: the second never leaves, and
        // the first was not retried. A 403 answers the same way twice, so the
        // retry that helps a timeout only doubles the cost here.
        Http::assertSentCount(1);
    }

    #[Test]
    public function an_upstream_error_degrades_instead_of_throwing(): void
    {
        Http::fake(['api.tradedoubler.com/*' => Http::response([], 503)]);

        $this->assertSame([], $this->connector->search('koptelefoon', Market::NlNl));
    }

    #[Test]
    public function it_is_inert_without_a_token(): void
    {
        config(['giftcoves.connectors.tradedoubler.token' => null]);

        Http::fake();

        $this->assertFalse($this->connector->supports(Market::NlNl));
        $this->assertSame([], $this->connector->search('koptelefoon', Market::NlNl));

        Http::assertNothingSent();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Services\Connectors\Bol\BolConnector;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * bol is a live source, so the contract that matters is: it degrades, never
 * throws. A dead or rate-limited bol must mean a smaller result set, not a
 * broken search page.
 */
class BolConnectorTest extends TestCase
{
    private BolConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'brandcoves.connectors.bol.enabled' => true,
            'brandcoves.connectors.bol.client_id' => 'test-id',
            'brandcoves.connectors.bol.client_secret' => 'test-secret',
            'brandcoves.connectors.bol.partner_site_id' => ['BE' => '25421', 'NL' => '1005548'],
        ]);

        Cache::flush();

        // The cache store is `array` under test, so Cache::flush() does not
        // touch the rate limiter — it talks to Redis directly, on purpose,
        // because its whole job is sharing state across processes. Without
        // this reset the bucket drains part-way through the class and later
        // tests silently make no requests at all.
        foreach (['search', 'product'] as $bucket) {
            Redis::del("bc:ratelimit:bol:{$bucket}", "bc:ratelimit:bol:{$bucket}:cooldown");
        }

        $this->connector = new BolConnector;
    }

    private function fakeToken(): array
    {
        return ['login.bol.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 300])];
    }

    /**
     * bol's actual response shape.
     *
     * Copied from v1's connector, which has been running against this API in
     * production — not from the docs and not from what our own parser happens
     * to expect. The previous version of this fixture used `products` and
     * `images[]`, which the parser also used, so the pair agreed with each
     * other and with nothing else. Green tests, zero live results: the same
     * failure as the Awin barcode-column bug.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function product(array $overrides = []): array
    {
        return array_merge([
            'bolProductId' => '9200000123456',
            'ean' => '4006381333931',
            'title' => 'Sony WH-1000XM5 Koptelefoon',
            // HTML, as bol actually sends it.
            'description' => 'Ruisonderdrukking<br /><ul><li>Kleur: Zwart</li></ul>',
            'url' => 'https://www.bol.com/nl/p/sony/9200000123456/',
            // Singular object on search results, not a plural array.
            'image' => ['url' => 'https://media.bol.com/1.jpg'],
            // GPC taxonomy, coarse to fine. No `attributes`, and no brand
            // anywhere in the payload.
            'gpc' => [
                ['level' => 'SEGMENT', 'name' => 'Audio Visual/Photography'],
                ['level' => 'CHUNK', 'name' => 'Koptelefoon'],
            ],
            // No `available` flag — the presence of a price is the signal.
            'offer' => ['price' => 329.99, 'strikethroughPrice' => 399.00],
        ], $overrides);
    }

    /**
     * The search envelope: `results`, not `products`.
     *
     * @param  list<array<string, mixed>>|null  $products
     * @return array<string, mixed>
     */
    private function searchResponse(?array $products = null): array
    {
        return ['results' => $products ?? [$this->product()]];
    }

    #[Test]
    public function it_normalises_a_search_result(): void
    {
        Http::fake([...$this->fakeToken(), 'api.bol.com/*' => Http::response($this->searchResponse())]);

        $offers = $this->connector->search('koptelefoon', Market::BeNl);

        $this->assertCount(1, $offers);
        $offer = $offers[0];

        $this->assertSame('Sony WH-1000XM5 Koptelefoon', $offer->title);
        // Cents, not floats — 329.99 must not become 32998 or 33000.
        $this->assertSame(32999, $offer->price);
        $this->assertSame('4006381333931', $offer->ean);

        // bolProductId, not the EAN: the EAN identifies the product, and two
        // listings of it would collide on one external id.
        $this->assertSame('9200000123456', $offer->externalId);

        // The deepest GPC level, because specificity is what makes a category
        // useful for grouping and for the rarity signal.
        $this->assertSame('Koptelefoon', $offer->merchantCategory);

        // Markup stripped at the boundary, so nothing downstream has to decide
        // whether this field is safe to render.
        $this->assertStringNotContainsString('<', (string) $offer->description);

        /*
         * No brand, deliberately.
         *
         * This endpoint does not return one, and guessing it from the title is
         * worse than leaving it null — grouping and the brand facet both key on
         * it, so a wrong brand splits a product or mislabels a filter.
         */
        $this->assertNull($offer->brand);

        // The response carries no `available` flag. bol only returns an offer
        // block for something it can sell, so a price IS the signal — reading
        // a missing flag as "out of stock" would remove bol from the site.
        $this->assertSame(Availability::InStock, $offer->availability);
    }

    #[Test]
    public function a_result_with_no_offer_block_is_out_of_stock(): void
    {
        Http::fake([...$this->fakeToken(), 'api.bol.com/*' => Http::response(
            $this->searchResponse([$this->product(['offer' => []])])
        )]);

        $offer = $this->connector->search('koptelefoon', Market::BeNl)[0];

        $this->assertNull($offer->price);
        $this->assertSame(Availability::OutOfStock, $offer->availability);
    }

    #[Test]
    public function a_cache_hit_survives_a_round_trip_through_a_real_cache_store(): void
    {
        /*
         * The cache must hold plain arrays, never Offer objects.
         *
         * Caching the objects put a serialised App\Services\Connectors\Offer
         * into Redis, and reading it back gave "tried to call a method on an
         * incomplete object" — a 500 on every search that hit a warm cache,
         * which is to say almost all of them. The array cache store used in
         * tests hands the same instance back without serialising, so the suite
         * was blind to it; this test forces a real serialize/unserialize.
         */
        Http::fake([...$this->fakeToken(), 'api.bol.com/*' => Http::response($this->searchResponse())]);

        $this->connector->search('koptelefoon', Market::BeNl);

        $key = sprintf('bc:bol:search:%s:%s:%d', Market::BeNl->value, sha1('koptelefoon'), 24);
        $cached = Cache::get($key);

        $this->assertIsArray($cached);
        $this->assertNotEmpty($cached);

        foreach ($cached as $entry) {
            $this->assertIsArray($entry, 'A domain object reached the cache.');
        }

        // Round-trip it the way a shared cache store does.
        $revived = unserialize(serialize($cached));
        Cache::put($key, $revived, 60);

        $offers = $this->connector->search('koptelefoon', Market::BeNl);

        $this->assertCount(1, $offers);
        $this->assertSame(32999, $offers[0]->price);
        $this->assertStringStartsWith('https://partner.bol.com/', $offers[0]->affiliateUrl);
    }

    #[Test]
    public function an_empty_result_is_not_cached(): void
    {
        // A sequence, not two fake() calls: Http::fake() MERGES stubs rather
        // than replacing them, so a second registration for the same URL never
        // takes effect and the first response answers both requests.
        Http::fake([
            ...$this->fakeToken(),
            'api.bol.com/*' => Http::sequence()
                ->push($this->searchResponse([]))
                ->push($this->searchResponse()),
        ]);

        $this->assertSame([], $this->connector->search('niets', Market::BeNl));

        /*
         * Every degraded path returns an empty array — expired token, rate
         * limit, timeout. Caching that would blank bol for this query for the
         * full fifteen minutes, long after the cause had cleared.
         *
         * Cost an hour of debugging: the connector was fixed and working, and a
         * poisoned cache entry from an earlier broken run kept reporting
         * nothing.
         */
        $this->assertCount(1, $this->connector->search('niets', Market::BeNl));
    }

    #[Test]
    public function the_affiliate_url_goes_through_bols_click_tracker(): void
    {
        Http::fake([...$this->fakeToken(), 'api.bol.com/*' => Http::response($this->searchResponse())]);

        $offer = $this->connector->search('koptelefoon', Market::BeNl)[0];

        /*
         * bol attributes a sale through partner.bol.com carrying a site id, not
         * through a parameter appended to the product URL.
         *
         * This is the one bug in the connector that is invisible from outside:
         * an untracked link works perfectly — the visitor clicks, the visitor
         * buys — and the commission simply goes to nobody. It shows up as an
         * empty statement months later, never as an error.
         */
        $this->assertStringStartsWith('https://partner.bol.com/click/click?', $offer->affiliateUrl);
        $this->assertStringContainsString('s=25421', $offer->affiliateUrl);
        $this->assertStringContainsString(urlencode('https://www.bol.com/nl/p/sony/9200000123456/'), $offer->affiliateUrl);
        $this->assertTrue($offer->hasSafeAffiliateUrl());
    }

    #[Test]
    public function each_country_earns_on_its_own_partner_account(): void
    {
        Http::fake([...$this->fakeToken(), 'api.bol.com/*' => Http::response($this->searchResponse())]);

        // Belgium and the Netherlands are separate partner accounts. Sending a
        // Dutch click on the Belgian site id earns nothing on either.
        $this->assertStringContainsString('s=25421', $this->connector->search('x', Market::BeNl)[0]->affiliateUrl);

        Cache::flush();
        $this->assertStringContainsString('s=1005548', $this->connector->search('x', Market::NlNl)[0]->affiliateUrl);
    }

    #[Test]
    public function the_search_request_asks_for_the_offer_and_the_image(): void
    {
        Http::fake([...$this->fakeToken(), 'api.bol.com/*' => Http::response($this->searchResponse([]))]);

        $this->connector->search('koptelefoon', Market::BeNl);

        // Without include-offer bol returns the catalogue entry with no price,
        // and without include-image there is nothing to render. Both would
        // produce results that pass validation and are useless on a card.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'search-term=koptelefoon')
            && str_contains($r->url(), 'include-offer=true')
            && str_contains($r->url(), 'include-image=true'));
    }

    #[Test]
    public function each_market_gets_its_own_country_and_language(): void
    {
        Http::fake([...$this->fakeToken(), 'api.bol.com/*' => Http::response($this->searchResponse([]))]);

        $this->connector->search('test', Market::BeFr);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.bol.com')
            && str_contains($r->url(), 'country-code=BE')
            && $r->header('Accept-Language')[0] === 'fr-BE');

        Cache::flush();
        $this->connector->search('test', Market::NlNl);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.bol.com')
            && str_contains($r->url(), 'country-code=NL'));
    }

    #[Test]
    public function english_falls_back_to_dutch_because_bol_has_no_english_catalogue(): void
    {
        Http::fake([...$this->fakeToken(), 'api.bol.com/*' => Http::response($this->searchResponse([]))]);

        $this->connector->search('headphones', Market::En);

        // Dutch product names beat no results at all.
        Http::assertSent(fn (Request $r) => ! str_contains($r->url(), 'login.bol.com')
            && $r->header('Accept-Language')[0] === 'nl');
    }

    #[Test]
    public function spain_is_skipped_entirely(): void
    {
        Http::fake($this->fakeToken());

        // bol does not operate in Spain. A null country means skip, never
        // "use the default" — which would show Belgian stock to Spanish users.
        $this->assertFalse($this->connector->supports(Market::Es));
        $this->assertSame([], $this->connector->search('auriculares', Market::Es));

        Http::assertNothingSent();
    }

    #[Test]
    public function a_429_triggers_a_cooldown_rather_than_a_retry_storm(): void
    {
        Http::fake([...$this->fakeToken(), 'api.bol.com/*' => Http::response([], 429)]);

        $this->assertSame([], $this->connector->search('koptelefoon', Market::BeNl));
        $this->assertTrue($this->connector->isCoolingDown());

        // While cooling down nothing further is attempted at all.
        Cache::forget('bc:bol:search:be-nl:'.sha1('koptelefoon').':24');
        $this->assertSame([], $this->connector->search('koptelefoon', Market::BeNl));
    }

    #[Test]
    public function an_upstream_error_degrades_instead_of_throwing(): void
    {
        Http::fake([...$this->fakeToken(), 'api.bol.com/*' => Http::response([], 503)]);

        // Search must keep working on the remaining sources.
        $this->assertSame([], $this->connector->search('koptelefoon', Market::BeNl));
    }

    #[Test]
    public function a_failed_token_request_degrades_instead_of_throwing(): void
    {
        Http::fake(['login.bol.com/*' => Http::response([], 401)]);

        $this->assertSame([], $this->connector->search('koptelefoon', Market::BeNl));
    }

    #[Test]
    public function results_are_cached_so_a_burst_costs_one_call(): void
    {
        Http::fake([...$this->fakeToken(), 'api.bol.com/*' => Http::response($this->searchResponse())]);

        $this->connector->search('koptelefoon', Market::BeNl);
        $this->connector->search('koptelefoon', Market::BeNl);
        $this->connector->search('koptelefoon', Market::BeNl);

        // One search request; the token call is separate and also cached.
        Http::assertSentCount(2);
    }

    #[Test]
    public function it_is_disabled_without_credentials(): void
    {
        config(['brandcoves.connectors.bol.client_id' => null]);
        Http::fake();

        $this->assertFalse($this->connector->supports(Market::BeNl));
        $this->assertSame([], $this->connector->search('test', Market::BeNl));
        Http::assertNothingSent();
    }
}

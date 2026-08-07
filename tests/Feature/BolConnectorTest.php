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
            'brandcoves.connectors.bol.partner_id' => 'partner123',
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

    private function product(array $overrides = []): array
    {
        return array_merge([
            'id' => '9200000123456',
            'ean' => '4006381333931',
            'title' => 'Sony WH-1000XM5 Koptelefoon',
            'summary' => 'Ruisonderdrukking',
            'url' => 'https://www.bol.com/nl/p/sony/9200000123456/',
            'images' => [['url' => 'https://media.bol.com/1.jpg']],
            'attributes' => [
                ['key' => 'brand', 'value' => 'Sony'],
                ['key' => 'category', 'value' => 'Audio'],
            ],
            'offer' => ['price' => 329.99, 'available' => true],
        ], $overrides);
    }

    #[Test]
    public function it_normalises_a_search_result(): void
    {
        Http::fake([...$this->fakeToken(), 'api.bol.com/*' => Http::response(['products' => [$this->product()]])]);

        $offers = $this->connector->search('koptelefoon', Market::BeNl);

        $this->assertCount(1, $offers);
        $offer = $offers[0];

        $this->assertSame('Sony WH-1000XM5 Koptelefoon', $offer->title);
        $this->assertSame('Sony', $offer->brand);
        // Cents, not floats — 329.99 must not become 32998 or 33000.
        $this->assertSame(32999, $offer->price);
        $this->assertSame(Availability::InStock, $offer->availability);
        $this->assertSame('4006381333931', $offer->ean);
    }

    #[Test]
    public function the_affiliate_url_carries_the_partner_id(): void
    {
        Http::fake([...$this->fakeToken(), 'api.bol.com/*' => Http::response(['products' => [$this->product()]])]);

        $offer = $this->connector->search('koptelefoon', Market::BeNl)[0];

        // Without this the click is real but earns nothing.
        $this->assertStringContainsString('partner123', $offer->affiliateUrl);
        $this->assertTrue($offer->hasSafeAffiliateUrl());
    }

    #[Test]
    public function each_market_gets_its_own_country_and_language(): void
    {
        Http::fake([...$this->fakeToken(), 'api.bol.com/*' => Http::response(['products' => []])]);

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
        Http::fake([...$this->fakeToken(), 'api.bol.com/*' => Http::response(['products' => []])]);

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
        Http::fake([...$this->fakeToken(), 'api.bol.com/*' => Http::response(['products' => [$this->product()]])]);

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

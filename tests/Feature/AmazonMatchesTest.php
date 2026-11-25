<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\IdentityKind;
use App\Enums\Market;
use App\Jobs\LookUpAmazonMatches;
use App\Models\ProductGroup;
use App\Services\Amazon\AmazonMatches;
use App\Services\Amazon\AsinDataClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Bekijk op Amazon": a barcode matched to its ASIN in the background, then a
 * direct, tagged link without a price (docs/features/amazon-links.md).
 */
class AmazonMatchesTest extends TestCase
{
    use RefreshDatabase;

    private const PERSON = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();

        config(['giftcoves.connectors.asindata.key' => 'test-key']);
        Http::preventStrayRequests();
    }

    #[Test]
    public function a_found_barcode_keeps_its_asin_and_a_missing_one_is_remembered_as_missing(): void
    {
        Http::fake([
            'api.asindataapi.com/*gtin=5702017583518*' => Http::response(['request_info' => ['success' => true], 'product' => ['asin' => 'B0CFVYBW3L', 'title' => 'LEGO', 'buybox_winner' => ['price' => ['value' => 49.99]]]]),
            'api.asindataapi.com/*gtin=8712345678906*' => Http::response(['request_info' => ['success' => true, 'message' => "An ASIN for GTIN '8712345678906' could not be found on amazon.nl."]]),
        ]);

        (new LookUpAmazonMatches('amazon.nl', ['5702017583518', '8712345678906']))->handle(app(AsinDataClient::class));

        $this->assertSame('B0CFVYBW3L', DB::table('amazon_matches')->where('gtin', '5702017583518')->value('asin'));
        $this->assertTrue(DB::table('amazon_matches')->where('gtin', '8712345678906')->exists());
        $this->assertNull(DB::table('amazon_matches')->where('gtin', '8712345678906')->value('asin'));

        // Only the ASIN: no title, price or stock is kept anywhere (invariant 6).
        $this->assertSame(['id', 'storefront', 'gtin', 'asin', 'checked_at'], array_keys((array) DB::table('amazon_matches')->first()));
    }

    #[Test]
    public function a_hiccup_writes_nothing_and_no_credits_stops_the_day(): void
    {
        Http::fakeSequence('api.asindataapi.com/*')
            ->push(['request_info' => ['success' => false, 'message' => 'please retry. You have not been charged']], 500)
            ->push(['request_info' => ['success' => false]], 402);

        (new LookUpAmazonMatches('amazon.com.be', ['1111111111116', '2222222222222', '3333333333333']))->handle(app(AsinDataClient::class));

        $this->assertSame(0, DB::table('amazon_matches')->count());
        $this->assertTrue(Cache::has('asindata:stopped:'.now()->toDateString()));
        // The third barcode was never asked: out of credits does not improve per barcode.
        Http::assertSentCount(2);
    }

    #[Test]
    public function the_daily_cap_is_kept(): void
    {
        config(['giftcoves.connectors.asindata.lookups_per_day' => 1]);
        Http::fake(['api.asindataapi.com/*' => Http::response(['request_info' => ['success' => true], 'product' => ['asin' => 'B000000001']])]);

        (new LookUpAmazonMatches('amazon.nl', ['1111111111116', '2222222222222']))->handle(app(AsinDataClient::class));

        $this->assertSame(1, DB::table('amazon_matches')->count());
        Http::assertSentCount(1);
    }

    #[Test]
    public function only_the_pages_own_follow_up_queues_the_lookup_never_the_html_or_a_crawler(): void
    {
        Queue::fake();
        $group = $this->group(Market::BeNl, '5702017583518');
        $url = "/be-nl/p/{$group->id}/{$group->slug}";

        // The page itself, as a scraper fetching HTML gets it: nothing queued, and the
        // Amazon row left for the follow-up (2026-11-23).
        $this->withHeader('User-Agent', self::PERSON)->get($url)->assertOk()
            ->assertInertia(fn ($page) => $page->missing('amazonLink')->etc());
        Queue::assertNothingPushed();

        // A crawler that did run the follow-up queues nothing either.
        $this->followUp($url, 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)');
        Queue::assertNothingPushed();

        // A browser's follow-up does.
        $this->followUp($url);
        Queue::assertPushed(LookUpAmazonMatches::class, fn (LookUpAmazonMatches $job) => $job->storefront === 'amazon.com.be' && $job->gtins === ['5702017583518']);
    }

    #[Test]
    public function a_matched_product_links_to_amazon_without_a_price_on_its_page_and_its_search_card(): void
    {
        Queue::fake();
        $group = $this->group(Market::BeNl, '5702017583518', 'Technic racewagen');
        DB::table('amazon_matches')->insert(['storefront' => 'amazon.com.be', 'gtin' => '5702017583518', 'asin' => 'B0CFVYBW3L', 'checked_at' => now()]);

        // Drawn like the other shops: the storefront as its name, Amazon's icon, a title.
        $row = $this->followUp("/be-nl/p/{$group->id}/{$group->slug}")->json('props.amazonLink');
        $this->assertSame([
            'url' => 'https://www.amazon.com.be/dp/B0CFVYBW3L?tag=giftcoves05-21',
            'host' => 'amazon.com.be',
            'icon' => 'https://www.amazon.com.be/favicon.ico',
            'title' => 'Technic racewagen',
        ], $row);

        $this->withHeader('User-Agent', self::PERSON)->get('/be-nl/search?q=racewagen')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('results.items.0.amazon', 'https://www.amazon.com.be/dp/B0CFVYBW3L?tag=giftcoves05-21'));

        // Nothing already matched was queued again.
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_search_page_queues_no_lookups(): void
    {
        // Owner, 2026-11-23: "don't do a batch". Only a product page asks, one barcode at a time.
        Queue::fake();
        $this->group(Market::BeNl, '5702017583518', 'Technic racewagen');
        $this->group(Market::BeNl, '4006381333931', 'Technic racewagen twee');

        $this->withHeader('User-Agent', self::PERSON)->get('/be-nl/search?q=racewagen')->assertOk();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function markets_without_our_storefront_get_no_link_and_ask_nothing(): void
    {
        Queue::fake();
        $group = $this->group(Market::Es, '5702017583518');

        // es: no storefront. (en has amazon.de since 2026-11-25.)
        $this->assertNull(app(AmazonMatches::class)->storefront(Market::Es));
        $this->withHeader('User-Agent', self::PERSON)->get("/es/p/{$group->id}/{$group->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('amazonLink', null));
        Queue::assertNothingPushed();
    }

    /** The request the product page's JavaScript makes for the deferred Amazon row. */
    private function followUp(string $url, string $userAgent = self::PERSON): TestResponse
    {
        $version = $this->withHeader('User-Agent', $userAgent)->get($url)->viewData('page')['version'];

        return $this->withHeader('User-Agent', $userAgent)->get($url, [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'Product',
            'X-Inertia-Partial-Data' => 'amazonLink',
        ])->assertOk();
    }

    private function group(Market $market, string $gtin, ?string $title = null): ProductGroup
    {
        return ProductGroup::factory()->create(array_filter([
            'market' => $market,
            'identity_kind' => IdentityKind::Ean,
            'identity_key' => $gtin,
            'title' => $title,
        ]));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Ook op Amazon": Amazon's own products for a search, under ours, without a price,
 * from a follow-up request of their own (App\Services\Amazon\AmazonSearch).
 */
class AmazonSearchTest extends TestCase
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
    public function the_page_asks_for_amazon_after_it_is_shown_and_never_for_a_crawler_or_another_market(): void
    {
        Http::fake();

        $this->withHeader('User-Agent', self::PERSON)->get('/be-nl/search?q=lego')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->missing('amazon')->etc());

        $this->withHeader('User-Agent', 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)')->get('/be-nl/search?q=lego')
            ->assertInertia(fn ($page) => $page->where('amazon', null)->etc());

        $this->withHeader('User-Agent', self::PERSON)->get('/en/search?q=lego')
            ->assertInertia(fn ($page) => $page->where('amazon', null)->etc());

        // Showing the page asked Amazon nothing: that is the follow-up's job.
        Http::assertNothingSent();
    }

    #[Test]
    public function the_follow_up_shows_amazons_products_without_a_price_and_without_the_ads(): void
    {
        Http::fake(['api.asindataapi.com/*' => Http::response([
            'request_info' => ['success' => true],
            'search_results' => [
                ['asin' => 'B0GJDDY8FD', 'title' => 'Robot kit', 'sponsored' => true, 'image' => 'https://m.media-amazon.com/a.jpg', 'price' => ['value' => 30]],
                ['asin' => 'B0CFVYBW3L', 'title' => 'LEGO Technic racewagen', 'sponsored' => false, 'image' => 'https://m.media-amazon.com/b.jpg', 'price' => ['value' => 49.99]],
            ],
        ])]);

        $items = $this->followUp('/be-nl/search?q=lego%20technic')->json('props.amazon');

        $this->assertSame([[
            'asin' => 'B0CFVYBW3L',
            'title' => 'LEGO Technic racewagen',
            'image' => 'https://m.media-amazon.com/b.jpg',
            'url' => 'https://www.amazon.com.be/dp/B0CFVYBW3L?tag=giftcoves05-21',
        ]], $items);

        // Saved (owner, 2026-11-23): the product in amazon_products, without a price
        // (there is no column for one), and the term's answer in amazon_searches.
        $product = DB::table('amazon_products')->where('asin', 'B0CFVYBW3L')->first();
        $this->assertSame('LEGO Technic racewagen', $product->classified_title);
        $this->assertSame('https://m.media-amazon.com/b.jpg', $product->image_url);
        $this->assertSame(['amazon.com.be'], json_decode($product->seen_in_locales, true));
        $this->assertFalse(DB::table('amazon_products')->where('asin', 'B0GJDDY8FD')->exists(), 'a sponsored result is not kept');
        // assertEquals: jsonb keeps an object's keys in its own order.
        $this->assertEquals(
            [['asin' => 'B0CFVYBW3L', 'title' => 'LEGO Technic racewagen', 'image' => 'https://m.media-amazon.com/b.jpg']],
            json_decode((string) DB::table('amazon_searches')->where('language', 'nl_BE')->where('term', 'lego technic')->value('items'), true),
        );

        // Asked in the market's language (be-nl: Dutch), best sellers first (owner), without ads.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'language=nl_BE')
            && str_contains($request->url(), 'sort_by=bestseller_rankings')
            && str_contains($request->url(), 'exclude_sponsored=true'));

        // The same term again is answered from the database, even after the cache is
        // emptied: one credit per term.
        Cache::flush();
        $this->assertSame('B0CFVYBW3L', $this->followUp('/be-nl/search?q=LEGO%20technic')->json('props.amazon.0.asin'));
        Http::assertSentCount(1);
    }

    #[Test]
    public function the_daily_cap_is_kept(): void
    {
        config(['giftcoves.connectors.asindata.searches_per_day' => 0]);
        Http::fake();

        $this->assertSame([], $this->followUp('/nl-nl/search?q=lego')->json('props.amazon'));
        Http::assertNothingSent();
    }

    private function followUp(string $url): TestResponse
    {
        $version = $this->withHeader('User-Agent', self::PERSON)->get($url)->viewData('page')['version'];

        return $this->withHeader('User-Agent', self::PERSON)->get($url, [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'Search',
            'X-Inertia-Partial-Data' => 'amazon',
        ])->assertOk();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\WarmSitemapSearchesCommand;
use App\Enums\Market;
use App\Http\Middleware\CacheAnonymousPage;
use App\Services\Search\SearchTermStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The sitemap's search pages, kept a day for search engines and filled ahead of them
 * (owner, 2026-11-24: "let's pre-cache pages in the sitemap"). CacheAnonymousPage,
 * App\Services\Search\SitemapSearches, bc:warm-sitemap-searches.
 */
class SitemapSearchCacheTest extends TestCase
{
    use RefreshDatabase;

    private const GOOGLE = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    private const PERSON = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();

        config(['giftcoves.page_cache.enabled' => true, 'giftcoves.robots_allow' => true]);
        WarmSitemapSearchesCommand::$pause = 0;

        // One submitted search page on be-nl: lego.
        $this->app->instance(SearchTermStats::class, new class extends SearchTermStats
        {
            public function __construct() {}

            public function for(Market $market): array
            {
                return ['months' => [], 'trending' => [], 'latest' => $market === Market::BeNl
                    ? [['term' => 'lego', 'url' => '/be-nl/zoek/lego']]
                    : []];
            }
        });
    }

    #[Test]
    public function a_search_engine_gets_a_submitted_search_page_from_the_cache_for_a_day(): void
    {
        $this->assertSame('miss', $this->as(self::GOOGLE, '/be-nl/zoek/lego'));
        $this->assertSame('hit', $this->as(self::GOOGLE, '/be-nl/zoek/lego'));

        // Still fresh after ten minutes, where a public page would be stale.
        $this->travel(2)->hours();
        $this->assertSame('hit', $this->as(self::GOOGLE, '/be-nl/zoek/lego'));
    }

    #[Test]
    public function people_and_other_search_pages_are_never_cached(): void
    {
        // A person: the page as always, so the search is logged.
        $this->assertSame('bypass', $this->as(self::PERSON, '/be-nl/zoek/lego'));
        // A search page the sitemap does not submit, even for Google.
        $this->assertSame('bypass', $this->as(self::GOOGLE, '/be-nl/zoek/koptelefoon'));
        // Another crawler.
        $this->assertSame('bypass', $this->as('Mozilla/5.0 (compatible; YandexBot/3.0)', '/be-nl/zoek/lego'));
    }

    #[Test]
    public function the_warm_up_asks_each_submitted_page_once_per_deploy(): void
    {
        Http::fake(['*' => Http::response('ok', 200, [CacheAnonymousPage::HEADER => 'miss'])]);

        $this->artisan('bc:warm-sitemap-searches')->assertSuccessful();
        $this->artisan('bc:warm-sitemap-searches')->assertSuccessful();

        // As Googlebot, to the public address, and only once: the second run found its marker.
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === url('/be-nl/zoek/lego')
            && str_contains($request->header('User-Agent')[0] ?? '', 'Googlebot'));

        // A new deploy is a new commit: asked again.
        config(['giftcoves.commit_sha' => 'next']);
        $this->artisan('bc:warm-sitemap-searches')->assertSuccessful();
        Http::assertSentCount(2);
    }

    #[Test]
    public function staging_warms_nothing(): void
    {
        config(['giftcoves.robots_allow' => false]);
        Http::fake();

        $this->artisan('bc:warm-sitemap-searches')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertFalse(Cache::has('bc:sitemap-search-warm'));
    }

    private function as(string $agent, string $url): string
    {
        return (string) $this->withHeader('User-Agent', $agent)->get($url)->assertOk()->headers->get(CacheAnonymousPage::HEADER);
    }
}

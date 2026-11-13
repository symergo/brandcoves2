<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\LimitHeavyCrawlers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HeavyCrawlersTest extends TestCase
{
    use RefreshDatabase;

    private const AMAZON = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Amazonbot/0.1; +https://developer.amazon.com/support/amazonbot) Chrome/119.0.6045.214 Safari/537.36';

    private const SEMRUSH = 'Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)';

    private const EXA = 'Mozilla/5.0 (compatible; ExaSearchBot/1.0; +https://crawler.exa.ai/)';

    private const GOOGLE = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (array_keys(LimitHeavyCrawlers::CRAWLERS) as $name) {
            RateLimiter::clear('bc:heavy-crawler:'.$name);
        }
    }

    #[Test]
    public function the_three_crawlers_get_no_search_pages(): void
    {
        foreach ([self::AMAZON, self::SEMRUSH, self::EXA] as $agent) {
            $this->withHeader('User-Agent', $agent)->get('/be-nl/zoek/lego')->assertForbidden();
            $this->withHeader('User-Agent', $agent)->get('/be-fr/recherche/lego')->assertForbidden();
            $this->withHeader('User-Agent', $agent)->get('/be-nl/search?q=lego')->assertForbidden();
        }
    }

    #[Test]
    public function people_and_search_engines_still_search(): void
    {
        $this->withHeader('User-Agent', self::GOOGLE)->get('/be-nl/zoek/lego')->assertOk();
        $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/145.0.0.0 Safari/537.36')
            ->get('/be-nl/zoek/lego')->assertOk();
    }

    #[Test]
    public function other_pages_are_capped_per_crawler_and_robots_is_always_served(): void
    {
        for ($i = 0; $i < LimitHeavyCrawlers::PER_MINUTE; $i++) {
            $this->withHeader('User-Agent', self::SEMRUSH)->get('/be-nl/about')->assertOk();
        }

        $this->withHeader('User-Agent', self::SEMRUSH)->get('/be-nl/about')
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        // Its own count, and the rules stay readable
        $this->withHeader('User-Agent', self::SEMRUSH)->get('/robots.txt')->assertOk();
        $this->withHeader('User-Agent', self::EXA)->get('/be-nl/about')->assertOk();
        $this->withHeader('User-Agent', self::GOOGLE)->get('/be-nl/about')->assertOk();
    }

    #[Test]
    public function robots_tells_them_the_same(): void
    {
        config(['giftcoves.robots_allow' => true]);

        $robots = $this->get('/robots.txt')->assertOk()->getContent();
        [$everybody, $three] = explode("\n\n", $robots);

        $this->assertStringContainsString("User-agent: Amazonbot\nUser-agent: SemrushBot\nUser-agent: ExaSearchBot\nCrawl-delay: 10\n", $three);
        $this->assertStringContainsString('Disallow: /*/zoek/', $three);
        $this->assertStringContainsString('Disallow: /*/recherche/', $three);
        $this->assertStringContainsString('Disallow: /*/search', $three);
        // The rules for everybody apply to them too
        $this->assertStringContainsString('Disallow: /*/go/', $three);
        $this->assertStringContainsString('Disallow: /admin', $three);

        // And everybody else may still crawl search pages
        $this->assertStringStartsWith("User-agent: *\nAllow: /\n", $everybody);
        $this->assertStringNotContainsString('zoek', $everybody);
        $this->assertStringContainsString('Disallow: /*/go/', $everybody);
    }

    #[Test]
    public function the_brand_key_works_without_a_search_path(): void
    {
        // Autovacuum analyses with an empty search path; the function failed there (2026-11-13)
        DB::statement("SET LOCAL search_path = ''");

        $this->assertSame('audiotechnica', DB::scalar("SELECT public.bc_brand_key('Audio-Technica')"));
        $this->assertSame('creme', DB::scalar("SELECT public.bc_brand_key('Crème')"));
    }
}

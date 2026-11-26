<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Http\Middleware\LimitHeavyCrawlers;
use App\Models\ProductGroup;
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
    public function ai_crawlers_get_no_search_pages_and_every_other_page_without_a_cap(): void
    {
        $gpt = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.4; +https://openai.com/gptbot)';
        $meta = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 (compatible; meta-externalagent/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler))';

        $metaIndex = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 (compatible; meta-webindexer/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler))';
        $preview = 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)';
        $claudeSearch = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Claude-SearchBot/1.0; +searchbot@anthropic.com)';

        // Link previews are not an AI crawler and keep every page
        $this->withHeader('User-Agent', $preview)->get('/be-nl/zoek/lego')->assertOk();

        foreach ([$gpt, $meta, $metaIndex, $claudeSearch] as $agent) {
            $this->withHeader('User-Agent', $agent)->get('/be-nl/zoek/lego')->assertForbidden();
            $this->withHeader('User-Agent', $agent)->get('/be-nl/search?q=lego')->assertForbidden();
        }

        // Unlike the heavy crawlers, no per-minute cap on the rest
        for ($i = 0; $i <= LimitHeavyCrawlers::PER_MINUTE + 2; $i++) {
            $this->withHeader('User-Agent', $gpt)->get('/be-nl/about')->assertOk();
        }

        config(['giftcoves.robots_allow' => true]);
        $robots = $this->get('/robots.txt')->getContent();
        $this->assertStringContainsString("User-agent: GPTBot\nUser-agent: ClaudeBot\nUser-agent: Claude-SearchBot\nUser-agent: meta-externalagent\nUser-agent: meta-webindexer\nUser-agent: Reflectionbot\nUser-agent: KakaoBot\nAllow: /\nDisallow: /*/search\n", $robots);
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
    public function amazons_search_crawler_is_one_of_them(): void
    {
        $amzn = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Amzn-SearchBot/0.1) Chrome/119.0.6045.214 Safari/537.36';

        $this->withHeader('User-Agent', $amzn)->get('/be-nl/zoek/lego')->assertStatus(403);
        $this->withHeader('User-Agent', $amzn)->get('/be-nl/about')->assertOk();
    }

    #[Test]
    public function the_heavy_crawlers_are_refused_product_pages_and_search_engines_are_not(): void
    {
        $group = ProductGroup::factory()->create(['market' => Market::BeNl]);
        $amzn = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Amzn-SearchBot/0.1) Chrome/119.0.6045.214 Safari/537.36';
        $page = "/be-nl/p/{$group->id}/{$group->slug}";

        // Amzn-User (Amazon's assistant fetching for a person) and any later "Amzn-…" agent too.
        $amznUser = 'Mozilla/5.0 (compatible; Amzn-User/0.1)';
        $amznLater = 'Mozilla/5.0 (compatible; Amzn-NewAgent/1.0)';
        // Moz's crawler (owner, 2026-11-24).
        $dot = 'Mozilla/5.0 (compatible; DotBot/1.2; +https://opensiteexplorer.org/dotbot; help@moz.com)';
        // Majestic's (owner, 2026-11-26).
        $mj12 = 'Mozilla/5.0 (compatible; MJ12bot/v2.0.7; http://mj12bot.com/)';

        foreach ([self::AMAZON, $amzn, $amznUser, $amznLater, $dot, $mj12, self::SEMRUSH, self::EXA] as $agent) {
            $this->withHeader('User-Agent', $agent)->get($page)->assertStatus(403);
            $this->withHeader('User-Agent', $agent)->get('/be-nl/zoek/lego')->assertStatus(403);
        }

        $this->withHeader('User-Agent', self::GOOGLE)->get($page)->assertOk();
        $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/145.0 Safari/537.36')->get($page)->assertOk();
    }

    #[Test]
    public function robots_tells_them_the_same(): void
    {
        config(['giftcoves.robots_allow' => true]);

        $robots = $this->get('/robots.txt')->assertOk()->getContent();
        [$everybody, $three] = explode("\n\n", $robots);

        $this->assertStringContainsString("User-agent: Amazonbot\nUser-agent: SemrushBot\nUser-agent: ExaSearchBot\nUser-agent: Amzn-SearchBot\nUser-agent: Amzn-User\nUser-agent: DotBot\nUser-agent: MJ12bot\nUser-agent: Amzn\nCrawl-delay: 10\n", $three);
        // Product pages refused too (owner, 2026-11-23)
        $this->assertStringContainsString('Disallow: /*/p/', $three);
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

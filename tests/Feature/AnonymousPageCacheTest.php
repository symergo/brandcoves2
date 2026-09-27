<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\CacheAnonymousPage;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\TrackAnonymousIdentity;
use App\Models\AnonymousIdentity;
use App\Models\User;
use App\Support\MarketPreference;
use App\Support\SsrGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The whole-page cache for signed-out visitors (docs/features/speed.md,
 * "Anonymous page cache"). See App\Http\Middleware\CacheAnonymousPage.
 *
 * Every request carries an ordinary browser User-Agent: a crawler's agent
 * changes the shared props (no market bar), and the point here is the
 * visitor, not the bot.
 */
class AnonymousPageCacheTest extends TestCase
{
    use RefreshDatabase;

    private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';

    private const PAGE = '/be-nl/about';

    protected function setUp(): void
    {
        parent::setUp();

        // The suite runs with the cache off (phpunit.xml); this is its test.
        config(['giftcoves.page_cache.enabled' => true]);
    }

    #[Test]
    public function a_guest_gets_the_second_view_from_the_cache_and_no_cookie_either_time(): void
    {
        $first = $this->guest()->get(self::PAGE)->assertOk();
        $second = $this->guest()->get(self::PAGE)->assertOk();

        $this->assertSame('miss', $first->headers->get(CacheAnonymousPage::HEADER));
        $this->assertSame('hit', $second->headers->get(CacheAnonymousPage::HEADER));

        foreach ([$first, $second] as $response) {
            $this->assertSame([], $response->headers->getCookies(), 'a cached page sets no cookie');
            $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('max-age=60', (string) $response->headers->get('Cache-Control'));
            // No visitor's token in a page every visitor gets.
            $this->assertStringContainsString('<meta name="csrf-token" content="">', (string) $response->getContent());
        }

        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertSame(0, AnonymousIdentity::query()->count(), 'reading a page makes no identity');
    }

    #[Test]
    public function a_signed_in_visitor_never_gets_a_cached_page(): void
    {
        $this->guest()->get(self::PAGE)->assertOk();

        $user = User::factory()->create();

        $response = $this->actingAs($user)->guest()->get(self::PAGE)->assertOk();

        $this->assertSame('bypass', $response->headers->get(CacheAnonymousPage::HEADER));
        $this->assertStringNotContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('<meta name="csrf-token" content="">', (string) $response->getContent());
    }

    #[Test]
    public function a_visitor_with_a_session_or_a_remember_cookie_bypasses(): void
    {
        $this->guest()->get(self::PAGE)->assertOk();

        $withSession = $this->guest()
            ->withCookie((string) config('session.cookie'), 'some-session')
            ->get(self::PAGE);

        $this->assertSame('bypass', $withSession->headers->get(CacheAnonymousPage::HEADER));

        $this->flushHeaders();
        $this->forgetCookies();

        $remembered = $this->guest()
            ->withUnencryptedCookie('remember_web_59ba36addc2b2f9401580f014c7f58ea4e30989d', 'x')
            ->get(self::PAGE);

        $this->assertSame('bypass', $remembered->headers->get(CacheAnonymousPage::HEADER));
    }

    #[Test]
    public function a_market_choice_is_its_own_entry(): void
    {
        // A Dutch-speaking browser on a Belgian page with nothing chosen: the
        // bar asks whether Belgium is right.
        $unchosen = $this->guest()->get(self::PAGE);
        $this->assertSame('miss', $unchosen->headers->get(CacheAnonymousPage::HEADER));

        // Somebody who chose the Netherlands is offered their own market.
        $chosen = $this->guest()->withCookie(MarketPreference::COOKIE, 'nl-nl')->get(self::PAGE);
        $this->assertSame('miss', $chosen->headers->get(CacheAnonymousPage::HEADER), 'another choice is another entry');
        $chosen->assertInertia(fn ($page) => $page->where('marketBar.suggest', 'nl-nl'));

        $again = $this->guest()->withCookie(MarketPreference::COOKIE, 'nl-nl')->get(self::PAGE);
        $this->assertSame('hit', $again->headers->get(CacheAnonymousPage::HEADER));

        $this->forgetCookies();
        $unchosen->assertInertia(fn ($page) => $page->where('marketBar.suggest', null));
    }

    #[Test]
    public function the_cookie_answer_is_its_own_entry(): void
    {
        $this->guest()->get(self::PAGE);

        $answered = $this->guest()->withCookie('bc_consent', 'denied')->get(self::PAGE);

        $this->assertSame('miss', $answered->headers->get(CacheAnonymousPage::HEADER));
        $answered->assertInertia(fn ($page) => $page->where('analytics.consent', 'denied'));
    }

    #[Test]
    public function an_inertia_visit_and_a_full_load_are_separate_entries(): void
    {
        $version = (string) app(HandleInertiaRequests::class)->version(Request::create('/'));

        $html = $this->guest()->get(self::PAGE);
        $this->assertSame('miss', $html->headers->get(CacheAnonymousPage::HEADER));

        $inertia = fn (): TestResponse => $this->guest()
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
            ->get(self::PAGE);

        $json = $inertia();
        $this->assertSame('miss', $json->headers->get(CacheAnonymousPage::HEADER), 'JSON is not served the HTML entry');
        $json->assertHeader('X-Inertia', 'true');

        $jsonAgain = $inertia();
        $this->assertSame('hit', $jsonAgain->headers->get(CacheAnonymousPage::HEADER));
        $this->assertSame('Legal', $jsonAgain->json('component'));

        $this->flushHeaders();
        $htmlAgain = $this->guest()->get(self::PAGE);
        $this->assertSame('hit', $htmlAgain->headers->get(CacheAnonymousPage::HEADER));
        $this->assertStringContainsString('<!DOCTYPE html>', (string) $htmlAgain->getContent());
    }

    #[Test]
    public function a_browser_on_an_old_build_is_not_served_the_cached_json(): void
    {
        $version = (string) app(HandleInertiaRequests::class)->version(Request::create('/'));

        $this->guest()
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
            ->get(self::PAGE);

        // Inertia answers a version mismatch with 409, which makes the browser
        // reload and fetch the new build. A cached JSON page would skip that.
        $this->guest()
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => 'an-old-build'])
            ->get(self::PAGE)
            ->assertStatus(409);
    }

    #[Test]
    public function a_page_carrying_session_state_is_never_stored(): void
    {
        $this->withSession(['success' => 'Saved to your list']);

        $first = $this->guest()->get(self::PAGE)->assertOk();
        $first->assertInertia(fn ($page) => $page->where('flash.success', 'Saved to your list'));

        $this->flushSession();

        $second = $this->guest()->get(self::PAGE)->assertOk();

        $this->assertSame('miss', $second->headers->get(CacheAnonymousPage::HEADER), 'the flashed page was not stored');
        $second->assertInertia(fn ($page) => $page->where('flash.success', null));
    }

    #[Test]
    public function an_unknown_query_parameter_bypasses_and_a_known_one_is_cached(): void
    {
        foreach (['?utm_source=newsletter', '?gclid=abc', '?page[]=1', '?page=0', '?sort=<script>'] as $query) {
            $response = $this->guest()->get(self::PAGE.$query);

            $this->assertSame('bypass', $response->headers->get(CacheAnonymousPage::HEADER), $query);
            $this->assertNotSame([], $response->headers->getCookies(), "{$query}: a bypass is an ordinary page with its session");
        }

        $this->assertSame('miss', $this->guest()->get(self::PAGE.'?page=2')->headers->get(CacheAnonymousPage::HEADER));
        $this->assertSame('hit', $this->guest()->get(self::PAGE.'?page=2')->headers->get(CacheAnonymousPage::HEADER));
        $this->assertSame('miss', $this->guest()->get(self::PAGE)->headers->get(CacheAnonymousPage::HEADER), 'a page is not its page 2');
    }

    #[Test]
    public function a_stale_page_is_served_while_another_request_rebuilds_it(): void
    {
        $this->guest()->get(self::PAGE);
        $key = $this->storedKey();

        $this->travel(6)->minutes();

        // Another request holds the rebuild.
        $lock = Cache::lock($key.':lock', 30);
        $this->assertTrue($lock->get());

        $stale = $this->guest()->get(self::PAGE)->assertOk();
        $this->assertSame('stale', $stale->headers->get(CacheAnonymousPage::HEADER));
        $this->assertSame([], $stale->headers->getCookies());

        $lock->release();

        // The rebuild: this request takes the lock, builds and stores.
        $rebuilt = $this->guest()->get(self::PAGE);
        $this->assertSame('miss', $rebuilt->headers->get(CacheAnonymousPage::HEADER));

        $this->assertSame('hit', $this->guest()->get(self::PAGE)->headers->get(CacheAnonymousPage::HEADER));
    }

    #[Test]
    public function a_stale_page_that_is_gone_is_not_served_again(): void
    {
        $this->guest()->get(self::PAGE);
        $key = $this->storedKey();

        $this->travel(6)->minutes();

        // What the page answers now: pretend the rebuild failed its render.
        $this->app['router']->matched(function ($event): void {
            $event->request->attributes->set(SsrGateway::FAILED, true);
        });

        $this->assertSame('miss', $this->guest()->get(self::PAGE)->headers->get(CacheAnonymousPage::HEADER));
        $this->assertNull(Cache::get($key), 'a rebuild that may not be stored drops the stale copy');
    }

    #[Test]
    public function a_page_whose_render_failed_is_not_stored(): void
    {
        $this->app['router']->matched(function ($event): void {
            $event->request->attributes->set(SsrGateway::FAILED, true);
        });

        $this->guest()->get(self::PAGE);

        $this->assertSame('miss', $this->guest()->get(self::PAGE)->headers->get(CacheAnonymousPage::HEADER));
    }

    #[Test]
    public function a_guest_can_still_write_after_a_cached_page(): void
    {
        $this->guest()->get(self::PAGE);
        $cached = $this->guest()->get(self::PAGE);
        $this->assertSame('hit', $cached->headers->get(CacheAnonymousPage::HEADER));

        // What ensureCsrfToken() asks before the first write.
        $csrf = $this->guest()->getJson('/csrf')->assertOk();
        $token = $csrf->json('token');
        $this->assertIsString($token);
        $this->assertNotSame('', $token);
        $this->assertStringContainsString('no-store', (string) $csrf->headers->get('Cache-Control'));

        $sessionName = (string) config('session.cookie');
        $names = array_map(fn ($cookie) => $cookie->getName(), $csrf->headers->getCookies());
        $this->assertContains($sessionName, $names, 'the token request starts the session');
        $this->assertContains('XSRF-TOKEN', $names, 'and gives Inertia its cookie');

        $sessionId = $csrf->getCookie($sessionName, decrypt: true)?->getValue();
        $this->assertIsString($sessionId);

        // The token belongs to that session.
        $store = $this->app['session']->driver();
        $store->setId($sessionId);
        $store->start();
        $this->assertSame($token, $store->token());

        // A guest's save: remembered until they sign in.
        $this->flushHeaders();
        $this->guest()
            ->withCookie($sessionName, $sessionId)
            ->withHeader('X-CSRF-TOKEN', $token)
            ->postJson('/be-nl/save-intent', ['group_id' => 1])
            ->assertOk()
            ->assertJson(['ok' => true]);

        // And from now on their pages are built for them, never the shared copy.
        $after = $this->guest()->withCookie($sessionName, $sessionId)->get(self::PAGE);
        $this->assertSame('bypass', $after->headers->get(CacheAnonymousPage::HEADER));
    }

    #[Test]
    public function the_identity_cookie_is_made_on_the_first_write_not_on_reading(): void
    {
        $this->guest()->get(self::PAGE);
        $this->guest()->get('/be-nl/privacy');
        $this->assertSame(0, AnonymousIdentity::query()->count());

        $csrf = $this->guest()->getJson('/csrf');

        $this->assertNotNull($csrf->getCookie(TrackAnonymousIdentity::COOKIE));
        $this->assertSame(1, AnonymousIdentity::query()->count());
    }

    #[Test]
    public function the_kill_switch_restores_the_ordinary_page(): void
    {
        config(['giftcoves.page_cache.enabled' => false]);

        $response = $this->guest()->get(self::PAGE)->assertOk();

        $this->assertSame('bypass', $response->headers->get(CacheAnonymousPage::HEADER));
        $this->assertNotSame([], $response->headers->getCookies());
    }

    #[Test]
    public function a_head_request_is_answered_from_the_cache(): void
    {
        $this->guest()->get(self::PAGE);

        // call() takes server variables, not the default headers.
        $head = $this->call('HEAD', self::PAGE, server: [
            'HTTP_USER_AGENT' => self::BROWSER,
            'HTTP_ACCEPT_LANGUAGE' => 'nl-BE,nl;q=0.9',
        ]);

        $this->assertSame('hit', $head->headers->get(CacheAnonymousPage::HEADER));
        $this->assertSame([], $head->headers->getCookies());
    }

    #[Test]
    public function the_help_page_leaves_the_referer_to_the_browser(): void
    {
        // Otherwise the first visitor's previous page would be prefilled in
        // everybody's feedback form. resources/js/previousPath.ts fills it in.
        $this->guest()
            ->withHeader('referer', url('/be-nl/p/99/thing'))
            ->get('/be-nl/help')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('path', null));

        $cached = $this->guest()->withHeader('referer', url('/be-nl/brands'))->get('/be-nl/help');
        $this->assertSame('hit', $cached->headers->get(CacheAnonymousPage::HEADER));
        $this->assertStringNotContainsString('/be-nl/p/99/thing', (string) $cached->getContent());
    }

    #[Test]
    public function the_opted_in_routes_are_the_public_pages(): void
    {
        // A spot check of the list itself; PageCacheRoutesTest guards the other side.
        foreach (['home', 'legal', 'product', 'brand', 'brands', 'shops', 'coves', 'guides.show', 'daily.edition', 'gift-ideas.persona', 'community.show', 'help'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertContains(CacheAnonymousPage::ALIAS, $route->middleware(), $name);
        }
    }

    private function guest(): static
    {
        return $this->withHeaders([
            'User-Agent' => self::BROWSER,
            'Accept-Language' => 'nl-BE,nl;q=0.9',
        ]);
    }

    private function forgetCookies(): void
    {
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
    }

    private function storedKey(): string
    {
        $store = Cache::getStore();
        $keys = (fn (): array => array_keys($this->storage))->call($store);
        $pages = array_values(array_filter($keys, fn (string $key) => str_starts_with($key, 'page:')));

        $this->assertCount(1, $pages);

        return $pages[0];
    }
}

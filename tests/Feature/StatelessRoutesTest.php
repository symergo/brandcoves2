<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\StatelessRoutes;
use App\Models\AnonymousIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The machine-read routes answer without a session and without a cookie
 * (2026-09-27). See {@see StatelessRoutes} for why.
 *
 * Every request here comes from an ordinary browser User-Agent on purpose: a
 * crawler's agent would already skip the anonymous identity, and the point is
 * that the ROUTE is stateless, whoever asks.
 */
class StatelessRoutesTest extends TestCase
{
    use RefreshDatabase;

    private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';

    #[Test]
    public function the_machine_read_routes_set_no_cookie(): void
    {
        foreach ([
            '/health',
            '/robots.txt',
            '/sitemap.xml',
            '/sitemap/be-nl/1.xml',
            '/be-nl/og/default.png',
        ] as $path) {
            $response = $this->browserGet($path);

            $this->assertLessThan(500, $response->getStatusCode(), "{$path} failed");
            $this->assertNoCookies($response, $path);
        }

        $this->assertSame(0, AnonymousIdentity::query()->count(), 'no identity row for a machine-read route');
    }

    #[Test]
    public function a_missing_card_or_picture_is_a_plain_404_with_no_cookie(): void
    {
        /*
         * Our own 404 page is an Inertia page whose shared props read the
         * session, which these routes no longer start. bootstrap/app.php hands
         * a sessionless request the framework's plain 404 instead: the right
         * answer for a missing PNG, and not a 500.
         */
        foreach ([
            '/be-nl/og/p/999999999.png',
            '/media/items/00000000-0000-0000-0000-000000000000.webp',
        ] as $path) {
            $response = $this->browserGet($path)->assertNotFound();

            $this->assertNoCookies($response, $path);
        }
    }

    #[Test]
    public function a_page_for_people_still_has_its_session(): void
    {
        // The control: without it, a test that finds no cookies proves nothing
        // if cookies simply never appear in a test response.
        $response = $this->browserGet('/be-nl')->assertOk();

        $this->assertNotSame([], $response->headers->getCookies());
    }

    private function browserGet(string $path): TestResponse
    {
        return $this->withHeader('User-Agent', self::BROWSER)->get($path);
    }

    private function assertNoCookies(TestResponse $response, string $path): void
    {
        $names = array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies());

        $this->assertSame([], $names, "{$path} set a cookie");
    }
}

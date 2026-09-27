<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\SsrGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Inertia\Ssr\Gateway;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Our SSR gateway: a time limit, and no server render for a signed-in visitor.
 * The reasons are on App\Support\SsrGateway and in docs/features/speed.md.
 */
class SsrGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml switches SSR off for the suite; these tests are about it.
        config(['inertia.ssr.enabled' => true, 'inertia.ssr.url' => 'http://ssr.test']);
    }

    #[Test]
    public function inertia_resolves_our_gateway(): void
    {
        $this->assertInstanceOf(SsrGateway::class, app(Gateway::class));
    }

    #[Test]
    public function a_visitor_who_is_not_signed_in_gets_the_server_render(): void
    {
        Http::fake(['ssr.test/render' => Http::response(['head' => [], 'body' => '<div id="app"><h1>rendered on the server</h1></div>'])]);

        $this->get('/be-nl/help')->assertOk()->assertSee('rendered on the server', escape: false);

        Http::assertSentCount(1);
    }

    #[Test]
    public function a_signed_in_visitor_is_not_rendered_on_the_server(): void
    {
        Http::fake(['ssr.test/render' => Http::response(['head' => [], 'body' => '<div id="app">rendered on the server</div>'])]);

        $this->actingAs(User::factory()->create())
            ->get('/be-nl/help')
            ->assertOk()
            ->assertDontSee('rendered on the server', escape: false);

        Http::assertNothingSent();
    }

    #[Test]
    public function a_renderer_that_does_not_answer_falls_back_to_the_browser(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        // The page still arrives, without server-rendered markup.
        $this->get('/be-nl/help')->assertOk()->assertSee('data-page', escape: false);
    }
}

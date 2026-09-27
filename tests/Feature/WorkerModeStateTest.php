<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Listeners\Octane\ReapplySettingsOverlays;
use App\Models\AnonymousIdentity;
use App\Services\Settings\AiSettingsStore;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\Testing\Fakes\FakeClient;
use Laravel\Octane\Testing\Fakes\FakeWorker;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Worker mode (Octane) keeps one booted app in memory and hands it request after
 * request. What one request leaves behind must not reach the next: the market,
 * the language, a page's SEO block, the admin's settings.
 *
 * The first test runs real requests through Octane's own worker loop
 * (`FakeWorker`, the class Octane tests itself with), in this process, one
 * after another through the same booted app: the closest thing to a worker
 * that a test can hold. It uses a crawler's User-Agent and machine-readable
 * pages so the worker's app, which has its own database connection outside
 * this test's transaction, writes nothing that would outlive the test.
 *
 * See docs/features/speed.md, "Worker mode".
 */
class WorkerModeStateTest extends TestCase
{
    use RefreshDatabase;

    private const CRAWLER = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    #[Test]
    public function one_worker_serves_markets_in_turn_without_carrying_one_into_the_next(): void
    {
        $responses = $this->throughOneWorker([
            '/nl-nl',
            '/be-fr',
            '/be-nl/help',
            '/en',
            '/nl-nl',
        ]);

        $expected = ['nl-NL', 'fr-BE', 'nl-BE', 'en', 'nl-NL'];

        foreach ($responses as $i => $response) {
            $this->assertSame(200, $response->getStatusCode(), "request {$i}");
            $this->assertSame($expected[$i], $response->headers->get('Content-Language'), "request {$i}");
            $this->assertStringContainsString('<html lang="'.$expected[$i].'">', (string) $response->getContent(), "request {$i}");
        }

        // The page's own canonical, not the previous page's (PageMeta is scoped
        // and reset; a leaked one would advertise the wrong page).
        $this->assertStringContainsString('/be-fr"', $this->canonical($responses[1]));
        $this->assertStringContainsString('/be-nl/help"', $this->canonical($responses[2]));

        $this->assertSame(0, AnonymousIdentity::query()->count(), 'the worker wrote nothing that outlives the test');
    }

    #[Test]
    public function inertia_props_are_the_requests_own(): void
    {
        $responses = $this->throughOneWorker(['/be-fr', '/nl-nl']);

        $first = $this->page($responses[0]);
        $second = $this->page($responses[1]);

        $this->assertSame('be-fr', $first['props']['market']['key'] ?? null);
        $this->assertSame('nl-nl', $second['props']['market']['key'] ?? null);
        $this->assertNull($second['props']['auth']['user'] ?? null);
    }

    /**
     * In worker mode the provider's boot, which lays the admin's settings over
     * the config, runs once per worker. The listener lays them again for each
     * request, so a setting saved in the admin applies to the next request as
     * it does in classic mode.
     */
    #[Test]
    public function a_setting_saved_in_the_admin_reaches_the_next_request(): void
    {
        $this->assertNotSame('claude-worker-test', config('giftcoves.ai.model'));

        app(AiSettingsStore::class)->put(['model' => 'claude-worker-test']);

        // What a worker's next request starts from: the config as it was at boot.
        (new ReapplySettingsOverlays)->handle((object) ['sandbox' => $this->app]);

        $this->assertSame('claude-worker-test', config('giftcoves.ai.model'));
    }

    #[Test]
    public function the_listener_is_registered_for_requests_tasks_and_ticks(): void
    {
        foreach (['RequestReceived', 'TaskReceived', 'TickReceived'] as $event) {
            $listeners = config('octane.listeners.Laravel\\Octane\\Events\\'.$event);

            $this->assertContains(ReapplySettingsOverlays::class, $listeners, $event);
            $this->assertSame(ReapplySettingsOverlays::class, end($listeners), "{$event}: after Octane's own preparation");
        }
    }

    /**
     * @param  list<string>  $paths
     * @return list<Response>
     */
    private function throughOneWorker(array $paths): array
    {
        $server = ['HTTP_USER_AGENT' => self::CRAWLER];

        $client = new FakeClient(array_map(
            fn (string $path): Request => Request::create($path, 'GET', server: $server),
            $paths,
        ));

        try {
            $worker = new FakeWorker(new ApplicationFactory(base_path()), $client);
            $worker->boot();
            $worker->run();
        } finally {
            // The worker points the container and the facades at its own app.
            // Hand them back, or the rest of this test talks to the worker's app.
            Container::setInstance($this->app);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($this->app);
            // Eloquent keeps its connection resolver and event dispatcher in
            // statics, which the worker's boot pointed at its own app.
            Model::setConnectionResolver($this->app['db']);
            Model::setEventDispatcher($this->app['events']);
        }

        $this->assertSame([], $client->errors);
        $this->assertCount(count($paths), $client->responses);

        return $client->responses;
    }

    /**
     * The Inertia page object the HTML carries (the first visit's props).
     *
     * @return array<string, mixed>
     */
    private function page(Response $response): array
    {
        // Inertia 3 writes it as a JSON script tag: <script data-page="app" type="application/json">.
        preg_match('#<script[^>]*data-page[^>]*>(.*?)</script>#s', (string) $response->getContent(), $m);

        return (array) json_decode($m[1] ?? '[]', true);
    }

    private function canonical(Response $response): string
    {
        preg_match('/<link rel="canonical" href="[^"]*"/', (string) $response->getContent(), $m);

        return $m[0] ?? '';
    }
}

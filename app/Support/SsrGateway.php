<?php

declare(strict_types=1);

namespace App\Support;

use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Vite;
use Inertia\Ssr\HttpGateway;
use Inertia\Ssr\Response;
use Inertia\Ssr\SsrException;

/**
 * Inertia's SSR gateway, with a time limit and without the signed-in visitor.
 *
 * **A time limit.** Inertia posts the page to the Node renderer with a plain
 * `Http::post()`, which carries Guzzle's default of no limit and Laravel's of
 * 30 seconds. A renderer that hangs (out of memory, stuck on one page) then
 * holds every PHP request for 30 s before the page falls back to rendering in
 * the browser, which it would have done anyway. With a limit of two seconds a
 * hung renderer costs two seconds and the fallback is the same. A healthy
 * render takes tens of milliseconds, so two seconds never cuts a real one.
 *
 * **Not for signed-in visitors.** The render exists for crawlers and for a
 * first paint on a public page. Nobody who signs in is a crawler, and what
 * they mostly open (their lists, their people, notifications) is private and
 * never indexed. Leaving them out takes that load off the renderer, removes a
 * whole class of hydration mismatches on personal pages, and costs them a
 * shorter wait for the HTML against a blank moment before the JavaScript
 * draws the page; the page's own chunk is preloaded (app.blade.php) to keep
 * that moment short. SEO is untouched: search engines never carry a session.
 *
 * `dispatch()` is Inertia's own (inertia-laravel 3.3.1, Ssr/HttpGateway.php)
 * with the timeout added, because the request is built inside that method and
 * there is no hook to reach it. Compare it with the vendor file when Inertia
 * is upgraded.
 */
class SsrGateway extends HttpGateway
{
    /**
     * @param  array<string, mixed>  $page
     */
    public function dispatch(array $page, ?Request $request = null): ?Response
    {
        if (! $this->ssrIsEnabled($request ?? request())) {
            return null;
        }

        $isHot = Vite::isRunningHot();

        if (! $isHot && $this->shouldEnsureBundleExists() && ! $this->bundleExists()) {
            return null;
        }

        $url = $isHot
            ? $this->getHotUrl('/__inertia_ssr')
            : $this->getProductionUrl('/render');

        try {
            $response = $this->http()->post($url, $page);

            if ($response->failed()) {
                $this->handleSsrFailure($page, $response->json());

                return null;
            }

            if (! $data = $response->json()) {
                return null;
            }

            return new Response(
                implode("\n", $data['head'] ?? []),
                $data['body'] ?? ''
            );
        } catch (Exception $e) {
            if ($e instanceof StrayRequestException || $e instanceof SsrException) {
                throw $e;
            }

            $this->handleSsrFailure($page, [
                'error' => $e->getMessage(),
                'type' => 'connection',
            ]);

            return null;
        }
    }

    public function isHealthy(): bool
    {
        try {
            return $this->http()->get($this->getProductionUrl('/health'))->successful();
        } catch (Exception $e) {
            if ($e instanceof StrayRequestException) {
                throw $e;
            }

            return false;
        }
    }

    protected function ssrIsEnabled(Request $request): bool
    {
        return $request->user() === null && parent::ssrIsEnabled($request);
    }

    private function http(): PendingRequest
    {
        $timeout = (float) config('inertia.ssr.timeout', 2);

        // The connect limit is shorter still: the renderer is a container on
        // the same Docker network, so a connection that takes more than a
        // second is not going to be answered.
        return Http::timeout($timeout)->connectTimeout(min(1.0, $timeout));
    }
}

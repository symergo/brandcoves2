<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetMarket;
use App\Http\Middleware\TrackAnonymousIdentity;
use App\Services\Seo\LegacyRedirects;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Coolify's Traefik terminates TLS and forwards plain HTTP, so without
        // this Laravel believes every request is insecure and generates http://
        // URLs for redirects, assets, canonical tags and the sitemap.
        //
        // Trusting all proxies is correct here: the container publishes no
        // ports and is reachable only through Traefik, so a forged
        // X-Forwarded-* header cannot originate outside the Docker network.
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            // Order matters: the market must be resolved before Inertia shares
            // it with the frontend, and the visitor identity must exist before
            // anything tries to attach a wishlist to it.
            SetMarket::class,
            TrackAnonymousIdentity::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
        ]);

        /*
         * Where a guest is sent when they hit an auth-only route.
         *
         * Laravel's default calls route('login'), which cannot be generated
         * here: every route is prefixed with {market}, and the exception
         * handler has no market to give it. The result is a 500 instead of a
         * login page. Resolving the market from the request restores the
         * intended behaviour, and returns the visitor to the market they were
         * already browsing.
         */
        $middleware->redirectGuestsTo(function (Request $request) {
            $segment = $request->segment(1);
            $market = Market::tryFrom((string) $segment)
                ?? Market::fromAcceptLanguage($request->header('Accept-Language'));

            return '/'.$market->value.'/login';
        });

        // navigator.sendBeacon cannot set headers, so the click beacon cannot
        // carry a CSRF token. Exempt deliberately: it writes an analytics row
        // and nothing else, is rate-limited, and the worst a forged request can
        // do is skew a click count. Blocking it instead would mean losing the
        // click data on every Amazon link.
        $middleware->validateCsrfTokens(except: [
            '*/track/click',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        /*
         * Catch v1's URLs on their way to a 404.
         *
         * As a 404 handler rather than a route table: v1 is a WordPress site
         * with thousands of indexed paths, and registering them all as routes
         * would put a legacy lookup in front of every real request forever.
         * Here the cost is paid only by requests that were going to fail
         * anyway.
         *
         * The mapper returns null for anything it does not recognise, so a
         * genuine v2 typo still 404s visibly instead of being swallowed into a
         * silent redirect. See docs/features/cutover.md.
         */
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->expectsJson()) {
                return null;
            }

            $destination = app(LegacyRedirects::class)->urlFor(
                $request->path(),
                Market::fromAcceptLanguage($request->header('Accept-Language')),
            );

            // 301, not 302: the move is permanent, and a 302 tells a crawler to
            // keep the old URL indexed.
            return $destination === null ? null : redirect()->away($destination, 301);
        });
    })->create();

<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetMarket;
use App\Http\Middleware\TrackAnonymousIdentity;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();

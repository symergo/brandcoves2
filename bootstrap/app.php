<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectLegacyHost;
use App\Http\Middleware\RequireApiAbility;
use App\Http\Middleware\SetMarket;
use App\Http\Middleware\TrackAnonymousIdentity;
use App\Services\Seo\LegacyRedirects;
use App\Support\MarketPreference;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // The editorial API. A separate file and a separate middleware stack:
        // no session, no CSRF, no market prefix and no Inertia — the caller is a
        // program with a key, not a browser with a login.
        api: __DIR__.'/../routes/api.php',
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

        /*
         * Global, so the old domain is answered on every route — the API and
         * the sitemap included — and before routing, because the old host is
         * valid on every route and would otherwise answer 200 under the wrong
         * domain rather than ever reaching a 404 handler.
         *
         * `append`, not `prepend`, and the difference is not cosmetic.
         * `prepend` puts this ahead of Laravel's own TrustProxies in the global
         * stack, so `getScheme()` has not yet seen `X-Forwarded-Proto` and
         * every redirect is issued as `http://`. Behind Coolify that lands the
         * visitor on a host that only speaks TLS. Appending puts it after
         * TrustProxies and still well before routing.
         */
        $middleware->append(RedirectLegacyHost::class);

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
            'api.token' => AuthenticateApiToken::class,
            'api.ability' => RequireApiAbility::class,
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
        $market = fn (Request $request): Market => Market::tryFrom((string) $request->segment(1))
            ?? MarketPreference::resolve($request);

        $middleware->redirectGuestsTo(fn (Request $request) => '/'.$market($request)->value.'/login');

        /*
         * And the mirror: where somebody already signed in is sent when they
         * open a guest-only route.
         *
         * The same defect, the other way round, and it survived the first fix.
         * Laravel's default calls `route('home')`, which is `/{market}` here and
         * cannot be generated without one — so opening `/be-nl/login` while
         * signed in threw `UrlGenerationException` and returned a 500 rather
         * than the home page. Easy to reach: a bookmarked login page, a stale
         * "Sign in" link, or a magic-link email opened after signing in on
         * another tab.
         */
        $middleware->redirectUsersTo(fn (Request $request) => '/'.$market($request)->value);

        // navigator.sendBeacon cannot set headers, so the click beacon cannot
        // carry a CSRF token. Exempt deliberately: it writes an analytics row
        // and nothing else, is rate-limited, and the worst a forged request can
        // do is skew a click count. Blocking it instead would mean losing the
        // click data on every Amazon link.
        $middleware->validateCsrfTokens(except: [
            '*/track/click',

            // eBay's account-deletion webhook. A server-to-server POST from
            // outside, so there is no session and no token to carry — and
            // rejecting it would mark the application non compliant in eBay's
            // portal, which stops the production keyset minting tokens.
            // Exempt safely: the handler changes no state and writes no
            // personal data. See docs/features/ebay-account-deletion.md.
            'webhooks/ebay/account-deletion',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Who gets a JSON error rather than an HTML one.
         *
         * `api/*` alone was narrower than Laravel's own default, and the
         * difference stopped mattering the moment the site grew a caller that
         * is neither the API nor a page: the save control, which posts to
         * `/list-items` and `/save-intent` with `Accept: application/json` and
         * reads the answer.
         *
         * Under the old predicate a validation failure there was answered with
         * a **302 to an HTML page**, which `fetch` follows silently and reports
         * as a success — so the one thing the control could not do was find out
         * that it had failed. A guest hitting an `auth` route got the login
         * page as a 200 for the same reason.
         *
         * `expectsJson()` restores the framework default and is exactly the
         * question worth asking: did this caller ask for JSON? Inertia visits
         * are unaffected — they send `Accept: text/html`, so `wantsJson()` is
         * false and `acceptsAnyContentType()` is false — and keep their
         * redirect-and-flash behaviour.
         */
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
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
                MarketPreference::resolve($request),
            );

            // 301, not 302: the move is permanent, and a 302 tells a crawler to
            // keep the old URL indexed.
            return $destination === null ? null : redirect()->away($destination, 301);
        });
    })->create();

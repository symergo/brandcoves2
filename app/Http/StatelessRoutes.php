<?php

declare(strict_types=1);

namespace App\Http;

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\TrackAnonymousIdentity;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The `web` middleware a machine-read route does not run.
 *
 * `/health`, robots.txt, the sitemaps, the pictures under /media/items and the
 * social cards under /{market}/og are fetched by healthchecks, crawlers and
 * link-preview bots, never by a person filling in a form. Through the full web
 * group each of them started a session (a Redis write per fetch, for a visitor
 * who keeps no cookie and so starts a new one every time) and answered with two
 * Set-Cookie headers: the session and XSRF-TOKEN. A response carrying Set-Cookie
 * is one no shared cache will keep, so the one kind of URL that is identical
 * for everybody was also the one kind that could not be cached (2026-09-27).
 *
 * What stays: EncryptCookies (it only reads), SetMarket (the social cards draw
 * in their market's language; on the unprefixed routes it binds the default
 * market, which is harmless), SubstituteBindings, and any throttle the route
 * declares, which counts in the cache and needs no session.
 *
 * Without a session there is also no site 404 page on these routes: that page
 * is an Inertia page whose shared props read the session. bootstrap/app.php
 * sends a request with no session the framework's plain 404 instead, which is
 * the right answer for a missing PNG or sitemap chunk anyway.
 *
 * PreventRequestForgery is Laravel 13's name for the CSRF check (what used to be
 * VerifyCsrfToken / ValidateCsrfToken, both now subclasses of it; excluding the
 * parent excludes them too). On a GET it checks nothing and only adds the
 * XSRF-TOKEN cookie, which is exactly the part these routes must not do.
 *
 * Used as `->withoutMiddleware(StatelessRoutes::SKIPPED)` on each group in
 * routes/web.php. StatelessRoutesTest proves the result: no Set-Cookie.
 */
final class StatelessRoutes
{
    /** @var list<class-string> */
    public const SKIPPED = [
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
        AddQueuedCookiesToResponse::class,
        TrackAnonymousIdentity::class,
        HandleInertiaRequests::class,
    ];
}

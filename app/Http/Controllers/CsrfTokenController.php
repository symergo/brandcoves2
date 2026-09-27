<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A CSRF token for a page that was served without one.
 *
 * A page from the anonymous page cache is the same for every signed-out
 * visitor, so it cannot carry anybody's token: app.blade.php leaves the
 * csrf-token meta tag empty, and no session is started for the visit
 * (App\Http\Middleware\CacheAnonymousPage). The browser asks here right before
 * its first write (`ensureCsrfToken()` in resources/js/http.ts, and every
 * Inertia POST through the request hook in app.tsx). This request runs the
 * whole `web` group, so it starts the session and sets the session and
 * XSRF-TOKEN cookies; the token it returns belongs to that session.
 *
 * Starting a session here is the point, and is also why the cache stops for
 * this visitor: from now on they send a session cookie, and a page for a
 * visitor with a session may hold a flash message or their adding mode.
 *
 * `no-store`: a token is one visitor's, and a copy kept by any cache is a
 * token handed to somebody else.
 */
final class CsrfTokenController
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()
            ->json(['token' => $request->session()->token()])
            ->header('Cache-Control', 'no-store, private');
    }
}

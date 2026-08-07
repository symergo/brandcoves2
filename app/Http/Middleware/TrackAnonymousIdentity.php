<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AnonymousIdentity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every visitor a durable identity without requiring an account.
 *
 * The gift wizard and the wishlist tray have to be useful before signup —
 * demanding a login before showing results is how you lose the visit. This
 * cookie is what a shortlist, a list and a pick reaction hang off until the
 * visitor creates an account, at which point everything is merged across.
 *
 * The cookie is encrypted and signed by Laravel's EncryptCookies middleware, so
 * a visitor cannot claim someone else's id by editing it.
 */
class TrackAnonymousIdentity
{
    public const COOKIE = 'bc_visitor';

    /** Long-lived on purpose: gift lists are built over weeks, not minutes. */
    private const LIFETIME_MINUTES = 60 * 24 * 365;

    public function handle(Request $request, Closure $next): Response
    {
        // A signed-in user has a real identity; a second anonymous one would
        // only fragment their data.
        if ($request->user() !== null) {
            return $next($request);
        }

        $id = $request->cookie(self::COOKIE);
        $identity = is_string($id) ? AnonymousIdentity::find($id) : null;

        if ($identity === null) {
            $identity = AnonymousIdentity::create(['last_seen_at' => now()]);
        } elseif ($identity->last_seen_at?->lt(now()->subDay())) {
            // Only once a day: this runs on every request, and a write per
            // page view would be a needless load on the primary.
            $identity->update(['last_seen_at' => now()]);
        }

        $request->attributes->set('anonymous_identity', $identity);

        $response = $next($request);

        Cookie::queue(Cookie::make(
            name: self::COOKIE,
            value: $identity->getKey(),
            minutes: self::LIFETIME_MINUTES,
            httpOnly: true,
            sameSite: 'lax',
        ));

        return $response;
    }
}

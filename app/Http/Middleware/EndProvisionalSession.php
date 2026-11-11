<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\EmailSignIn;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends an unconfirmed sign-in once somebody else has proven the address.
 *
 * Since 2026-11-12 the sign-in form signs a new address in at once, without
 * proof (EmailSignIn::signIn with `proven: false`). Anybody can type anybody's
 * address, so that session must not outlive the moment the real owner presses
 * the link: otherwise a stranger who made the account first would still be
 * inside it afterwards, reading whatever its owner adds.
 *
 * A provisional session is marked in the session itself. Once the account is
 * confirmed, the mark decides:
 *
 * - confirmed from the same IP address as this session was started from (the
 *   same person on their laptop and their phone at home): the session becomes
 *   an ordinary one;
 * - from anywhere else, or when where it was confirmed is no longer known:
 *   signed out. The real owner on another network signs in again with one
 *   link; a stranger is out.
 *
 * Before `HandleInertiaRequests`, so the page is rendered for a guest in the
 * same request that ends the session.
 */
class EndProvisionalSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $mark = $request->hasSession() ? $request->session()->get(EmailSignIn::PROVISIONAL) : null;

        if (! is_array($mark)) {
            return $next($request);
        }

        $user = $request->user();

        // Signed out, or signed in as somebody else since: the mark is stale.
        if (! $user instanceof User || (int) ($mark['user'] ?? 0) !== (int) $user->id) {
            $request->session()->forget(EmailSignIn::PROVISIONAL);

            return $next($request);
        }

        if (! $user->emailConfirmed()) {
            return $next($request);
        }

        $provenFrom = Cache::get(EmailSignIn::provenFromKey((int) $user->id));

        if (is_string($provenFrom) && $provenFrom !== '' && hash_equals($provenFrom, (string) ($mark['ip'] ?? ''))) {
            $request->session()->forget(EmailSignIn::PROVISIONAL);

            return $next($request);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $next($request);
    }
}

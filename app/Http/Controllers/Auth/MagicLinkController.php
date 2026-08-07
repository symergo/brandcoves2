<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\TrackAnonymousIdentity;
use App\Mail\MagicLinkMail;
use App\Models\AnonymousIdentity;
use App\Models\LoginToken;
use App\Models\User;
use App\Services\Auth\IdentityMerger;
use App\Support\CurrentMarket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Passwordless sign-in.
 *
 * No passwords at all: this site holds gift lists and email addresses, not
 * payment details, and a password is a liability that people reuse. An email
 * round-trip is the whole security model, which is honest about what is being
 * protected.
 */
class MagicLinkController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function send(Request $request, CurrentMarket $current): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc,dns', 'max:254'],
        ]);

        $email = mb_strtolower(trim($validated['email']));

        // Two limits, because they stop different things: per-address stops
        // mailbox flooding of one victim, per-IP stops an attacker walking a
        // list of addresses to find which are registered.
        foreach ([
            ['magic:'.$email, 5],
            ['magic-ip:'.$request->ip(), 20],
        ] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages([
                    'email' => __('site.auth.too_many', [
                        'seconds' => RateLimiter::availableIn($key),
                    ]),
                ]);
            }
            RateLimiter::hit($key, 900);
        }

        ['token' => $token] = LoginToken::issue($email, $request->ip());

        Mail::to($email)->send(new MagicLinkMail(
            token: $token,
            market: $current->get(),
            requestedFrom: $request->ip(),
        ));

        // Deliberately identical whether or not the address has an account.
        // Anything else turns this form into an account-existence oracle.
        return back()->with('success', __('site.auth.link_sent'));
    }

    public function consume(Request $request, string $token, IdentityMerger $merger): RedirectResponse
    {
        $loginToken = LoginToken::consume($token);

        if ($loginToken === null) {
            return redirect()->route('login')->withErrors([
                'email' => __('site.auth.link_invalid'),
            ]);
        }

        // Case-insensitive, matching the unique index on lower(email), so
        // Alice@ and alice@ are one person rather than two accounts with half
        // a gift list each.
        $user = User::query()
            ->whereRaw('lower(email) = ?', [$loginToken->email])
            ->first();

        $user ??= User::create([
            'email' => $loginToken->email,
            'name' => null,
        ]);

        // Proof of mailbox control is exactly what a magic link establishes.
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        // Everything built before signing up moves across. Do this BEFORE
        // logging in, while the anonymous cookie identity is still resolvable.
        $anonId = $request->cookie(TrackAnonymousIdentity::COOKIE);
        if (is_string($anonId)) {
            $anon = AnonymousIdentity::find($anonId);
            if ($anon !== null) {
                $merger->merge($anon, $user);
            }
        }

        Auth::login($user, remember: true);
        // A fresh session id after a privilege change; otherwise a session
        // fixed before login stays valid after it.
        $request->session()->regenerate();

        return redirect()->intended(app(CurrentMarket::class)->url('lists'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect(app(CurrentMarket::class)->url());
    }
}

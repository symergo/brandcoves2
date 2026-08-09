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
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

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
        return Inertia::render('Auth/Login', [
            // Staging may legitimately run without OAuth credentials, and a
            // button that leads to an exception is worse than no button.
            'googleEnabled' => filled(config('services.google.client_id'))
                && filled(config('services.google.client_secret')),
        ]);
    }

    public function send(Request $request, CurrentMarket $current): RedirectResponse
    {
        $validated = $request->validate([
            // rfc only, deliberately not dns. A DNS check rejects perfectly
            // valid addresses whenever resolution is slow or a corporate domain
            // hides its MX, and it makes sign-in fail for reasons the visitor
            // cannot understand or fix. A wrong address simply never arrives.
            'email' => ['required', 'email:rfc', 'max:254'],

            /*
             * Optional, and only ever used when the account is created.
             *
             * Asked here because a magic link is the whole of registration —
             * there is no other moment. Without it every account starts
             * nameless, and a wishlist shared with friends cannot say whose it
             * is.
             */
            'name' => ['nullable', 'string', 'max:80'],
        ]);

        $email = mb_strtolower(trim($validated['email']));
        $name = filled($validated['name'] ?? null) ? trim((string) $validated['name']) : null;

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

        ['token' => $token] = LoginToken::issue($email, $request->ip(), $name);

        /*
         * A mail transport that is down must not be a stack trace.
         *
         * This is sent synchronously on purpose — a magic link expires in
         * fifteen minutes, so queueing it would turn a broken transport into a
         * page that says "check your email" while nothing ever arrives, which is
         * the worse failure by far. The cost of sending inline is that any SMTP
         * problem lands in the request, and it landed as a 500 on a form whose
         * whole job is to be the way in.
         *
         * Reported as a validation error rather than swallowed: the person needs
         * to know the link is not coming, and somebody needs to see it in the
         * logs. It says nothing about whether the address has an account, so the
         * form stays non-oracular.
         */
        try {
            Mail::to($email)->send(new MagicLinkMail(
                token: $token,
                market: $current->get(),
                requestedFrom: $request->ip(),
            ));
        } catch (TransportExceptionInterface $e) {
            report($e);

            throw ValidationException::withMessages([
                'email' => __('site.auth.mail_failed'),
            ]);
        }

        // Deliberately identical whether or not the address has an account.
        // Anything else turns this form into an account-existence oracle.
        return back()->with('success', __('site.auth.link_sent'));
    }

    /** `{market}` is consumed by middleware but still passed positionally. */
    public function consume(Request $request, string $market, string $token, IdentityMerger $merger): RedirectResponse
    {
        $loginToken = LoginToken::consume($token);

        if ($loginToken === null) {
            return redirect()->to("/{$market}/login")->withErrors([
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
            'name' => $loginToken->name,
        ]);

        // An account that never got a name takes the one just typed. Never
        // overwrites: a name already set is theirs, not the login form's.
        if (blank($user->name) && filled($loginToken->name)) {
            $user->forceFill(['name' => $loginToken->name])->save();
        }

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

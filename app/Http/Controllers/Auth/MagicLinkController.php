<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\MagicLinkMail;
use App\Models\LoginToken;
use App\Services\Auth\EmailSignIn;
use App\Services\Seo\PageMeta;
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
    public function show(Request $request): Response
    {
        /*
         * `noindex, follow`, which it was not until 2026-09-05.
         *
         * A sign-in form served `index, follow, max-image-preview:large` with no
         * title and no description — an indexable page with nothing on it worth
         * ranking, spending crawl budget that belongs to products and guides.
         * `follow`, never `nofollow`, for the same reason every other thin page
         * here keeps it: the site chrome is still the way out.
         *
         * No title or description set on purpose. A page that must not be
         * indexed does not need a listing written for it, and writing one
         * invites somebody to wonder later why it never appears.
         */
        app(PageMeta::class)->set(
            title: __('site.auth.title'),
            robots: 'noindex, follow',
        );

        /*
         * The address, filled in when the link carries one (2026-09-26).
         *
         * The button in an invitation email leads here with the invited
         * address, so accepting is one press. Only ever a pre-filled field:
         * nothing is sent until the visitor presses the button themselves, and
         * anything that is not an address is dropped rather than echoed.
         */
        $email = $request->query('email');
        $email = is_string($email) && mb_strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            ? $email
            : null;

        return Inertia::render('Auth/Login', [
            // Staging may legitimately run without OAuth credentials, and a
            // button that leads to an exception is worse than no button.
            'googleEnabled' => filled(config('services.google.client_id'))
                && filled(config('services.google.client_secret')),
            'email' => $email,
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
        } catch (\Throwable $e) {
            /*
             * Any failure to send, not only a transport one.
             *
             * Caught in the act on staging: an unset MAIL_FROM_ADDRESS throws
             * `Symfony\Component\Mime\Exception\LogicException`, which is not a
             * transport exception at all — so a narrower catch left the one form
             * whose job is to be the way in returning a 500 for a missing
             * environment variable.
             *
             * Scoped tightly to the send: a database or validation problem
             * elsewhere in this action still surfaces as itself.
             */
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
    public function consume(Request $request, string $market, string $token, EmailSignIn $signIn): RedirectResponse
    {
        $loginToken = LoginToken::consume($token);

        if ($loginToken === null) {
            return redirect()->to("/{$market}/login")->withErrors([
                'email' => __('site.auth.link_invalid'),
            ]);
        }

        // Find or create, then sign in. Shared with the invitation button
        // (2026-09-27) so both create an account the same way.
        $signIn->signIn($request, $loginToken->email, $loginToken->name, $market);

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

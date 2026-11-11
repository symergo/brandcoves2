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
use Illuminate\Support\Facades\Log;
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
    /** Shortest time a person has the form open before sending; see automated(). */
    private const MIN_FORM_MS = 1200;

    /** Session key: the hash of the last link this browser asked for. See confirm(). */
    private const REQUESTED_HERE = 'magic_link_requested';

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

    public function send(Request $request, CurrentMarket $current, EmailSignIn $signIn): RedirectResponse
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

            // The bot trap, see automated().
            'website' => ['nullable', 'string', 'max:200'],
            'elapsed_ms' => ['nullable', 'integer'],
        ]);

        $email = mb_strtolower(trim($validated['email']));
        $name = filled($validated['name'] ?? null) ? trim((string) $validated['name']) : null;

        /*
         * A bot gets the same answer as a person, and nothing is sent.
         *
         * Found 2026-09-28: accounts named "GyfQDROfpEjLMJCPpjuwRjE" on real
         * company addresses. A bot typed the name and the address, we mailed
         * that stranger a link, and their mail scanner opened it. The same
         * answer as a real request, so the bot learns nothing to adapt to;
         * logged, so the trap can be seen working.
         */
        $reason = $this->automated($request);

        if ($reason !== null) {
            Log::info('Sign-in form: automated request dropped', [
                'reason' => $reason,
                'ip' => $request->ip(),
                'domain' => str($email)->after('@')->value(),
            ]);

            return back()->with('success', __('site.auth.link_sent'));
        }

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

        // This browser asked for this link, so it may open it without the
        // button; see confirm(). The hash, never the link itself: the session
        // is not where a working key to the account should sit.
        $request->session()->put(self::REQUESTED_HERE, hash('sha256', $token));

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

        /*
         * An address with no account yet is signed in at once (owner,
         * 2026-11-12), unconfirmed: the link just mailed is what confirms it.
         * See EmailSignIn::signIn() and docs/features/auth.md.
         *
         * This does make the answer differ by whether the address has an
         * account, which the form avoided until now. The owner's choice: one
         * step less for every new visitor, against a stranger learning that
         * an address is known here.
         */
        if ($signIn->signIn($request, $email, $name, $current->get()->value, proven: false) !== null) {
            $message = __('site.auth.signed_in_unconfirmed');
            $previous = (string) parse_url(url()->previous(), PHP_URL_PATH);

            // Back to the page the popup was opened on; from the login page
            // itself, on to their lists (a signed-in visitor has no login page).
            return str_ends_with(rtrim($previous, '/'), '/login')
                ? redirect()->intended($current->url('lists'))->with('success', $message)
                : back()->with('success', $message);
        }

        return back()->with('success', __('site.auth.link_sent'));
    }

    /**
     * What the link in the email opens: a page with one button.
     *
     * Opening the link used to sign in (2026-09-28). Company mail scanners open
     * every link in a message to check it, so the scanner signed in, which
     * created accounts for people who never asked (together with the bot that
     * typed their address) and spent the link before the real person clicked
     * it. The button POSTs; scanners do not press buttons. The invitation
     * email's button has worked this way since 2026-09-27 (InviteAcceptController).
     *
     * Except in the browser that asked for the link (2026-10-04): its session
     * holds the link's hash from send(), so it signs in at once, without the
     * extra press the owner found pointless. A scanner never has that session,
     * so it still gets the page. Someone who asks on the laptop and opens the
     * mail on the phone gets the button too, which is no worse than before.
     * Clicking a link in a mail is a top-level navigation, so the `lax`
     * session cookie is sent with it.
     *
     * `{market}` is consumed by middleware but still passed positionally.
     */
    public function confirm(Request $request, string $market, string $token, EmailSignIn $signIn): Response|RedirectResponse
    {
        $requested = $request->session()->get(self::REQUESTED_HERE);

        if (is_string($requested) && hash_equals($requested, hash('sha256', $token))) {
            $request->session()->forget(self::REQUESTED_HERE);

            // Spent, or expired: fall through to the page, which says so.
            if (($loginToken = LoginToken::consume($token)) !== null) {
                return $this->signInWith($request, $loginToken, $market, $signIn);
            }
        }

        app(PageMeta::class)->set(
            title: __('site.auth.confirm_title'),
            robots: 'noindex, nofollow',
        );

        $current = app(CurrentMarket::class);

        return Inertia::render('Auth/ConfirmLink', [
            // Null when the link is used up or expired: the page then says so
            // and offers a new one, rather than a button that fails.
            'confirmUrl' => LoginToken::peek($token) === null ? null : $current->url("auth/magic/{$token}"),
            'loginUrl' => $current->url('login'),
            // For the plain form: it must work before the page's script loads.
            'csrfToken' => csrf_token(),
        ]);
    }

    /**
     * Mails the link again to somebody signed in on an unconfirmed address.
     *
     * The link from send() expires in fifteen minutes and the account lasts
     * only as long as its session until one is pressed, so the bar that says
     * so (ConfirmEmailNotice) has to be able to send another. Same per-address
     * limit as the form: the address may still be somebody else's mailbox.
     */
    public function resend(Request $request, CurrentMarket $current): RedirectResponse
    {
        $user = $request->user();

        if ($user->emailConfirmed()) {
            return back();
        }

        $email = mb_strtolower((string) $user->email);
        $key = 'magic:'.$email;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->with('error', __('site.auth.too_many', ['seconds' => RateLimiter::availableIn($key)]));
        }
        RateLimiter::hit($key, 900);

        ['token' => $token] = LoginToken::issue($email, $request->ip(), $user->name);
        $request->session()->put(self::REQUESTED_HERE, hash('sha256', $token));

        try {
            Mail::to($email)->send(new MagicLinkMail(
                token: $token,
                market: $current->get(),
                requestedFrom: $request->ip(),
            ));
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', __('site.auth.mail_failed'));
        }

        return back()->with('success', __('site.auth.link_sent'));
    }

    /** The page's button. `{market}` is consumed by middleware but still passed positionally. */
    public function consume(Request $request, string $market, string $token, EmailSignIn $signIn): RedirectResponse
    {
        $loginToken = LoginToken::consume($token);

        if ($loginToken === null) {
            return redirect()->to("/{$market}/login")->withErrors([
                'email' => __('site.auth.link_invalid'),
            ]);
        }

        return $this->signInWith($request, $loginToken, $market, $signIn);
    }

    private function signInWith(Request $request, LoginToken $loginToken, string $market, EmailSignIn $signIn): RedirectResponse
    {
        // Find or create, then sign in. Shared with the invitation button
        // (2026-09-27) so both create an account the same way.
        $signIn->signIn($request, $loginToken->email, $loginToken->name, $market);

        return redirect()->intended(app(CurrentMarket::class)->url('lists'));
    }

    /**
     * Why a sign-in request looks automated, or null when it looks like a person.
     *
     * No captcha: this form is the way in, and a puzzle in front of it costs
     * real people more than it costs bots. Two checks instead, both invisible:
     *
     * - `website` is a field no person sees (it is off-screen and skipped by
     *   the keyboard). A bot filling in every field fills it in.
     * - `elapsed_ms` is how long the form was open, sent by the page. A bot
     *   posting straight to this address sends none, and a script that loads
     *   the page submits faster than anybody types. `MIN_FORM_MS` is short
     *   enough for someone whose address is filled in already (the invitation
     *   email's link does that) and presses the button at once.
     */
    private function automated(Request $request): ?string
    {
        if (filled($request->input('website'))) {
            return 'hidden field filled';
        }

        $elapsed = $request->input('elapsed_ms');

        if (! is_numeric($elapsed)) {
            return 'no form time';
        }

        return (int) $elapsed < self::MIN_FORM_MS ? 'too fast' : null;
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect(app(CurrentMarket::class)->url());
    }
}

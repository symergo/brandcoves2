<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Mail\CoveConfirmationMail;
use App\Models\CoveSubscriber;
use App\Support\CurrentMarket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Subscribe to, confirm, and leave the Daily Cove.
 *
 * ## Double opt-in
 *
 * A signup creates an unconfirmed row and sends exactly one email. Nothing else
 * is ever sent to an address that has not clicked. The legal argument (consent
 * must be demonstrable) is the weaker one; the operational argument is that a
 * form anyone can type any address into is a way to mail people who never asked,
 * and the first time that happens at volume the domain's sending reputation is
 * gone for months.
 *
 * ## The response never reveals whether an address is subscribed
 *
 * Every outcome — new signup, already confirmed, previously unsubscribed —
 * returns the same message. Otherwise the form is an oracle: type an address,
 * read the response, learn whether that person reads this site. Same reasoning
 * as the magic-link flow.
 */
class CoveSubscriptionController extends Controller
{
    public function store(Request $request, CurrentMarket $current): RedirectResponse
    {
        /*
         * `email:rfc`, deliberately without the `dns` check.
         *
         * A DNS lookup catches typo'd domains, and it does so with a blocking
         * network call in the middle of a form submission — variable latency, and
         * a failing resolver turns "subscribe" into a 422 for everyone. The
         * confirmation email already catches an undeliverable address: nothing is
         * ever sent to an unconfirmed row, so a bad domain simply never confirms.
         */
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
        ]);

        $market = $current->get();
        $email = CoveSubscriber::normaliseEmail($validated['email']);

        /*
         * Rate limited per address as well as per IP.
         *
         * Per IP alone still allows a distributed signup flood at one victim's
         * address, which is a mailbombing service with our domain on it. Three
         * confirmation mails an hour to one address is generous for a human and
         * useless for that.
         */
        $key = 'cove-subscribe:'.sha1($email);

        if (RateLimiter::tooManyAttempts($key, 3)) {
            return back();
        }

        RateLimiter::hit($key, 3600);

        $subscriber = CoveSubscriber::query()->firstOrNew([
            'market' => $market->value,
            'email' => $email,
        ]);

        if ($subscriber->exists && $subscriber->isConfirmed()) {
            // Already on the list. Say nothing different — see the class docblock.
            return back();
        }

        $subscriber->fill([
            'confirm_token' => CoveSubscriber::newToken(),
            'confirm_sent_at' => now(),
            'signup_ip' => $request->ip(),
            'signup_source' => substr((string) $request->input('source', 'web'), 0, 64),
            // Re-subscribing clears the old departure. The row is reused rather
            // than duplicated, which is what keeps the unique index — and
            // therefore "one copy of each edition" — meaningful.
            'unsubscribed_at' => null,
        ]);

        $subscriber->unsubscribe_token ??= CoveSubscriber::newToken();
        $subscriber->save();

        Mail::to($email)->send(new CoveConfirmationMail(
            token: (string) $subscriber->confirm_token,
            market: $market,
            requestedFrom: $request->ip(),
        ));

        /*
         * No flash, on any of the three paths out of here.
         *
         * `CoveSubscribe` draws the confirmation itself, in place of the form,
         * so that a second submission is not invited — and the layout draws
         * `flash.status` too, which meant the card and a banner at the top of
         * the page carried the identical sentence about the identical press.
         * The card keeps it, because that is where the field was.
         *
         * All three returns are still the same bare `back()`, which is the
         * property that matters here: what happened must not be legible from
         * the response. See the class docblock.
         */
        return back();
    }

    public function confirm(CurrentMarket $current, string $marketSegment, string $token): RedirectResponse
    {
        $subscriber = CoveSubscriber::query()
            ->forMarket($current->get())
            ->where('confirm_token', $token)
            ->first();

        if ($subscriber === null || ! $subscriber->confirmTokenIsFresh()) {
            return redirect($current->url('daily'))
                ->with('status', __('site.cove.confirm_invalid'));
        }

        $subscriber->forceFill([
            'confirmed_at' => $subscriber->confirmed_at ?? now(),
            // Cleared, so a link sitting in an abandoned mailbox cannot
            // re-confirm an address that has since left.
            'confirm_token' => null,
            'unsubscribed_at' => null,
        ])->save();

        return redirect($current->url('daily'))
            ->with('status', __('site.cove.confirm_done'));
    }

    /**
     * Leave.
     *
     * GET, and deliberately not behind a confirmation step. Email clients cannot
     * POST from a footer link, a reader who cannot leave in one click marks the
     * mail as spam instead, and a spam complaint costs the domain far more than
     * an unsubscribe does. The token is unguessable, so the only person who can
     * trigger this is someone holding an email we sent.
     *
     * Also reachable by POST, for RFC 8058 one-click unsubscribe.
     */
    public function unsubscribe(Request $request, CurrentMarket $current, string $marketSegment, string $token): RedirectResponse
    {
        $subscriber = CoveSubscriber::query()
            ->forMarket($current->get())
            ->where('unsubscribe_token', $token)
            ->first();

        // The row survives with a timestamp rather than being deleted: it is the
        // evidence that someone opted out, and deleting it means a later signup
        // form cannot tell that they did.
        $subscriber?->forceFill(['unsubscribed_at' => now()])->save();

        // Unknown token gets the same page. A 404 here would confirm which
        // tokens are real, and there is nothing useful to tell someone whose
        // link is malformed anyway.
        return redirect($current->url('daily'))
            ->with('status', __('site.cove.unsubscribed'));
    }
}

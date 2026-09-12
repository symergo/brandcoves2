<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Mail\NewRegistrationMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * What happens the moment an account is created, and nowhere else.
 *
 * There is no sign-up form: the first sign-in creates the account, on either
 * path (docs/features/auth.md). Both callbacks therefore have a line that
 * reads "this user was just created", and everything that should follow from
 * that line lives here rather than being written twice and kept in step by
 * hand. Two things follow today:
 *
 * - The page after the redirect reports a `sign_up` event to analytics, so
 *   registrations can be counted as a conversion. The callback flashes how
 *   the account signed in; `HandleInertiaRequests` shares it for one request.
 *   See docs/features/analytics.md.
 * - The owner gets an email. Asked for on 2026-09-12: the site is small enough
 *   that every new account is news, and the admin panel is not somewhere
 *   anybody looks daily. Off unless `REGISTRATION_NOTIFY_EMAIL` is set, and
 *   queued, so a slow mail server never slows the person signing in.
 */
final class Registration
{
    /**
     * @param  'google'|'email'  $method  how the account signed in
     * @param  string  $market  the market the sign-in happened in
     */
    public function record(Request $request, User $user, string $method, string $market): void
    {
        $request->session()->flash('signed_up', $method);

        $notify = config('giftcoves.registrations.notify');

        if (! is_string($notify) || trim($notify) === '') {
            return;
        }

        Mail::to(trim($notify))->queue(new NewRegistrationMail(
            name: (string) $user->name,
            email: (string) $user->email,
            method: $method,
            market: $market,
            // Counted here, once, rather than in the mail: the mail may be
            // sent minutes later from the queue, and "you now have 41" should
            // be the number at the moment this account made it 41.
            total: User::query()->count(),
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Http\Middleware\EndProvisionalSession;
use App\Models\User;
use App\Services\Auth\EmailSignIn;
use Illuminate\Auth\Events\Login;

/**
 * Every session of an unconfirmed account is marked provisional, however it
 * began.
 *
 * Since 2026-11-12 an unconfirmed account keeps a remember cookie too (owner:
 * "why can't the cookie last longer? The most important thing is that there
 * are no emails sent to the email until confirmed"). A remember cookie starts
 * new sessions by itself, and those would not carry the mark that
 * {@see EmailSignIn} sets, so {@see EndProvisionalSession}
 * would not see them. Marking at the `Login` event covers both: the form's
 * own sign-in and one that came back through the cookie.
 *
 * A listener, like {@see ReplayPendingSave}: there is more than one way to
 * become signed in, and this must hold for all of them.
 */
class MarkProvisionalSession
{
    public function handle(Login $event): void
    {
        $user = $event->user;
        $request = request();

        if (! $user instanceof User || $user->emailConfirmed() || ! $request->hasSession()) {
            return;
        }

        $mark = $request->session()->get(EmailSignIn::PROVISIONAL);

        if (! is_array($mark) || (int) ($mark['user'] ?? 0) !== (int) $user->id) {
            $request->session()->put(EmailSignIn::PROVISIONAL, ['user' => $user->id, 'ip' => (string) $request->ip()]);
        }
    }
}

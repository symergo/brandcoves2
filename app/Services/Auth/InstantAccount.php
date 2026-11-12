<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\Market;
use App\Http\Controllers\Auth\MagicLinkController;
use App\Mail\MagicLinkMail;
use App\Models\LoginToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * An account on the spot, from a form that is about something else.
 *
 * The sign-in form signs a new address in at once and mails the link that
 * confirms it (docs/features/auth.md, "Unconfirmed accounts"). A form that
 * needs an account to finish, such as starting a Secret Friend group, does
 * the same through here instead of sending its visitor to sign in first.
 *
 * Only ever for an address with **no account**: typing an address that has
 * one proves nothing, so that returns null and the caller asks them to sign
 * in.
 */
class InstantAccount
{
    public function __construct(private readonly EmailSignIn $signIn) {}

    public function start(Request $request, string $email, ?string $name, Market $market): ?User
    {
        $user = $this->signIn->signIn($request, $email, $name, $market->value, proven: false);

        if ($user === null) {
            return null;
        }

        ['token' => $token] = LoginToken::issue(mb_strtolower(trim($email)), $request->ip(), $name);

        // This browser asked, so it may open the link without the extra button.
        $request->session()->put(MagicLinkController::REQUESTED_HERE, hash('sha256', $token));

        try {
            Mail::to($user->email)->send(new MagicLinkMail(
                token: $token,
                market: $market,
                requestedFrom: $request->ip(),
            ));
        } catch (\Throwable $e) {
            // The account and the session exist; the bar under the header
            // (ConfirmEmailNotice) can mail the link again.
            report($e);
        }

        return $user;
    }
}

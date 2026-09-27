<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Models\FriendInviteToken;
use App\Models\User;
use App\Services\Auth\EmailSignIn;
use Illuminate\Http\Request;

/**
 * "Uitnodiging aannemen": the button in an invitation email, which creates a
 * new invitee's account and signs them in without a second email
 * (owner, 2026-09-27).
 *
 * Until then the button opened the sign-in page with the address filled in,
 * and the invitee had to ask for a magic link: a second email to prove they
 * own an address the first one had just reached. The invitation already
 * proves it, so the button now carries its own single-use token
 * ({@see FriendInviteToken}).
 *
 * ## Never an existing account
 *
 * The token signs in only an account it creates. An address that has an
 * account (then, or by the time the button is pressed) is sent to the
 * sign-in page instead. An invitation lives two weeks and sits in an inbox,
 * possibly forwarded; for a new address the worst it opens is an empty
 * account, but for an existing one it would be a standing key to a person's
 * lists, notes and people, handed out by whoever typed their address. A magic
 * link is fifteen minutes for exactly that reason.
 *
 * ## The connection
 *
 * Made the way every sign-in makes it: {@see EmailSignIn} fires Login, and
 * LinkSharerAsFriend applies the waiting `friend_invites` (friendship,
 * birthday, the saved person). Not from the token's inviter: a member who
 * withdrew the invitation since must not be connected by an old email.
 */
class InviteAcceptance
{
    public function __construct(private readonly EmailSignIn $signIn) {}

    /**
     * The invitation behind a button, if pressing it would create an account.
     * Reads only; opening the link consumes nothing, because mail scanners
     * open every link in an email.
     */
    public function acceptable(string $token): ?FriendInviteToken
    {
        $row = FriendInviteToken::findByPlaintext($token);

        if ($row === null || ! $row->isUsable() || $this->hasAccount($row->email)) {
            return null;
        }

        return $row->inviter()->exists() ? $row : null;
    }

    /**
     * The address a button was for, whatever its state, to fill in the
     * sign-in page it falls back to. The holder of the link has the email it
     * came in, so this tells them nothing new.
     */
    public function addressFor(string $token): ?string
    {
        return FriendInviteToken::findByPlaintext($token)?->email;
    }

    /**
     * Use the button: the new account, signed in, or null (unknown, used,
     * expired, or the address has an account by now).
     */
    public function accept(Request $request, string $token, string $market): ?User
    {
        $row = FriendInviteToken::consume($token);

        if ($row === null) {
            return null;
        }

        return $this->signIn->signIn($request, $row->email, null, $market, onlyNew: true);
    }

    private function hasAccount(string $email): bool
    {
        return User::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->exists();
    }
}

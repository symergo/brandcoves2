<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Http\Middleware\TrackAnonymousIdentity;
use App\Models\AnonymousIdentity;
use App\Models\User;
use App\Services\Social\InviteAcceptance;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Signing somebody in by a proven email address: the magic link, and since
 * 2026-09-27 the button in an invitation email.
 *
 * Moved out of MagicLinkController so both paths create an account in exactly
 * the same way (same fields, the Registration record, the verified address,
 * the anonymous identity merged, the Login event that connects waiting
 * invitations). A second copy would drift, and the drift would only show for
 * whichever half of people came in the other way.
 */
class EmailSignIn
{
    public function __construct(private readonly IdentityMerger $merger) {}

    /**
     * Find or create the account for this address and sign it in.
     *
     * With `$onlyNew`, an address that already has an account is not signed
     * in and null comes back; the invitation button uses that (see
     * {@see InviteAcceptance}).
     */
    public function signIn(Request $request, string $email, ?string $name, string $market, bool $onlyNew = false): ?User
    {
        $email = mb_strtolower(trim($email));

        // Case-insensitive, matching the unique index on lower(email), so
        // Alice@ and alice@ are one person rather than two accounts with half
        // a gift list each.
        $user = $this->find($email);

        if ($user !== null && $onlyNew) {
            return null;
        }

        if ($user === null) {
            try {
                $user = User::create(['email' => $email, 'name' => $name]);
            } catch (UniqueConstraintViolationException) {
                // Created by another request in the meantime (two links
                // pressed at once). That account is the one to use, unless
                // only a new one would do.
                $user = $onlyNew ? null : $this->find($email);

                if ($user === null) {
                    return null;
                }
            }
        }

        // A new account: the analytics event and the owner's email both
        // start here. See App\Services\Auth\Registration.
        if ($user->wasRecentlyCreated) {
            app(Registration::class)->record($request, $user, 'email', $market);
        }

        // An account that never got a name takes the one just typed. Never
        // overwrites: a name already set is theirs, not the login form's.
        if (blank($user->name) && filled($name)) {
            $user->forceFill(['name' => $name])->save();
        }

        // Proof of mailbox control is exactly what both paths establish.
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        // Everything built before signing up moves across. Do this BEFORE
        // logging in, while the anonymous cookie identity is still resolvable.
        $anonId = $request->cookie(TrackAnonymousIdentity::COOKIE);
        if (is_string($anonId)) {
            $anon = AnonymousIdentity::find($anonId);
            if ($anon !== null) {
                $this->merger->merge($anon, $user);
            }
        }

        // Fires Login, which LinkSharerAsFriend turns into connections.
        Auth::login($user, remember: true);
        // A fresh session id after a privilege change; otherwise a session
        // fixed before login stays valid after it.
        $request->session()->regenerate();

        return $user;
    }

    private function find(string $email): ?User
    {
        return User::query()->whereRaw('lower(email) = ?', [$email])->first();
    }
}

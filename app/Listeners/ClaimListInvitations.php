<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Services\Wishlist\Invitations;
use Illuminate\Auth\Events\Login;

/**
 * Turn every waiting invitation into access, the moment somebody signs in.
 *
 * A listener rather than two edits in `MagicLinkController` and
 * `GoogleController`, because those are not the only two sign-in paths this
 * application will ever have — and an invitation that redeems on one path and
 * not the other is a bug that only shows up for whichever half of people used
 * the other button.
 *
 * Deliberately **not** queued. The redirect after login usually lands on the
 * lists page, and an invitation redeemed a second later means somebody arrives,
 * sees nothing, and concludes the link did not work. Two indexed queries and a
 * couple of inserts is a fair price for the page being right when it renders.
 *
 * Matching is on the email address, which is the only thing the inviter knew.
 * That is also why this cannot run before the account exists.
 */
class ClaimListInvitations
{
    public function __construct(private readonly Invitations $invitations) {}

    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $this->invitations->claimFor($user);
    }
}

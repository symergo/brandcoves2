<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Services\Social\FriendInvites;
use App\Services\Social\Friends;
use App\Services\Social\ShareReferral;
use Illuminate\Auth\Events\Login;

/**
 * Connect somebody to the person whose link brought them here.
 *
 * A listener for the same reason {@see ReplayPendingSave} and
 * {@see ReplayPendingClaim} are: there are two sign-in paths today and nothing
 * promises there will not be a third, and a connection made on the magic link
 * but not on Google is a bug visible only to whichever half of people pressed
 * the other button.
 *
 * Not queued. It is two upserts, and the page they land on may be the friend
 * list itself.
 */
class LinkSharerAsFriend
{
    public function __construct(
        private readonly ShareReferral $referral,
        private readonly Friends $friends,
        private readonly FriendInvites $invites,
    ) {}

    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $this->referral->applyTo($user, $this->friends);

        /*
         * And whoever added this address before it had an account.
         *
         * The same moment and the same reasoning, so the same listener: an
         * invite that applied on the magic link but not on Google would be
         * invisible to whichever half of people pressed the other button.
         */
        $this->invites->applyTo($user);
    }
}

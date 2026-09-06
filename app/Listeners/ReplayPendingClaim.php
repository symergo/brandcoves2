<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Services\Wishlist\PendingClaim;
use Illuminate\Auth\Events\Login;

/**
 * Finish the claim the visitor started before they had an account.
 *
 * A listener for the same reason {@see ReplayPendingSave} is one: there are two
 * sign-in paths today and nothing promises there will not be a third, and a
 * claim that completes on the magic link but not on Google is a bug visible
 * only to whichever half of people pressed the other button.
 *
 * Deliberately **not** queued. The redirect after sign-in lands the visitor
 * back on the list they were trying to claim from, and a claim applied a second
 * later means they arrive, see the item unclaimed, and press again — on a race
 * they have already won.
 */
class ReplayPendingClaim
{
    public function __construct(private readonly PendingClaim $pending) {}

    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $title = $this->pending->claimFor($user);

        if ($title === null) {
            return;
        }

        /*
         * Said out loud, unlike an ordinary claim.
         *
         * A claim pressed on the page announces nothing, because the row itself
         * changes under the finger that pressed it — see
         * `SharedListController::claim()`. This one happened during a sign-in,
         * somewhere else, possibly in another tab an hour later, and the row it
         * changed may be well down a list the person has not scrolled yet.
         */
        session()->flash('success', __('site.lists.claimed_item', ['item' => $title]));
    }
}

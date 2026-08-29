<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Services\Wishlist\DefaultList;
use App\Services\Wishlist\ItemSaver;
use App\Services\Wishlist\PendingSave;
use Illuminate\Auth\Events\Login;

/**
 * Finish the save the visitor started before they had an account.
 *
 * A listener for the same reason {@see ClaimListInvitations} is one: there are
 * two sign-in paths today and nothing promises there will not be a third, and a
 * save that completes on the magic link but not on Google is a bug visible only
 * to whichever half of people pressed the other button.
 *
 * Deliberately **not** queued. The redirect after sign-in lands the visitor
 * back on the product they were trying to keep, and a save applied a second
 * later means they arrive, see an empty bookmark, and conclude it did not work.
 * One insert is a fair price for the page being right when it renders.
 */
class ReplayPendingSave
{
    public function __construct(
        private readonly PendingSave $pending,
        private readonly ItemSaver $saver,
        private readonly DefaultList $lists,
    ) {}

    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $list = $this->pending->replayFor($user, $this->saver, $this->lists);

        if ($list === null) {
            return;
        }

        /*
         * Said out loud, through the ordinary flash channel.
         *
         * A save that happens silently during a sign-in is indistinguishable
         * from one that did not happen — and this is the one save the visitor
         * has already had reason to doubt. It names the list for the same
         * reason every other confirmation does.
         */
        session()->flash('success', __('site.lists.added_to', ['list' => $list]));
    }
}

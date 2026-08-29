<?php

declare(strict_types=1);

namespace App\Services\Wishlist;

use App\Enums\CollaboratorRole;
use App\Models\ListInvitation;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistCollaborator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Inviting somebody to help choose, and redeeming that invitation later.
 *
 * ## Both branches do the same visible thing
 *
 * Whether or not the address has an account, `invite()` writes a row, queues a
 * mail and returns nothing. That symmetry is the security property: the form
 * must not be a way to discover which of your friends use the site, and the
 * cheapest way to guarantee that is to make the two paths genuinely identical
 * rather than to make them merely *look* identical from the controller.
 *
 * An address that already has an account still gets a mail — they need to know
 * a list is waiting for them, and they were told nothing at all before.
 *
 * ## Redeeming is on sign-in, not on click
 *
 * The token is in an email, and a link in an email is followed by whoever has
 * the inbox — which is the right person, but proves nothing about who is at the
 * keyboard on a shared machine. So the invitation is claimed the moment an
 * account exists for the address it was sent to, and the token in the URL only
 * decides *which* list to jump to afterwards. `claimFor()` is called from the
 * login flow, so an invitation redeems itself even if the link is lost.
 */
class Invitations
{
    /*
     * `invite()` lived here and is gone with the form that called it: sharing
     * is a link now, which grants the same access without needing an address.
     *
     * `claimFor()` stays, and the distinction is the point. Invitations already
     * sent are sitting in real inboxes, and a link in an email is followed
     * whenever somebody gets round to it. Deleting the redemption path would
     * turn every one of those into a dead end long after anybody could work out
     * why.
     */
    /**
     * Invite an address, whatever state it is in.
     *
     * Idempotent per (list, address): inviting the same person twice re-sends
     * rather than piling up rows, which is also what an owner expects when they
     * press it again because the first one went to spam.
     */
    /**
     * Redeem everything waiting for this account.
     *
     * Called on sign-in rather than from the link, so somebody who lost the
     * mail still ends up on the list. Matching is on the address, which is the
     * only thing the inviter knew about them.
     *
     * @return int how many were redeemed
     */
    public function claimFor(User $user): int
    {
        $pending = ListInvitation::query()
            ->open()
            ->whereRaw('lower(email) = ?', [mb_strtolower((string) $user->email)])
            ->with('wishlist')
            ->get();

        $claimed = 0;

        foreach ($pending as $invitation) {
            // The list may have been deleted or handed over since. An
            // invitation to a list that is no longer the inviter's is not
            // honoured — handing a list over purges its collaborators
            // deliberately, and this would put one straight back.
            if ($invitation->wishlist === null
                || $invitation->wishlist->owner_user_id !== $invitation->invited_by_user_id) {
                $invitation->forceFill(['claimed_at' => now()])->save();

                continue;
            }

            if ($invitation->wishlist->owner_user_id === $user->id) {
                $invitation->forceFill(['claimed_at' => now()])->save();

                continue;
            }

            DB::transaction(function () use ($invitation, $user): void {
                $this->grant($invitation->wishlist, $user, $invitation->role);
                $invitation->forceFill(['claimed_at' => now()])->save();
            });

            $claimed++;
        }

        return $claimed;
    }

    private function grant(Wishlist $list, User $user, CollaboratorRole $role): void
    {
        WishlistCollaborator::updateOrCreate(
            ['wishlist_id' => $list->id, 'user_id' => $user->id],
            ['role' => $role->value],
        );
    }
}

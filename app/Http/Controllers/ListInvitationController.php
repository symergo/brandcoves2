<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ListInvitation;
use App\Services\Wishlist\Invitations;
use App\Support\CurrentMarket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Following an invitation link.
 *
 * The token decides **which list to land on**, not whether access is granted.
 * Granting happens on sign-in, from `Invitations::claimFor()`, because a link
 * in an email is followed by whoever holds the inbox — the right person, almost
 * always, but a forwarded mail or a shared machine is not something a URL can
 * tell apart. Requiring the account to exist for the invited address is what
 * makes "you were invited" mean something.
 *
 * The consequence is deliberate: a signed-out visitor is sent to sign in, and
 * the invitation redeems itself on the way back — even if they never return to
 * this URL at all.
 */
class ListInvitationController extends Controller
{
    public function __invoke(
        Request $request,
        CurrentMarket $current,
        string $market,
        string $token,
        Invitations $invitations,
    ): RedirectResponse {
        $invitation = ListInvitation::query()->where('token', $token)->first();

        if ($invitation === null) {
            throw new NotFoundHttpException;
        }

        $user = $request->user();

        if ($user === null) {
            // Not an error. They have the link; they simply have to be
            // somebody first, and the invitation is waiting either way.
            return redirect()
                ->to($current->url('login'))
                ->with('status', __('site.invitations.sign_in_first'));
        }

        // Redeems everything waiting for this address, not only this token — a
        // second invitation whose mail was lost lands at the same time.
        $invitations->claimFor($user);

        /*
         * An expired or already-claimed invitation still lands them on the
         * list *if they can see it*, and 404s otherwise. Saying "this
         * invitation expired" to somebody who already has access would be
         * technically true and useless.
         */
        $list = $invitation->wishlist;

        if ($list === null) {
            throw new NotFoundHttpException;
        }

        return redirect()->to($current->url("lists/{$list->id}"));
    }
}

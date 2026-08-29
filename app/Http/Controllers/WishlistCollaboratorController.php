<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Wishlist;
use App\Models\WishlistCollaborator;
use App\Support\ListAccess;
use App\Support\Owner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Taking back access that was granted by name.
 *
 * Co-givers used to be added one email address at a time, with a role each.
 * Sharing is a link now: it grants the same thing to whoever it reaches,
 * without needing to know their address or doing it again for each of them,
 * and `ListOpen` remembers who followed one so the list is still findable
 * afterwards. So nothing invites any more.
 *
 * **What remains is the undo.** Real people were granted real access this way
 * before the change, and dropping the roster would either strand them there
 * permanently or revoke them silently. Neither is a decision this code gets to
 * make on an owner's behalf.
 *
 * Only the owner: a collaborator who could remove collaborators is a list whose
 * audience changes under the person who made it.
 */
class WishlistCollaboratorController extends Controller
{
    public function destroy(Request $request, string $market, string $list, string $collaborator): RedirectResponse
    {
        $wishlist = $this->findOwned($request, $list);

        WishlistCollaborator::query()
            ->where('wishlist_id', $wishlist->id)
            ->whereKey($collaborator)
            ->delete();

        return back()->with('success', __('site.lists.collaborator_removed'));
    }

    /**
     * The roster is the owner's alone.
     *
     * `ListAccess::scope()` would also match a collaborator, which is right for
     * reading the list and wrong for changing who else can read it.
     */
    private function findOwned(Request $request, string $list): Wishlist
    {
        $owner = Owner::fromRequest($request);

        $wishlist = $owner->scope(Wishlist::query())->find($list);

        if ($wishlist === null || ! ListAccess::isOwner($wishlist, $owner)) {
            throw new NotFoundHttpException;
        }

        return $wishlist;
    }
}

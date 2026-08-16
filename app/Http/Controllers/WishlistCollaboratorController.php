<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CollaboratorRole;
use App\Models\Wishlist;
use App\Models\WishlistCollaborator;
use App\Services\Wishlist\Invitations;
use App\Support\CurrentMarket;
use App\Support\ListAccess;
use App\Support\Owner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Co-givers.
 *
 * Buying for one person is usually done by several — siblings for a parent,
 * a couple for a friend — and the coordination problem is the same one claiming
 * solves on a shared list, except the people involved are all *givers*.
 *
 * Only the owner manages the roster. A collaborator who could invite more
 * collaborators is a list that quietly grows an audience, and the whole point
 * of a `for_someone` list is that its subject never sees it.
 */
class WishlistCollaboratorController extends Controller
{
    public function store(
        Request $request,
        CurrentMarket $current,
        string $market,
        string $list,
        Invitations $invitations,
    ): RedirectResponse {
        $wishlist = $this->findOwned($request, $list);

        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:190'],
            'role' => ['nullable', 'string', 'in:'.implode(',', CollaboratorRole::values())],
        ]);

        /*
         * Deliberately not an oracle, and now genuinely so.
         *
         * The response must be identical whether or not that address has an
         * account, or anyone could type addresses into this form and learn
         * which of their friends use the site. It used to be identical because
         * the no-account branch did *nothing* — the owner was told "they can see
         * this list now" when nobody had been invited to anything, which is the
         * common case: the person whose help you want is exactly the person who
         * has not signed up yet.
         *
         * `Invitations::invite()` now writes a row and queues a mail in both
         * cases, so the two paths are the same act rather than merely the same
         * response.
         */
        $invitations->invite(
            list: $wishlist,
            from: $request->user(),
            email: $validated['email'],
            role: CollaboratorRole::tryFrom($validated['role'] ?? '') ?? CollaboratorRole::Viewer,
            current: $current,
        );

        return back()->with('success', __('site.lists.collaborator_invited'));
    }

    public function destroy(Request $request, CurrentMarket $current, string $market, string $list, string $collaborator): RedirectResponse
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

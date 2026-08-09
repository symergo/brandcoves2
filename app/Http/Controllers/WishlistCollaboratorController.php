<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CollaboratorRole;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistCollaborator;
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
    public function store(Request $request, CurrentMarket $current, string $market, string $list): RedirectResponse
    {
        $wishlist = $this->findOwned($request, $list);

        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:190'],
            'role' => ['nullable', 'string', 'in:'.implode(',', CollaboratorRole::values())],
        ]);

        $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower($validated['email'])])->first();

        /*
         * Deliberately not an oracle.
         *
         * The response is identical whether or not that address has an account,
         * because otherwise anyone could type addresses into this form and learn
         * which of their friends use the site. Same reasoning as the magic-link
         * flow and the cove signup.
         *
         * An invitation to an address with no account is therefore a no-op today
         * rather than an error. Delivering it by email is the natural next step
         * and it changes nothing about this check.
         */
        if ($user !== null && $user->id !== $wishlist->owner_user_id) {
            WishlistCollaborator::updateOrCreate(
                ['wishlist_id' => $wishlist->id, 'user_id' => $user->id],
                ['role' => $validated['role'] ?? CollaboratorRole::Viewer->value],
            );
        }

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

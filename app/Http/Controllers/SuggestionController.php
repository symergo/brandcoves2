<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ProductGroup;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Wishlist\ItemSaver;
use App\Support\CurrentMarket;
use App\Support\ListAccess;
use App\Support\Owner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "I think you would like this."
 *
 * The empty-list problem, attacked from the opposite side to the quiz: rather
 * than persuading you to fill in your own list, the people who know you fill it
 * in for you. Somebody opens your shared list, sees it is thin, and adds the
 * thing they have been meaning to tell you about.
 *
 * A suggestion is not on the list until you accept it. That matters for more
 * than tidiness — an unfiltered list would let anyone with the link put
 * anything in front of everyone else holding it.
 *
 * ## Not claim state
 *
 * Unusually for this feature, a suggestion is visible to the list owner and
 * *should* be: it is a message addressed to them. What stays hidden is
 * everything downstream — nobody may claim a pending suggestion, because
 * claiming one would tell the owner it exists by way of it being unavailable.
 */
class SuggestionController extends Controller
{
    public function store(Request $request, CurrentMarket $current, string $market, string $token, ItemSaver $saver): RedirectResponse
    {
        $list = $this->findShared($token);
        $owner = Owner::fromRequest($request);

        abort_unless($owner->exists(), 403);

        // Suggesting to yourself is just adding, and the list page already does
        // that without the round trip through approval.
        abort_if($list->shouldHideClaimsFrom($owner), 403);

        // Only somebody's own list. A `for_someone` list is private research,
        // and suggesting into it would tell a stranger it exists.
        abort_unless($list->allowsClaiming(), 403);

        $validated = $request->validate([
            'group_id' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $group = ProductGroup::query()
            ->forMarket($current->get())
            ->find($validated['group_id']);

        if ($group === null) {
            throw new NotFoundHttpException;
        }

        $item = $saver->saveGroup($list, $group, $current, $validated['note'] ?? null);

        /*
         * Explicitly pending.
         *
         * The column defaults to accepted, so this is the one place that has to
         * say otherwise — which is the right way round: forgetting it here
         * makes a suggestion appear immediately, while the old arrangement made
         * forgetting it anywhere else silently hide a real item.
         */
        $item->forceFill(['suggested_by_user_id' => $request->user()?->id, 'accepted_at' => null])->save();

        return back()->with('success', __('site.suggestions.sent'));
    }

    public function accept(Request $request, CurrentMarket $current, string $market, string $item): RedirectResponse
    {
        $this->findSuggestion($request, $item)->update(['accepted_at' => now()]);

        return back()->with('success', __('site.suggestions.accepted'));
    }

    public function destroy(Request $request, CurrentMarket $current, string $market, string $item): RedirectResponse
    {
        $this->findSuggestion($request, $item)->delete();

        return back()->with('success', __('site.suggestions.dismissed'));
    }

    /** A pending suggestion on a list this person owns. */
    private function findSuggestion(Request $request, string $item): WishlistItem
    {
        $owner = Owner::fromRequest($request);

        $wishlistItem = WishlistItem::query()
            ->whereKey($item)
            ->whereNull('accepted_at')
            ->whereHas('wishlist', fn ($q) => ListAccess::scope($q, $owner))
            ->with('wishlist')
            ->first();

        if ($wishlistItem === null) {
            throw new NotFoundHttpException;
        }

        // Accepting is the owner's alone: a collaborator deciding what appears
        // on somebody else's wishlist is not collaboration.
        abort_unless(ListAccess::isOwner($wishlistItem->wishlist, $owner), 403);

        return $wishlistItem;
    }

    private function findShared(string $token): Wishlist
    {
        $list = Wishlist::query()
            ->where('share_token', $token)
            ->where('visibility', '!=', 'private')
            ->first();

        if ($list === null) {
            throw new NotFoundHttpException;
        }

        return $list;
    }
}

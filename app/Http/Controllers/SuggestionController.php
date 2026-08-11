<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ProductGroup;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Rules\SafeExternalUrl;
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

        /*
         * Two shapes, one endpoint: a product we stock, or something typed in.
         *
         * The thing somebody most wants to put forward is often the thing we do
         * not sell — a voucher, a local shop, a book in one particular edition.
         * Sending them away empty because the search box found nothing wastes
         * the one moment they were willing to help.
         *
         * Same saver, same pending state, same accept/dismiss row for the owner.
         * A suggestion is a suggestion whichever way it was written.
         */
        $validated = $request->validate([
            'group_id' => ['nullable', 'integer', 'required_without:title'],
            'title' => ['nullable', 'string', 'max:500', 'required_without:group_id'],
            'url' => ['nullable', 'string', 'max:2048', new SafeExternalUrl],
            'price' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        if (empty($validated['group_id'])) {
            $item = $saver->saveManual(
                list: $list,
                title: $validated['title'],
                url: $validated['url'] ?? null,
                price: $validated['price'] ?? null,
                note: $validated['note'] ?? null,
            );

            return $this->pending($request, $item);
        }

        $group = ProductGroup::query()
            ->forMarket($current->get())
            ->find($validated['group_id']);

        if ($group === null) {
            throw new NotFoundHttpException;
        }

        /*
         * Something already on the list is not a suggestion.
         *
         * `ItemSaver::saveGroup()` is an `updateOrCreate` on
         * `(wishlist_id, group_id)`, and this method nulls `accepted_at`
         * immediately afterwards — so suggesting a product the owner already
         * has would take a real item *off* their list and turn it back into a
         * pending suggestion, carrying its claim with it. The owner watches a
         * thing they chose disappear; whoever claimed it is still on the hook
         * for a row nobody can see.
         *
         * Invisible until this endpoint had a UI: every test posts a group that
         * is not on the list, because that is what the feature is for. The
         * duplicate is the ordinary case the moment a visitor can search — the
         * obvious thing to suggest is the obvious thing to already own.
         *
         * Not treated as an error state worth hiding: the item is on the page
         * in front of them, so saying so reveals nothing.
         */
        if ($list->items()->where('group_id', $group->id)->exists()) {
            return back()->with('error', __('site.suggestions.already_on_list'));
        }

        return $this->pending($request, $saver->saveGroup($list, $group, $current, $validated['note'] ?? null));
    }

    /**
     * Explicitly pending.
     *
     * The column defaults to accepted, so this is the one place that has to say
     * otherwise — which is the right way round: forgetting it here makes a
     * suggestion appear immediately, while the old arrangement made forgetting
     * it anywhere else silently hide a real item.
     *
     * One method rather than one per shape, because both saver calls hand back
     * an accepted row and only this turns it into a message. A second copy is
     * how the manual path would eventually stop being pending.
     */
    private function pending(Request $request, WishlistItem $item): RedirectResponse
    {
        $item->forceFill([
            'suggested_by_user_id' => $request->user()?->id,
            'accepted_at' => null,
        ])->save();

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

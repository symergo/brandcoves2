<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ListKind;
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
 * ## Whether it waits depends on whose list it is
 *
 * On a `mine` list a contribution is a message *to somebody about their own
 * wish list*, so it waits for them: an unfiltered list would let anyone with
 * the link put anything in front of everyone else holding it.
 *
 * On a `for_someone` or `group` list the owner is a co-giver or an organiser
 * and everybody is researching a third person who never sees it. There an
 * addition goes **straight on**, because making each one wait turns a shared
 * workspace into an inbox that the person who asked for help has to empty.
 * {@see ListKind::acceptsDirectAdditions()}.
 *
 * A **hand-written** item waits on every kind, and that split is the one
 * judgement call here. A catalogue product is a `group_id` — structured, ours,
 * nothing to moderate. A typed title and price is free text from somebody
 * holding a link that can be forwarded anywhere, which is the moderation
 * surface `wishlists.md` declined to open, and the pending queue is the control
 * that already exists for it.
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

        /*
         * Contributing to yourself is just adding, and the list page already
         * does that without the round trip.
         *
         * Asks identity — `isOwnedBy()` — rather than `shouldHideClaimsFrom()`,
         * which it used to. The two agreed while every owner was a hidden
         * owner, and stopped agreeing when a gift list's owner began to see
         * claims: that owner would otherwise be able to suggest into their own
         * list and then approve it.
         */
        abort_if($list->isOwnedBy($owner), 403);

        /*
         * No kind check.
         *
         * This was `abort_unless($list->allowsClaiming())` — "only somebody's
         * own list; a `for_someone` list is private research, and suggesting
         * into it would tell a stranger it exists". The premise does not hold:
         * a stranger cannot reach this at all, because `findShared()` refuses a
         * private list, so anybody here was *sent the link on purpose*. Helping
         * fill a gift list is the reason they were sent it.
         *
         * The list being shared is therefore the whole gate, and it is enforced
         * by `findShared()` above rather than repeated here.
         */

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

            // Free text waits, on every kind of list. See the class docblock:
            // this is the one channel with unmoderated words in it.
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

        $item = $saver->saveGroup($list, $group, $current, $validated['note'] ?? null);

        /*
         * Straight on, or into the queue.
         *
         * `saveGroup()` hands back an accepted row either way, so this is the
         * only place the difference is made — and the direct branch is a
         * *return without acting*, which is the right way round: forgetting it
         * makes an addition wait, which is visible and recoverable. The
         * opposite mistake publishes something nobody approved.
         */
        if ($list->linkCanAdd()) {
            // Who added it, so a shared list is not a pile of anonymous finds
            // that two people quietly delete for each other.
            $item->forceFill(['suggested_by_user_id' => $request->user()?->id])->save();

            return back()->with('success', __('site.suggestions.added'));
        }

        return $this->pending($request, $item);
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

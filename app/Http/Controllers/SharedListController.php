<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ListVisibility;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Support\CurrentMarket;
use App\Support\Owner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The shared view of a gift list, and claiming.
 *
 * This is the half of the feature that has to be exactly right. The list owner
 * must never learn what has been claimed, and two people claiming at the same
 * moment must not both succeed — otherwise the recipient gets two of the same
 * thing and the surprise is spoiled either way.
 */
class SharedListController extends Controller
{
    public function show(Request $request, CurrentMarket $current, string $market, string $token): Response
    {
        $list = $this->findShared($token);
        $owner = Owner::fromRequest($request);

        // If the owner opens their own share link, suppress claim state rather
        // than showing it. Anonymous owners count: most lists are built before
        // signup.
        $isOwner = $list->shouldHideClaimsFrom($owner);

        // A list *about* someone is co-giver coordination, not a registry.
        $claimable = $list->allowsClaiming();

        $identity = $owner->claimIdentity();
        $hash = $identity === null ? null : WishlistItem::identityHash($identity);

        return Inertia::render('Lists/Shared', [
            'list' => [
                'title' => $list->title,
                'description' => $list->description,
                'kind' => $list->kind->value,
                'claimable' => $claimable,
                'recipient' => $list->recipient?->name,

                /*
                 * Whose list this is, for the copy that names a person.
                 *
                 * A `for_someone` list is about its recipient. A `mine` list is
                 * about its owner — and until this existed the payload carried
                 * no owner at all, so the sentence "…will not see who claimed
                 * what" fell back to the *list title* and a visitor to somebody's
                 * own wishlist was told that "Saved items" would not see who
                 * claimed what.
                 *
                 * Null for an anonymous owner, who has no name to give. The UI
                 * has copy for that case rather than inventing one.
                 */
                'for' => $for = $list->isForSomeoneElse()
                    ? $list->recipient?->name
                    : $list->owner?->displayName(),

                /*
                 * What a visitor should call this.
                 *
                 * "My wishlist" is the right name for the owner and a useless
                 * one for everybody else — a link that arrives in a group chat
                 * saying "My wishlist" belongs to nobody. A title the owner
                 * actually chose ("Wedding", "Books") is kept, because they
                 * meant something by it; only our own default name is replaced.
                 */
                'heading' => $for !== null && $list->title === __('site.lists.default_title')
                    ? __('site.lists.someones_wishlist', ['name' => $for])
                    : $list->title,
            ],
            'isOwner' => $isOwner,

            /*
             * "3 of 11 claimed" — for visitors only, and null for the owner.
             *
             * A count is claim state. Sending 0 to the owner would be just as
             * fatal as sending the truth, because the moment it stops being 0
             * they know. Absent, not zero.
             */
            'progress' => $isOwner || ! $claimable ? null : [
                'claimed' => $list->items()->whereNotNull('claimed_by_hash')->count(),
                'total' => $list->items()->count(),
            ],
            'items' => $list->items()->with('group')->get()->map(fn (WishlistItem $item) => [
                'id' => $item->id,
                'title' => $item->snapshot_title,
                'image' => $item->snapshot_image_url,
                'price' => $item->group?->min_price ?? $item->snapshot_price,
                'note' => $item->note,
                'url' => $item->group === null
                    ? null
                    : $current->url("p/{$item->group_id}/{$item->group->slug}"),
                'inStock' => $item->group?->in_stock ?? false,

                /*
                 * Claim state is computed per viewer and suppressed entirely
                 * for the owner.
                 *
                 * `claimedByMe` lets a visitor un-claim their own choice.
                 * `claimed` tells other visitors not to buy the same thing.
                 * The owner sees neither — not even the boolean — because a
                 * single leaked flag defeats the whole point of a gift list.
                 */
                'claimed' => $isOwner || ! $claimable ? null : $item->isClaimed(),
                'claimedByMe' => $isOwner || ! $claimable || $hash === null
                    ? false
                    : $item->claimed_by_hash === $hash,

                /*
                 * Only ever shown to the person who claimed it. "Bought" is a
                 * fact about their own errand — everyone else needs to know the
                 * item is spoken for, and nothing more.
                 */
                'sent' => ! $isOwner && $claimable && $hash !== null && $item->claimed_by_hash === $hash
                    ? $item->marked_sent_at !== null
                    : null,
            ]),
        ]);
    }

    public function claim(Request $request, CurrentMarket $current, string $market, string $token, string $item): RedirectResponse
    {
        $list = $this->findShared($token);
        $owner = Owner::fromRequest($request);
        $identity = $owner->claimIdentity();

        // Anonymous visitors can claim: requiring an account here would mean
        // most people simply do not, and the list stops working as a
        // coordination tool.
        abort_if($identity === null, 403);

        // Only a `mine` list is a registry. Hiding the button is not enough —
        // a hand-built POST would otherwise claim on someone's private research.
        abort_unless($list->allowsClaiming(), 403);

        // The owner claiming on their own list would tell them what is taken.
        abort_if($list->shouldHideClaimsFrom($owner), 403);

        $wishlistItem = $list->items()->whereKey($item)->first();
        if ($wishlistItem === null) {
            throw new NotFoundHttpException;
        }

        // Atomic: two people tapping "I'll get this" at the same moment is the
        // expected case, not an edge case.
        $claimed = $wishlistItem->claim(WishlistItem::identityHash($identity));

        return back()->with(
            $claimed ? 'success' : 'error',
            __($claimed ? 'site.lists.claimed' : 'site.lists.already_claimed'),
        );
    }

    public function unclaim(Request $request, CurrentMarket $current, string $market, string $token, string $item): RedirectResponse
    {
        $list = $this->findShared($token);
        $identity = Owner::fromRequest($request)->claimIdentity();
        abort_if($identity === null, 403);

        $wishlistItem = $list->items()->whereKey($item)->first();
        if ($wishlistItem === null) {
            throw new NotFoundHttpException;
        }

        // Only the person who claimed it, and only inside the undo window.
        $released = $wishlistItem->release(WishlistItem::identityHash($identity));

        return back()->with(
            $released ? 'success' : 'error',
            __($released ? 'site.lists.unclaimed' : 'site.lists.cannot_unclaim'),
        );
    }

    /**
     * "I've bought it."
     *
     * A claim stops two people buying the same thing; this says the buying
     * actually happened. The gap between them is the case that matters — an item
     * claimed weeks ago by somebody who then forgot, which reads as covered and
     * is not.
     */
    public function markSent(Request $request, CurrentMarket $current, string $market, string $token, string $item): RedirectResponse
    {
        $list = $this->findShared($token);
        $owner = Owner::fromRequest($request);
        $identity = $owner->claimIdentity();

        abort_if($identity === null, 403);
        abort_unless($list->allowsClaiming(), 403);
        abort_if($list->shouldHideClaimsFrom($owner), 403);

        $wishlistItem = $list->items()->whereKey($item)->first();

        if ($wishlistItem === null) {
            throw new NotFoundHttpException;
        }

        // Restricted to the claim holder inside the model, so a hand-built POST
        // cannot mark somebody else's errand done.
        $marked = $wishlistItem->markSent(WishlistItem::identityHash($identity));

        return back()->with(
            $marked ? 'success' : 'error',
            __($marked ? 'site.lists.marked_sent' : 'site.lists.cannot_mark_sent'),
        );
    }

    private function findShared(string $token): Wishlist
    {
        $list = Wishlist::query()
            ->with(['recipient', 'owner'])
            ->where('share_token', $token)
            // A private list is not reachable by token even if the token leaks:
            // turning sharing off has to actually turn it off.
            ->where('visibility', '!=', ListVisibility::Private->value)
            ->first();

        if ($list === null) {
            throw new NotFoundHttpException;
        }

        return $list;
    }
}

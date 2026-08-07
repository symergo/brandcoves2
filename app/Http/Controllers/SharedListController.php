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

        // If the owner opens their own share link, send them to the private
        // view rather than showing them claim state.
        $isOwner = $list->shouldHideClaimsFrom($owner->user);

        $identity = $owner->claimIdentity();
        $hash = $identity === null ? null : WishlistItem::identityHash($identity);

        return Inertia::render('Lists/Shared', [
            'list' => [
                'title' => $list->title,
                'description' => $list->description,
                'isGiftList' => $list->is_gift_list,
                'recipient' => $list->recipient?->name,
            ],
            'isOwner' => $isOwner,
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
                'claimed' => $isOwner ? null : $item->isClaimed(),
                'claimedByMe' => $isOwner || $hash === null
                    ? false
                    : $item->claimed_by_hash === $hash,
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

        // The owner claiming on their own list would tell them what is taken.
        abort_if($list->shouldHideClaimsFrom($owner->user), 403);

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

    private function findShared(string $token): Wishlist
    {
        $list = Wishlist::query()
            ->with('recipient')
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

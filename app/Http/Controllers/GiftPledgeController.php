<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\GiftPledge;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Support\CurrentMarket;
use App\Support\Owner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Group gift: several people, one present.
 *
 * Pledges, not payments. Four colleagues on one expensive thing is a
 * coordination problem — who is in, and for how much — and that is the part
 * worth solving. Moving the money is a regulated business, and the people
 * involved settle up between themselves regardless.
 *
 * The buyer is the ordinary claim on the item. Somebody claims it, everyone
 * else pledges against it, and the claimer collects in real life.
 *
 * ## The privacy rule is unchanged and load-bearing
 *
 * A pledge is claim state. Telling the list owner that four people have put
 * €25 against the bicycle tells them about the bicycle, so pledges never
 * appear in any payload the owner can see — the same rule, and the same
 * `shouldHideClaimsFrom()` check, as everything else on a shared list.
 */
class GiftPledgeController extends Controller
{
    public function store(Request $request, CurrentMarket $current, string $market, string $token, string $item): RedirectResponse
    {
        [$list, $wishlistItem, $owner] = $this->resolve($request, $token, $item);

        $validated = $request->validate([
            // Euros in, cents stored — invariant #7.
            'amount' => ['required', 'numeric', 'min:1', 'max:100000'],
            'display_name' => ['required', 'string', 'max:80'],
        ]);

        GiftPledge::updateOrCreate(
            [
                'item_id' => $wishlistItem->id,
                ...$owner->attributes('user_id', 'anon_id'),
            ],
            [
                'amount' => (int) round((float) $validated['amount'] * 100),
                'display_name' => $validated['display_name'],
            ],
        );

        unset($list);

        return back()->with('success', __('site.pledges.added'));
    }

    public function destroy(Request $request, CurrentMarket $current, string $market, string $token, string $item): RedirectResponse
    {
        [$list, $wishlistItem, $owner] = $this->resolve($request, $token, $item);

        $owner->scope(
            GiftPledge::query()->where('item_id', $wishlistItem->id),
            'user_id',
            'anon_id',
        )->delete();

        unset($list);

        return back()->with('success', __('site.pledges.removed'));
    }

    /**
     * @return array{0: Wishlist, 1: WishlistItem, 2: Owner}
     */
    private function resolve(Request $request, string $token, string $item): array
    {
        $list = Wishlist::query()
            ->where('share_token', $token)
            ->where('visibility', '!=', 'private')
            ->first();

        if ($list === null) {
            throw new NotFoundHttpException;
        }

        $owner = Owner::fromRequest($request);

        // Pledging is claim state, so the same two gates apply: only a list that
        // can be claimed from, and never the owner of it.
        abort_unless($list->allowsClaiming(), 403);
        abort_if($list->shouldHideClaimsFrom($owner), 403);
        abort_unless($owner->exists(), 403);

        $wishlistItem = $list->items()->whereKey($item)->first();

        if ($wishlistItem === null) {
            throw new NotFoundHttpException;
        }

        return [$list, $wishlistItem, $owner];
    }
}

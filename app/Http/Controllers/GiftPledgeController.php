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
 * ## The privacy rule, and the one list where it inverts
 *
 * On a wish list a pledge is claim state. Telling the owner that four people
 * have put €25 against the bicycle tells them about the bicycle, so pledges
 * never appear in any payload they can see — the same rule as everything else
 * on a shared list.
 *
 * On a **group** list the owner is the organiser and the recipient is a third
 * party who never sees the list, so there is no surprise to protect from them:
 * they see who put in what, because they front the money and collect
 * afterwards. Members see the total and their own share and never each other's
 * amounts — a visible ladder is social pressure on whoever put in least.
 *
 * Both halves live in `Wishlist::allowsContributionsFrom()` and
 * `ContributionView`, one each for the write and the read side.
 */
class GiftPledgeController extends Controller
{
    public function store(Request $request, CurrentMarket $current, string $market, string $token, ?string $item = null): RedirectResponse
    {
        [$list, $wishlistItem, $owner] = $this->resolve($request, $token, $item);

        $validated = $request->validate([
            // Euros in, cents stored — invariant #7.
            'amount' => ['required', 'numeric', 'min:1', 'max:100000'],
            'display_name' => ['required', 'string', 'max:80'],
        ]);

        GiftPledge::updateOrCreate(
            [
                'wishlist_id' => $list->id,
                // Null on a group list: the money is towards the present, and
                // the shortlist under it is candidates for what that will be.
                'item_id' => $wishlistItem?->id,
                ...$owner->attributes('user_id', 'anon_id'),
            ],
            [
                'amount' => (int) round((float) $validated['amount'] * 100),
                'display_name' => $validated['display_name'],
            ],
        );

        return back()->with('success', __('site.pledges.added'));
    }

    public function destroy(Request $request, CurrentMarket $current, string $market, string $token, ?string $item = null): RedirectResponse
    {
        [$list, $wishlistItem, $owner] = $this->resolve($request, $token, $item);

        $owner->scope(
            GiftPledge::query()
                ->where('wishlist_id', $list->id)
                // `whereNull` rather than `where(..., null)`, which never
                // matches: leaving the pot has to find the row with no item.
                ->when(
                    $wishlistItem === null,
                    fn ($q) => $q->whereNull('item_id'),
                    fn ($q) => $q->where('item_id', $wishlistItem->id),
                ),
            'user_id',
            'anon_id',
        )->delete();

        return back()->with('success', __('site.pledges.removed'));
    }

    /**
     * @return array{0: Wishlist, 1: ?WishlistItem, 2: Owner}
     */
    private function resolve(Request $request, string $token, ?string $item): array
    {
        $list = Wishlist::query()
            ->where('share_token', $token)
            ->where('visibility', '!=', 'private')
            ->first();

        if ($list === null) {
            throw new NotFoundHttpException;
        }

        $owner = Owner::fromRequest($request);

        /*
         * One question, asked once.
         *
         * This was two gates — `allowsClaiming()` and `shouldHideClaimsFrom()`
         * — and both were wrong for a group list. The first is `mine`-only, so
         * a list for a third person structurally could not carry contributions;
         * the second locked out the organiser, who is a participant rather than
         * the person being surprised. `Wishlist::allowsContributionsFrom()`
         * holds the whole rule now, including the inversion and the "somebody
         * has to own this row" check, so no surface can re-decide half of it.
         */
        abort_unless($list->allowsContributionsFrom($owner), 403);

        /*
         * Where the money attaches is decided by the kind, never by the caller.
         *
         * Two routes reach this — `/pledge` and `/pledge/{item}` — and either
         * one used on the wrong kind of list is refused rather than quietly
         * doing the other thing. A pot pledge on a wish list would be money
         * against nothing in particular; a per-item pledge on a group list is
         * the incoherence this whole change removes, and accepting it would
         * split one pot into several the page never adds up.
         */
        if ($list->kind->poolsOnTheList()) {
            abort_unless($item === null, 403);

            return [$list, null, $owner];
        }

        abort_if($item === null, 403);

        $wishlistItem = $list->items()->whereKey($item)->first();

        if ($wishlistItem === null) {
            throw new NotFoundHttpException;
        }

        return [$list, $wishlistItem, $owner];
    }
}

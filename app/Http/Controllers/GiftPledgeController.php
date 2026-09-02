<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\GiftPledge;
use App\Models\Wishlist;
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
    public function store(Request $request, CurrentMarket $current, string $market, string $token): RedirectResponse
    {
        [$list, $owner] = $this->resolve($request, $token);

        /*
         * When the organiser has set a standard share, the member does not get
         * to name a number — they are in for that, or they are not in. So the
         * amount is `sometimes` and is ignored below: validating it as required
         * would reject a form that correctly has no field, and trusting it
         * would let anybody post €1 into a €10-a-head pot.
         */
        $standard = $list->standardPledge();

        $validated = $request->validate([
            // Euros in, cents stored — invariant #7.
            'amount' => [$standard === null ? 'required' : 'sometimes', 'numeric', 'min:1', 'max:100000'],
            'display_name' => ['required', 'string', 'max:80'],
        ]);

        GiftPledge::updateOrCreate(
            [
                'wishlist_id' => $list->id,
                ...$owner->attributes('user_id', 'anon_id'),
            ],
            [
                'amount' => $standard ?? (int) round((float) $validated['amount'] * 100),
                'display_name' => $validated['display_name'],
            ],
        );

        return back()->with('success', __('site.pledges.added'));
    }

    public function destroy(Request $request, CurrentMarket $current, string $market, string $token): RedirectResponse
    {
        [$list, $owner] = $this->resolve($request, $token);

        $owner->scope(
            GiftPledge::query()->where('wishlist_id', $list->id),
            'user_id',
            'anon_id',
        )->delete();

        return back()->with('success', __('site.pledges.removed'));
    }

    /**
     * @return array{0: Wishlist, 1: Owner}
     */
    private function resolve(Request $request, string $token): array
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
         * holds the whole rule now, including the "somebody has to own this
         * row" check, so no surface can re-decide half of it.
         */
        abort_unless($list->allowsContributionsFrom($owner), 403);

        return [$list, $owner];
    }
}

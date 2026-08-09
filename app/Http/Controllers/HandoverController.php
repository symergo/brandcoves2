<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ListKind;
use App\Models\Wishlist;
use App\Models\WishlistCollaborator;
use App\Support\CurrentMarket;
use App\Support\ListAccess;
use App\Support\Owner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "I started this for you. Now it is yours."
 *
 * A list about somebody else was permanently the giver's, which is right while
 * it is research and wrong the moment the point becomes *helping them ask for
 * things*. A parent building a list for a child, a partner collecting ideas
 * before a birthday — the natural end of both is handing it over.
 *
 * ## What changes, and why each one has to
 *
 * The list becomes `mine` and its recipient is cleared: it is no longer *about*
 * a person, it belongs to one. That flip is what makes it claimable, which is
 * the entire value of the handover — their list can now be shared and bought
 * from.
 *
 * **Collaborators are removed.** They were co-givers coordinating in private
 * about this exact person, and leaving them attached both tells the new owner
 * who was plotting and leaves those people reading a list that is now somebody
 * else's private property.
 *
 * There are no claims to worry about: a `for_someone` list is not claimable in
 * the first place, so nothing has ever been bought from it.
 */
class HandoverController extends Controller
{
    public function store(Request $request, CurrentMarket $current, string $market, string $list): RedirectResponse
    {
        $owner = Owner::fromRequest($request);

        $wishlist = $owner->scope(Wishlist::query())->with('recipient')->find($list);

        if ($wishlist === null || ! ListAccess::isOwner($wishlist, $owner)) {
            throw new NotFoundHttpException;
        }

        abort_unless($wishlist->kind === ListKind::ForSomeone, 403, __('site.handover.only_gift_lists'));
        abort_if($wishlist->handed_over_at !== null, 403, __('site.handover.already'));

        $recipient = $wishlist->recipient;

        /*
         * There has to be somebody to hand it to.
         *
         * A stub recipient is a name the giver typed; handing a list to a name
         * gives it to nobody and loses it. They claim their `/for/{token}` link
         * first, which is what turns the name into an account.
         */
        abort_if($recipient?->user_id === null, 422, __('site.handover.not_linked'));

        DB::transaction(function () use ($wishlist, $recipient): void {
            WishlistCollaborator::query()->where('wishlist_id', $wishlist->id)->delete();

            $wishlist->update([
                'owner_user_id' => $recipient->user_id,
                'owner_anon_id' => null,
                'recipient_id' => null,
                'kind' => ListKind::Mine,
                // Never the new owner's default: it arrives beside a list they
                // may already have, and quietly moving where their saves land
                // is not a gift.
                'is_default' => false,
                'handed_over_at' => now(),
            ]);
        });

        return redirect()
            ->to($current->url('lists'))
            ->with('success', __('site.handover.done', ['name' => $recipient->name]));
    }
}

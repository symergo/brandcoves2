<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ClaimVisibility;
use App\Enums\ListKind;
use App\Models\User;
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
 * ## Claims survive, names do not
 *
 * This docblock used to say "there are no claims to worry about: a
 * `for_someone` list is not claimable in the first place". That stopped being
 * true when gift lists became claimable, and the sentence is the kind that goes
 * on being believed long after it is wrong.
 *
 * **The claim hash stays.** A sibling may already have bought the thing, and
 * releasing the claim sends a second person to the shops. The new owner never
 * learns of it: the list is now `mine`, so `shouldHideClaimsFrom()` hides it
 * from them absolutely, and their own page has never carried a claim field.
 *
 * **The name goes, and so does the setting.** A claimer typed their name for a
 * small audience of co-givers who were plotting a surprise. The list is now a
 * wish list its owner may share with anyone, which is a different audience
 * entirely, and consent to the first is not consent to the second. Resetting
 * `claim_visibility` matters for the same reason: `named` is meaningless on a
 * `mine` list and would be waiting to take effect if the list were ever handed
 * on again.
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

        $validated = $request->validate([
            'email' => ['nullable', 'email:rfc', 'max:254'],
        ]);

        /*
         * Who is receiving it.
         *
         * The typed address wins, falling back to the account already linked to
         * this recipient. Handing a list to a *name* would give it to nobody and
         * lose it, so there has to be a real account at the end of this.
         */
        $email = filled($validated['email'] ?? null)
            ? mb_strtolower(trim((string) $validated['email']))
            : $recipient?->person?->email;

        $newOwner = $email === null
            ? null
            : User::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();

        /*
         * Deliberately explicit, unlike the collaborator invite.
         *
         * That form must not reveal whether an address has an account, because
         * anybody could type addresses into it. This one is different: the owner
         * is giving away something of theirs and has to know whether it landed,
         * and silence would leave them believing a list had moved when it had
         * not.
         */
        abort_if($newOwner === null, 422, __('site.handover.no_account'));

        DB::transaction(function () use ($wishlist, $newOwner): void {
            WishlistCollaborator::query()->where('wishlist_id', $wishlist->id)->delete();

            // The claim itself is deliberately untouched — see the class
            // docblock. Only the name given to a co-giver audience goes.
            $wishlist->items()->whereNotNull('claimed_by_name')->update([
                'claimed_by_name' => null,
            ]);

            $wishlist->update([
                'owner_user_id' => $newOwner->id,
                'owner_anon_id' => null,
                'recipient_id' => null,
                'kind' => ListKind::Mine,
                'claim_visibility' => ClaimVisibility::Anonymous,
                // Back to "never asked": the new owner has expressed no
                // preference, and inheriting the giver's would decide for
                // them whether their own list surprises them.
                'owner_sees_claims' => null,
                // Never the new owner's default: it arrives beside a list they
                // may already have, and quietly moving where their saves land
                // is not a gift.
                'is_default' => false,
                'handed_over_at' => now(),
            ]);
        });

        return redirect()
            ->to($current->url('lists'))
            ->with('success', __('site.handover.done', [
                'name' => $recipient?->name ?? $newOwner->displayName(),
            ]));
    }
}

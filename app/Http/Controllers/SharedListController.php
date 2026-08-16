<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ListVisibility;
use App\Models\ProductGroup;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Search\SearchQuery;
use App\Services\Search\SearchService;
use App\Services\Wishlist\ContributionView;
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
    public function show(
        Request $request,
        CurrentMarket $current,
        string $market,
        string $token,
        SearchService $search,
        ContributionView $contributor,
    ): Response {
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

        /*
         * "I think you would like this."
         *
         * The same three conditions `SuggestionController::store()` enforces,
         * asked here so the control is absent when the POST would be refused.
         * Mirrored, never trusted: the endpoint re-checks all of it, because
         * hiding a button stops nobody hand-building the request.
         *
         * No account needed — an anonymous cookie identity is an owner. Somebody
         * followed a link once; requiring a signup before they can say "she'd
         * love this" is how the feature does not get used.
         */
        $canSuggest = ! $isOwner && $claimable && $owner->exists();

        $term = $canSuggest ? trim((string) $request->query('q', '')) : '';

        /*
         * Has this visitor claimed something on this list?
         *
         * The one question the delivery address is gated on, decided here and
         * nowhere else. It reads **their own** hash, so the answer tells them
         * nothing about anybody else — and it is short-circuited by `! $isOwner`
         * so it can never become a second route to "has anybody claimed", which
         * is `progress` and is already withheld from the owner.
         *
         * The dangerous variant of this query is `whereNotNull('claimed_by_hash')`.
         * Do not write it.
         */
        $hasClaimed = ! $isOwner
            && $claimable
            && $hash !== null
            && $list->items()->where('claimed_by_hash', $hash)->exists();

        $items = $list->items()->with(['group', 'pledges'])->get();

        /*
         * Money on the list.
         *
         * `$isOwner` is passed through rather than used to decide, and the
         * distinction matters: it is `shouldHideClaimsFrom()`, which is **true
         * for a group organiser too**. Reusing it here as "hide everything"
         * would lock the organiser out of the breakdown that is the entire
         * point of a group list. `ContributionView` holds the whole table.
         */
        $contributions = $contributor->forItems($list, $items, $owner, $isOwner);

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
            'canSuggest' => $canSuggest,
            'suggestTerm' => $term,

            /*
             * Whether this viewer may put money in, mirrored from the endpoint
             * exactly as `canSuggest` is. Mirrored, never trusted: the POST
             * asks the same question again, because hiding a control stops
             * nobody hand-building the request.
             */
            'canContribute' => $list->allowsContributionsFrom($owner),

            /*
             * The registry block: an occasion, a date, and where to send it.
             *
             * All three were stored and read back to the owner alone, so a
             * registry was a form you could fill in and nobody could ever use.
             * The copy has promised the gate below in two places since the
             * feature shipped, and so has the migration's own comment.
             *
             * **The occasion and date are not gated.** They are why the list
             * exists — "Wedding, 14 June" — and belong to everyone holding the
             * link. Only the address is, which is exactly what
             * `registry.address_hint` says.
             *
             * `delivery_address` is an encrypted cast, so reading it here is the
             * authorised disclosure. There are exactly two readers in the
             * codebase: the owner's own page behind `ListAccess::isOwner()`, and
             * this one behind `$hasClaimed`. There is no third.
             */
            'registry' => ! $list->isRegistry() ? null : [
                'occasion' => $list->event_type->label(),
                'date' => $list->event_date?->toDateString(),
                'address' => $hasClaimed ? $list->delivery_address : null,
                // Said out loud, so somebody who has claimed nothing knows the
                // address exists and how to see it, rather than assuming the
                // owner forgot to add one.
                'locked' => ! $hasClaimed && filled($list->delivery_address),
            ],

            /*
             * Null means "no search has been run", `[]` means "searched and
             * found nothing". They are different sentences on the page and
             * collapsing them into an empty array makes the first visit look
             * like a failed search.
             */
            'results' => $term === '' ? null : array_map(
                fn (ProductGroup $group) => [
                    'id' => $group->id,
                    'title' => $group->title,
                    'image' => $group->image_url,
                    // Cents on the wire, as everywhere: the client formats.
                    'price' => $group->min_price,
                ],
                /*
                 * Eight, not a page.
                 *
                 * This is a picker inside somebody else's list, not the search
                 * page — a paginated grid here would bury the list it is meant
                 * to be adding to. `discountedOnly` is passed explicitly
                 * because the value object defaults it to *true*, which would
                 * quietly restrict a suggestion to whatever happens to be on
                 * offer today.
                 */
                array_slice($search->search(new SearchQuery(
                    market: $current->get(),
                    term: $term,
                    discountedOnly: false,
                    // Not public demand. `search_log` feeds the related-search
                    // chips and the guide topic queue, and a term typed inside
                    // one named person's gift list does not belong there.
                    logged: false,
                ))->groups->items(), 0, 8),
            ),

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
            'items' => $items->map(fn (WishlistItem $item) => [
                'id' => $item->id,
                'title' => $item->snapshot_title,
                'image' => $item->snapshot_image_url,
                'price' => $item->group?->min_price ?? $item->snapshot_price,
                'note' => $item->note,
                'url' => $item->group === null
                    ? null
                    : $current->url("p/{$item->group_id}/{$item->group->slug}"),

                // A hand-written item points somewhere off the site, so it is
                // not the same link as the one above and must not be rendered
                // as one. `https:` only, decided by the model.
                'externalUrl' => $item->externalUrl(),

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

                /*
                 * Absent, not null, wherever there is nothing to say — the same
                 * discipline as `progress` above. `ContributionView` omits the
                 * item entirely rather than returning an empty shape, so the
                 * owner of a wish list receives no key here at all.
                 */
                ...isset($contributions[$item->id])
                    ? ['contributions' => $contributions[$item->id]]
                    : [],
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

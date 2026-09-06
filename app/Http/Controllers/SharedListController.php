<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ListVisibility;
use App\Models\ListOpen;
use App\Models\ProductGroup;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Notifications\ListActivity;
use App\Services\Search\SearchQuery;
use App\Services\Search\SearchService;
use App\Services\Seo\PageMeta;
use App\Services\Social\Friends;
use App\Services\Social\ShareReferral;
use App\Services\Wishlist\Board;
use App\Services\Wishlist\ClaimView;
use App\Services\Wishlist\ContributionView;
use App\Services\Wishlist\DefaultTitle;
use App\Support\CurrentMarket;
use App\Support\ListAccess;
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
        ClaimView $claims,
        Friends $friends,
        ShareReferral $referral,
    ): Response {
        $list = $this->findShared($token);
        $owner = Owner::fromRequest($request);

        /*
         * Two questions, and they used to be one variable.
         *
         * `$isOwner` was `shouldHideClaimsFrom()`, which answered both while
         * the two happened to agree — every list whose owner must not see
         * claims was also every list the owner owned. A gift list about
         * somebody else breaks that: its owner is a co-giver rather than the
         * person being surprised, so they *do* see claims, and every one of the
         * eight uses below would have silently changed meaning.
         *
         * `list-taxonomy.md` names this exact hazard for `ContributionView`:
         * "$isOwner must not be reused as 'hide everything'".
         *
         * - `$isOwner`    — identity. Whose list is this?
         * - `$hideClaims` — policy. May this person see what is taken?
         */
        $isOwner = $list->isOwnedBy($owner);
        $hideClaims = $list->shouldHideClaimsFrom($owner);

        /*
         * Remember that this person has the list, so they can find it again.
         *
         * Not for the owner, whose own lists are already theirs — a row there
         * would put their list in their own Shared Lists. Written on the read
         * because there is no other moment: somebody sent a link, and following
         * it is the whole of the interaction until they claim something, which
         * most readers never do.
         */
        if (! $isOwner) {
            ListOpen::record($list, $owner);

            /*
             * And remember who sent it, so the two people know each other after.
             *
             * Sharing is a link, which made it frictionless and left both ends
             * anonymous to each other: you claim something off a friend's
             * registry and neither of you ends up with any record that the
             * other exists, so the next occasion starts with a hunt through a
             * chat history for a URL.
             *
             * Two paths, because a visitor is in one of two states and only one
             * of them can be connected to anybody right now:
             *
             * - Signed in already: connect them here, on the read. The link has
             *   been followed and both ends are accounts; there is nothing left
             *   to wait for.
             * - Not signed in: hold the referral in the session, and
             *   `LinkSharerAsFriend` applies it if and when an account appears.
             *   Claiming now asks for one, so this is the ordinary journey.
             *
             * Nothing is recorded against an anonymous visitor who never signs
             * in. A friendship is between two accounts, and half of one is a
             * cookie that will be cleared.
             *
             * Only for a list with a real account behind it: an anonymous
             * owner is nobody to be connected to.
             */
            if ($list->owner !== null) {
                if ($owner->user !== null) {
                    $friends->link($owner->user, $list->owner);
                } else {
                    $referral->remember($list->owner->id);
                }
            }
        }

        /*
         * Claiming needs a kind that allows it AND somebody to coordinate with.
         * The second half is free here — `findShared()` excludes private lists,
         * so anything reachable through this route is shared by definition —
         * but it is asked through the model so this page and the owner's page
         * cannot disagree about it.
         */
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
        $canSuggest = ! $isOwner && $owner->exists();

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

        /*
         * On a group list with voting on, the items are candidates and the tally
         * is their order: most-backed first, so a shortlist that has been voted
         * on reads as one rather than as an unsorted pile. `latest()` is the
         * tiebreak only, which keeps the order stable while nobody has voted.
         *
         * **`votingEnabled()`, not `kind->allowsVoting()`.** The kind says a
         * group list *can* vote; the setting says whether this one does. Asked
         * of the kind, a list whose organiser had switched voting off went on
         * reordering itself by a tally nobody could add to — which is precisely
         * what the setting's own hint promises to stop.
         */
        $items = $list->items()
            ->with(['group', 'votes'])
            ->when(
                $list->votingEnabled(),
                fn ($q) => $q->withCount('votes')->orderByDesc('votes_count')->latest(),
                // Newest first everywhere else, which is the order the owner's
                // own page shows. Unordered meant oldest first, so somebody who
                // opened a shared link twice a week met the same nine things at
                // the top and had to scroll for what had changed.
                fn ($q) => $q->latest(),
            )
            ->get();

        $list->load('pledges');

        /*
         * Who has claimed what, decided in one place.
         *
         * This used to be three ternaries per item spelling out the same rule
         * three times, which was survivable while the rule was "the owner sees
         * nothing" and stopped being so the moment it inverted by kind and then
         * by a per-list setting. `ClaimView` holds the whole table; absent
         * rather than null wherever there is nothing to say.
         */
        $claimState = $claims->forItems($list, $items, $owner, $hideClaims);

        /*
         * Who may vote, mirrored from the endpoint exactly as `canClaim` is.
         * Mirrored, never trusted: the POST asks the same question again.
         */
        $canVote = $list->allowsVotingFrom($owner);

        /*
         * Never indexed. The share link is the access, and a crawler that finds
         * one — from a forum post, a chat preview, anywhere — would list a
         * family's gift list under the recipient's name. Nothing here set
         * `PageMeta`, so the shell defaulted this page to `index, follow`.
         */
        app(PageMeta::class)->set(
            title: $list->displayTitle(),
            robots: 'noindex, nofollow',
        );

        return Inertia::render('Lists/Shared', [
            'list' => [
                'title' => $list->displayTitle(),
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
                 *
                 * Recognising it is `DefaultTitle`'s job, not a string
                 * comparison against the active locale: the stored title is
                 * frozen in the language of the market the list was created on,
                 * so a list started on `/en` and shared to a Dutch reader failed
                 * this test and went out titled "My wishlist" — the exact
                 * anonymous link this branch exists to prevent.
                 */
                'heading' => $for !== null && DefaultTitle::isOurs($list->title)
                    ? __('site.lists.someones_wishlist', ['name' => $for])
                    : $list->displayTitle(),

                /*
                 * Who sent you this, at the top of the page.
                 *
                 * The owner, always — which is *not* `for` above. On a list
                 * about somebody else `for` is the recipient, so a page whose
                 * only name was that one told a reader who the presents are for
                 * and nothing about who is asking them to buy one. This page is
                 * the one screen opened cold, from a message, by somebody with
                 * no context; "who is this from" is the first thing they want
                 * and the page never answered it.
                 *
                 * Null for an anonymous owner, who has no name to give. The UI
                 * omits the line rather than inventing one.
                 */
                'sharedBy' => $list->owner?->displayName(),
            ],
            'isOwner' => $isOwner,

            /*
             * May this viewer claim, mirrored from the endpoint.
             *
             * The three conditions `claim()` enforces, asked here so the button
             * is absent wherever the POST would be refused. Mirrored, never
             * trusted: the endpoint asks again, because hiding a control stops
             * nobody hand-building the request.
             *
             * Note this is NOT `! $isOwner`. The owner of a gift list about
             * somebody else is a co-giver like any other and may well be the
             * one buying the scarf; it is only the owner of a *wish* list who
             * must be kept out, and `$hideClaims` is what says so.
             */
            'canClaim' => $claimable && ! $hideClaims && $owner->isSignedIn(),

            /*
             * The same button, for somebody who has not signed in yet.
             *
             * Two props rather than one because they draw two different
             * controls: `canClaim` posts the claim, this one stashes the intent
             * and opens the sign-in dialog. Collapsing them into
             * `canClaim: true` plus a client-side check of `auth.user` would
             * put the account rule in two places, and the page's copy is
             * already the half that drifts.
             *
             * Note it is not simply `! canClaim`: on a group list, or where
             * claims are withheld, there is nothing to sign in *for*, and
             * offering an account to somebody who still could not claim is a
             * bounce dressed as an invitation.
             */
            'claimNeedsAccount' => $claimable && ! $hideClaims && ! $owner->isSignedIn(),

            /*
             * Whether claims are being withheld from this viewer, so the page
             * can say so rather than looking broken.
             *
             * `isOwner` used to stand in for this, and on a gift list it now
             * says the opposite of what the banner means: its owner is looking
             * at their own list *and* seeing claim state, which is the whole
             * point of the inversion.
             */
            'hideClaims' => $hideClaims,

            /*
             * What the list has promised its claimers about their name, so the
             * claim control can say it *before* the press rather than after.
             * A name shown to other people is a consent decision, and consent
             * given in a panel somebody else opened is not consent.
             */
            'claimNames' => $claimable && $list->claim_visibility->namesClaimers(),
            /*
             * Lists this viewer may copy an item into.
             *
             * The same payload the owner's page sends, so both pages can draw
             * the same control. It used to be `SaveToList` here and `CopyToList`
             * there — two icons, two menus and two verbs for one errand ("put
             * this on one of my lists"), and the difference was an accident of
             * which page was written first.
             *
             * It also closes a gap. `SaveToList` works from a `group_id`, so a
             * **hand-written** item on somebody's shared list had no control at
             * all — while `ItemTransferController::fromShared` had been able to
             * copy one the whole time. A note somebody typed is often the most
             * copyable thing on a list.
             *
             * Empty for a visitor with no lists, and for an anonymous one:
             * `ListAccess::scope()` gives an anonymous owner plain ownership,
             * and they own nothing. The page falls back to `SaveToList` there,
             * which is the only control that can make somebody's first list.
             */
            'copyTargets' => ListAccess::scope(Wishlist::query(), $owner)
                ->whereKeyNot($list->id)
                ->orderBy('title')
                ->get(['id', 'title', 'owner_user_id', 'owner_anon_id'])
                ->filter(fn (Wishlist $other): bool => ListAccess::canEdit($other, $owner))
                ->map(fn (Wishlist $other): array => [
                    'id' => $other->id,
                    'title' => $other->displayTitle(),
                ])
                ->values(),

            'canSuggest' => $canSuggest,

            /*
             * Whether what they add lands on the list or in the owner's queue.
             *
             * The page needs it for the verb — "Add to this list" is a
             * different promise from "Suggest something", and getting it the
             * wrong way round either surprises the owner or makes a helper
             * think nothing happened.
             */
            'addsDirectly' => $list->linkCanAdd(),
            'suggestTerm' => $term,

            /*
             * Whether this viewer may put money in, mirrored from the endpoint
             * exactly as `canSuggest` is. Mirrored, never trusted: the POST
             * asks the same question again, because hiding a control stops
             * nobody hand-building the request.
             */
            'canContribute' => $list->allowsContributionsFrom($owner),
            'canVote' => $canVote,

            /*
             * The pot, on a group list: one payload for the whole present.
             *
             * Null on every other kind: only a group list has a pot. Chipping
             * in is a fact about the present, so it appears once, in the
             * header, and never under a card.
             */
            'pot' => $contributor->forList($list, $owner, $isOwner),

            /*
             * The discussion beside the list.
             *
             * Null for anybody who may not see one, so the page draws no rail
             * rather than an empty one — and on a wish list whose owner has not
             * asked to see claims, that is the owner. A board is claim state in
             * prose; see App\Services\Wishlist\Board.
             */
            'board' => app(Board::class)->forList($list, $owner),

            /*
             * Why this list exists: an occasion, a date, and — on a registry —
             * where to send it.
             *
             * All three were stored and read back to the owner alone, so a
             * registry was a form you could fill in and nobody could ever use.
             * The copy has promised the gate below in two places since the
             * feature shipped, and so has the migration's own comment.
             *
             * **The occasion and date are not gated, and no longer registry-
             * only.** They are why the list exists — "Wedding, 14 June", "Dad's
             * birthday, 14 June" — and belong to everyone holding the link,
             * whatever kind of list it is. `hasOccasion()` is the question now;
             * `isRegistry()` narrowed to mean what it says.
             *
             * **The address stays registry-only, and that is the reason the two
             * questions were split.** It is the owner's home, and it is only
             * ever appropriate on a list belonging to the person receiving the
             * parcel. A gift list about somebody else may carry an occasion and
             * must never carry an address — asking one question for both is how
             * that gate would quietly widen.
             *
             * `delivery_address` is an encrypted cast, so reading it here is the
             * authorised disclosure. There are exactly two readers in the
             * codebase: the owner's own page behind `ListAccess::isOwner()`, and
             * this one behind `$hasClaimed`. There is no third.
             */
            'occasion' => ! $list->hasOccasion() ? null : [
                'name' => $list->event_type->label(),
                'date' => $list->event_date?->toDateString(),
                'address' => $list->isRegistry() && $hasClaimed
                    ? $list->delivery_address
                    : null,
                // Said out loud, so somebody who has claimed nothing knows the
                // address exists and how to see it, rather than assuming the
                // owner forgot to add one.
                'locked' => $list->isRegistry()
                    && ! $hasClaimed
                    && filled($list->delivery_address),
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
             * "3 of 11 claimed" — null for anybody who may not see claims.
             *
             * A count is claim state. Sending 0 to the owner of a wish list
             * would be just as fatal as sending the truth, because the moment
             * it stops being 0 they know. Null, never zero.
             *
             * Gated on `$hideClaims` rather than `$isOwner`: the organiser of a
             * gift list about somebody else is exactly the person who needs to
             * know how much is still uncovered.
             */
            'progress' => $claims->progress($list, $hideClaims),
            'items' => $items->map(fn (WishlistItem $item) => [
                'id' => $item->id,
                'title' => $item->snapshot_title,
                'image' => $item->snapshot_image_url,
                'price' => $item->group?->min_price ?? $item->snapshot_price,
                'note' => $item->note,
                'url' => $item->productPath(),

                /*
                 * So a visitor can keep it on a list of their own.
                 *
                 * Discloses nothing new: `url` immediately above is
                 * `p/{group_id}/{slug}`, so the id has always been in this
                 * payload — it was simply not in a form the save control could
                 * read. Saving reads the *viewer's* lists and writes to the
                 * viewer's list; the owner's list is not touched and learns
                 * nothing, so invariant #4 is untouched.
                 */
                'groupId' => $item->group_id,

                // A hand-written item points somewhere off the site, so it is
                // not the same link as the one above and must not be rendered
                // as one. `https:` only, decided by the model.
                'externalUrl' => $item->externalUrl(),

                'inStock' => $item->group?->in_stock ?? false,

                /*
                 * Claim state, from the one place that decides it.
                 *
                 * Absent — not null — wherever this viewer may know nothing:
                 * the owner of a wish list receives no `claimed` key at all,
                 * and a `claimed: false` on every item is a channel that goes
                 * live the moment one of them flips. Same discipline as
                 * `votes` below.
                 *
                 * The page reads `claimed === undefined` to mean "no claiming
                 * here", so this must stay a spread rather than becoming three
                 * nullable fields again.
                 */
                ...$claimState[$item->id] ?? [],

                /*
                 * The tally, and whether this viewer is in it.
                 *
                 * Absent on every kind that does not vote — the same
                 * absent-not-null discipline as `claimed`,
                 * so the page reads the key's presence as "this is a candidate"
                 * rather than carrying a `votes: 0` that means two things.
                 *
                 * Absent for two reasons now: this is not a group list, or its
                 * organiser has switched voting off. The page does not care
                 * which — in both cases there is nothing to vote on, and one
                 * test at the top of the item is better than two.
                 *
                 * A count, never a list of names: "four people want this" is
                 * what decides something, and "Bob wanted this" is a
                 * disagreement inside a group buying somebody a present.
                 */
                ...$list->votingEnabled() ? [
                    'votes' => $item->votes->count(),
                    'votedByMe' => $this->hasVoted($item, $owner),
                ] : [],
            ]),
        ]);
    }

    public function claim(Request $request, CurrentMarket $current, string $market, string $token, string $item): RedirectResponse
    {
        $list = $this->findShared($token);
        $owner = Owner::fromRequest($request);

        /*
         * Claiming needs an account, and this is the one guard that says so.
         *
         * It did not, for most of this feature's life, and the argument was a
         * good one: somebody followed a link once, and making them register to
         * say "I'll get this" is how a gift list stops working as a
         * coordination tool. That was right about the press and wrong about
         * everything after it. A claim hangs off a hash of the claimer's
         * identity, and an anonymous identity is a *cookie* — so the person who
         * claimed the scarf could not open the link on their phone and see that
         * they had, could not release it from there, and lost the claim outright
         * by clearing the browser. The item stayed spoken for, by nobody
         * reachable, and nobody could hand it back.
         *
         * Re-parenting those hashes at sign-in is not the way out; see
         * App\Services\Auth\IdentityMerger for why. An account is the only
         * identity that survives a second device.
         *
         * The press itself is still not lost: the page stashes the intent
         * through `ClaimIntentController` and `ReplayPendingClaim` applies it
         * the moment the account exists, so the sign-in is a detour rather than
         * a dead end. See App\Services\Wishlist\PendingClaim.
         */
        abort_unless($owner->isSignedIn(), 403);

        $identity = $owner->claimIdentity();
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

        /*
         * The name, only when the list asks for one.
         *
         * Decided here rather than in the model: the setting belongs to the
         * list, and an item that went looking for it would be a second place to
         * get the consent question wrong. A name posted to an anonymous list is
         * discarded rather than refused — the client should not have sent it,
         * and arguing with somebody about a field they cannot see is worse than
         * ignoring it.
         */
        $name = $list->claim_visibility->namesClaimers()
            ? trim((string) $request->string('display_name')) ?: null
            : null;

        // Atomic: two people tapping "I'll get this" at the same moment is the
        // expected case, not an edge case.
        $claimed = $wishlistItem->claim(WishlistItem::identityHash($identity), $name);

        /*
         * Nothing is announced when it works.
         *
         * A successful claim used to flash `lists.claimed` — a banner at the
         * top of `<main>`, saying "you are getting this" about an item that is
         * usually well down a scrolled list and that now says so itself, in
         * place, in the strip the press just changed. The alert was a second
         * copy of the answer, further from the question, and on a long list it
         * scrolled the page under the finger that tapped.
         *
         * The failure still speaks, because that one is not visible anywhere
         * else: the row simply shows as taken, and without a word the tap looks
         * like a control that does not work.
         */
        if ($claimed) {
            /*
             * And the organiser hears, on the kinds where they may.
             *
             * `ListActivity::claimed()` asks `shouldHideClaimsFrom()` — the same
             * question this method asked two guards ago — so a wish list's owner
             * is never written to. That gate lives there rather than here on
             * purpose: an inbox row is the one channel that goes and finds
             * somebody, and the rule it must obey should sit beside every other
             * notification, not in the controller that happens to emit one.
             */
            app(ListActivity::class)->claimed($list, $owner, $wishlistItem->snapshot_title);
        }

        return $claimed
            ? back()
            : back()->with('error', __('site.lists.already_claimed'));
    }

    /**
     * Hand it back.
     *
     * Note this asks for an *identity*, not an account, where `claim()` now
     * insists on one. Claims made before that rule existed are hashed from a
     * cookie, and the browser holding that cookie is the only thing on earth
     * that can release them — locking it out would strand every one of them as
     * spoken for by nobody. New claims all belong to accounts, so this
     * gradually stops mattering; until then, taking something back is the
     * direction to be permissive in.
     */
    public function unclaim(Request $request, CurrentMarket $current, string $market, string $token, string $item): RedirectResponse
    {
        $list = $this->findShared($token);
        $identity = Owner::fromRequest($request)->claimIdentity();
        abort_if($identity === null, 403);

        $wishlistItem = $list->items()->whereKey($item)->first();
        if ($wishlistItem === null) {
            throw new NotFoundHttpException;
        }

        // Only the person who claimed it.
        $released = $wishlistItem->release(WishlistItem::identityHash($identity));

        /*
         * Nothing is announced when it works — the same rule `claim()` follows,
         * a few lines up, and for the same reason.
         *
         * The row you just pressed goes back to offering "I'll get this", in
         * place, under the finger that pressed it. A banner at the top of the
         * document saying so is a second copy of that answer, further from the
         * question, and on a long list it scrolls the page away from the item.
         *
         * The failure still speaks, because that one is visible nowhere else:
         * the row simply stays claimed, and without a word the tap looks like a
         * control that does not work.
         */
        return $released
            ? back()
            : back()->with('error', __('site.lists.cannot_unclaim'));
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

        /*
         * Silent when it works, like `claim()` and `unclaim()` above.
         *
         * The row turns into the sent strip in place. A banner at the top of
         * the document saying the same thing is a second copy of an answer the
         * page has already given, further from the question.
         *
         * The failure speaks, because a row that simply does not change is
         * indistinguishable from a control that does not work.
         */
        return $marked
            ? back()
            : back()->with('error', __('site.lists.cannot_mark_sent'));
    }

    /**
     * Has this viewer already backed this candidate?
     *
     * Read off the loaded relation rather than as a query per item — the votes
     * are eager-loaded for the tally anyway, and a shortlist of five would
     * otherwise cost five round trips to answer a question already in memory.
     */
    private function hasVoted(WishlistItem $item, Owner $viewer): bool
    {
        return $item->votes->contains(
            fn ($vote) => $viewer->user !== null
                ? $vote->user_id === $viewer->user->id
                : $viewer->anonymous !== null && $vote->anon_id === $viewer->anonymous->getKey(),
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

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EventType;
use App\Enums\ListKind;
use App\Enums\ListVisibility;
use App\Models\ListQuiz;
use App\Models\Recipient;
use App\Models\SecretSantaMember;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Gift\GiftTarget;
use App\Services\Wishlist\AddingMode;
use App\Services\Wishlist\ContributionView;
use App\Services\Wishlist\DefaultList;
use App\Services\Wishlist\ListMaker;
use App\Support\CurrentMarket;
use App\Support\ListAccess;
use App\Support\Owner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class WishlistController extends Controller
{
    public function index(Request $request, CurrentMarket $current): Response
    {
        $owner = Owner::fromRequest($request);

        // Everybody has one, and it is created on the first visit rather than
        // on the first save — an empty lists page with nothing on it does not
        // tell you what the page is for.
        if ($owner->isSignedIn()) {
            app(DefaultList::class)->for($owner, $current);
        }

        /*
         * One page, three views: `?view=mine|shared|group`, defaulting to mine.
         *
         * They are **views, not filters**, and the difference decides the query
         * rather than decorating it. Each answers a different question about
         * whose list it is — what am I keeping, what has somebody shown me, what
         * are we choosing together — so they select different rows through
         * different scopes rather than narrowing one pile.
         *
         * This replaces an earlier `?shared=1` that meant `visibility !=
         * private`, i.e. *"a list I own that I have shared outward"*. That is a
         * property of my own list, not a separate collection, and it made the
         * Shared view a second copy of My Lists. Nothing ever linked to it,
         * which is the only reason replacing it is free.
         *
         * An unrecognised value falls back to `mine` rather than showing
         * everything: the views have different privacy characteristics, and the
         * safe default is the one containing only rows this person owns.
         *
         * See docs/features/list-taxonomy.md.
         */
        $view = match ($request->query('view')) {
            'shared' => 'shared',
            'group' => 'group',
            default => 'mine',
        };

        $query = match ($view) {
            /*
             * Somebody else's list, shown to me. `ListAccess::scope()` already
             * unions owned and collaborated rows and was previously only ever
             * used to look up ONE list by id — so a list shared with you could
             * be opened from its URL and found nowhere. Excluding my own rows
             * is what turns that union into "theirs, not mine".
             *
             * Anonymous visitors get nothing here rather than an error:
             * collaboration is signed-in only by design, because an invitation
             * is delivered to an address and a cookie has nowhere to receive it.
             */
            'shared' => ListAccess::scope(Wishlist::query(), $owner)
                ->whereNot(fn ($q) => $owner->scope($q)),

            // Chosen at creation, never derived — a list must not change section
            // because somebody was invited to it.
            'group' => $owner->scope(Wishlist::query())->where('kind', ListKind::Group->value),

            default => $owner->scope(Wishlist::query())
                ->whereIn('kind', [ListKind::Mine->value, ListKind::ForSomeone->value]),
        };

        $lists = $query
            ->with([
                'recipient',
                /*
                 * Enough of each list to recognise it at a glance.
                 *
                 * The index rendered a title and a count, so five lists looked
                 * like five identical cards and you opened them one by one to
                 * find the right one. Four covers is what turns that into
                 * recognition.
                 *
                 * Only items that carry a stored image, which quietly excludes
                 * Amazon — nothing about it may be mirrored (invariant #6), and
                 * a cover strip is not worth a live fetch per list.
                 */
                'items' => fn ($q) => $q->whereNotNull('snapshot_image_url')
                    ->latest('created_at')
                    ->limit(4),
            ])
            ->withCount('items')
            /*
             * Only on My Lists. On the Shared and Group views these are other
             * people's lists, and a pending suggestion is a message addressed
             * to their owner — the count is theirs to see, not mine.
             */
            ->when($view === 'mine', fn ($q) => $q->withCount('suggestions'))
            ->latest('updated_at')
            ->get()
            ->map(fn (Wishlist $list) => [
                ...$this->summarise($list, $current),
                'covers' => $list->items->pluck('snapshot_image_url')->all(),
            ]);

        return Inertia::render('Lists/Index', [
            'lists' => $lists,
            /*
             * Which view this is, so the page can say so.
             *
             * Sent rather than re-derived from the URL in React: the page has
             * to name what an empty result means, and "you have no shared
             * lists" and "you have no lists" are different sentences. A page
             * that shows one empty state for three questions tells the visitor
             * nothing about which one they asked.
             */
            'view' => $view,
            'recipients' => $owner->scope(Recipient::query())
                ->orderBy('name')
                ->get(['id', 'name', 'relationship', 'occasion']),
            'isSignedIn' => $owner->isSignedIn(),
        ]);
    }

    public function store(Request $request, CurrentMarket $current, ListMaker $maker): RedirectResponse
    {
        $owner = Owner::fromRequest($request);
        abort_unless($owner->exists(), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'recipient_id' => ['nullable', 'uuid'],

            /*
             * Naming a new person here, as the save picker has always allowed.
             *
             * Without it this form could only ever make a list for yourself:
             * the recipient dropdown is drawn from people you already have, and
             * the only place to mint one was the picker on a product card. So
             * "make a list for my sister" was reachable from a search result and
             * not from the page called My lists.
             */
            'new_recipient' => ['nullable', 'string', 'max:80'],

            /*
             * Several of us, one present. A boolean rather than a `kind`,
             * because the recipient already decides mine-vs-else and a
             * client-supplied kind could disagree with it. See `ListMaker`.
             */
            'together' => ['boolean'],
        ]);

        // The recipient decides the kind and `together` adds one bit; both
        // creation paths go through the one service so they cannot drift.
        $list = $maker->make(
            owner: $owner,
            current: $current,
            title: $validated['title'],
            recipientId: $validated['recipient_id'] ?? null,
            newRecipient: $validated['new_recipient'] ?? null,
            together: (bool) ($validated['together'] ?? false),
        );

        return redirect()->to($current->url("lists/{$list->id}"));
    }

    public function show(
        Request $request,
        CurrentMarket $current,
        string $market,
        string $list,
        ContributionView $contributor,
    ): Response {
        $owner = Owner::fromRequest($request);

        // Collaborators see it too — a co-giver invited to help choose has to
        // be able to open the thing they were invited to.
        $wishlist = ListAccess::scope(Wishlist::query(), $owner)
            ->with(['recipient', 'items.group', 'items.pledges', 'collaborators.user'])
            ->find($list);

        if ($wishlist === null) {
            throw new NotFoundHttpException;
        }

        $target = $wishlist->recipient === null
            ? null
            : GiftTarget::fromRecipient($wishlist->recipient, $current->get());

        /*
         * Money, on the one kind of list whose owner may see it.
         *
         * `ContributionView` returns an empty array for every other case, so
         * the `...` spread below adds no key at all — and that absence is
         * load-bearing. A `contributions: null` on every item of a wish list is
         * a channel that goes live the first time somebody tidies the null
         * away, and they would tidy it away without knowing what it was for.
         *
         * Both arguments matter: a *collaborator* on a group list reaches this
         * page through `ListAccess::scope()` above, and they are a member of the
         * pool rather than the organiser collecting it.
         */
        $contributions = $contributor->forItems(
            $wishlist,
            $wishlist->items,
            $owner,
            ListAccess::isOwner($wishlist, $owner),
        );

        return Inertia::render('Lists/Show', [
            'list' => $this->summarise($wishlist, $current),

            'access' => [
                'isOwner' => ListAccess::isOwner($wishlist, $owner),
                'canEdit' => ListAccess::canEdit($wishlist, $owner),
            ],

            // Only the owner manages the roster, so only the owner is shown it.
            'collaborators' => ListAccess::isOwner($wishlist, $owner)
                ? $wishlist->collaborators->map(fn ($c) => [
                    'id' => $c->id,
                    // The owner typed this address to invite them, so showing
                    // it back is not a disclosure — and it is how they
                    // recognise who they invited.
                    'name' => $c->user?->name ?? $c->user?->email,
                    'role' => $c->role->value,
                ])->all()
                : [],

            /*
             * Who this list is about, and whether they can speak for themselves
             * yet. A `GiftTarget` rather than the recipient, because the same
             * page has to render for a Secret Santa assignment — where no
             * recipient row exists and the pairing lives in one encrypted column.
             */
            'target' => $target === null ? null : [
                'name' => $target->name,
                'isLinked' => $target->isLinked(),
                'askUrl' => $wishlist->recipient === null
                    ? null
                    : url($current->url("for/{$wishlist->recipient->share_token}")),
            ],

            /*
             * Lane one: what they actually asked for.
             *
             * Claim state here is computed exactly as it is on the shared list —
             * these are *their* items, and I am a visitor to them. The one thing
             * that must never happen is a second claim mechanism growing here.
             */
            'asked' => $target === null ? [] : $this->asked($target, $owner, $current),

            /*
             * Suggestions waiting on a decision.
             *
             * Visible to the owner, unusually for this feature — a suggestion is
             * a message addressed to them. It is not on the list until they
             * accept it, which is why `Wishlist::items()` filters them out.
             */
            'suggestions' => ListAccess::isOwner($wishlist, $owner)
                ? $wishlist->suggestions()->with(['group', 'suggestedBy'])->get()
                    ->map(fn (WishlistItem $item) => [
                        'id' => $item->id,
                        'title' => $item->snapshot_title,
                        'image' => $item->snapshot_image_url,
                        'price' => $item->snapshot_price,
                        'note' => $item->note,
                        'from' => $item->suggestedBy?->name,
                    ])->all()
                : [],

            // Only offered once there is somebody to hand it to.
            /*
             * No longer conditional on the recipient having a linked account.
             *
             * It used to be, and the account could only be linked by them
             * opening their `/for/{token}` link and pressing "This is me" —
             * which nobody had done, so the button never appeared and a working
             * feature read as a broken one. The address is asked for at the
             * point of handing over instead.
             */
            'canHandOver' => ListAccess::isOwner($wishlist, $owner)
                && $wishlist->kind === ListKind::ForSomeone
                && $wishlist->handed_over_at === null,

            // Prefilled when we already know it, so the common case is one tap.
            'handoverEmail' => $wishlist->recipient?->person?->email,

            'registryOptions' => array_map(
                fn (EventType $type) => ['value' => $type->value, 'label' => $type->label()],
                EventType::cases(),
            ),

            // The owner's own address, shown back to them so they can change it.
            'deliveryAddress' => ListAccess::isOwner($wishlist, $owner)
                ? $wishlist->delivery_address
                : null,

            /*
             * Sharing a list *as a quiz* rather than as a list.
             *
             * The same list, two artefacts: one asks people to claim something,
             * the other asks how well they know you. The second is the one that
             * gets built in the first place, because a list nobody has a reason
             * to fill in stays empty.
             */
            'quizUrl' => ($quiz = ListQuiz::query()->where('wishlist_id', $wishlist->id)->first())
                ? url($current->url("q/{$quiz->share_token}"))
                : null,
            'quizPlays' => $quiz?->attempts()->count() ?? 0,

            /*
             * Groups this list could answer for.
             *
             * Only `mine` lists: a Secret Santa giftee is told what *you* want,
             * never what you are plotting for somebody else.
             */
            'santaMemberships' => $wishlist->kind === ListKind::Mine && $owner->isSignedIn()
                ? SecretSantaMember::query()
                    ->where('user_id', $owner->user->id)
                    ->with('group')
                    ->get()
                    ->filter(fn (SecretSantaMember $m) => $m->group !== null)
                    ->map(fn (SecretSantaMember $m) => [
                        'groupId' => $m->group->id,
                        'title' => $m->group->title,
                        'attached' => $m->wishlist_id === $wishlist->id,
                    ])
                    ->values()
                    ->all()
                : [],

            // Lane two: what I found. The existing items, unchanged.
            'items' => $wishlist->items
                ->sortByDesc('priority')
                ->values()
                ->map(fn ($item) => [
                    'id' => $item->id,
                    'title' => $item->snapshot_title,
                    'image' => $item->snapshot_image_url,
                    'price' => $item->snapshot_price,
                    'note' => $item->note,
                    'groupId' => $item->group_id,
                    'slug' => $item->group?->slug,

                    /*
                     * Where a hand-written item says you can buy it.
                     *
                     * Separate from the product-page link rather than folded
                     * into one `url`, because they are different kinds of link:
                     * one is an internal route an Inertia visit can take, the
                     * other leaves the site and has to be an ordinary anchor.
                     * Null unless the stored value is `https:` — the model
                     * refuses to hand back anything else.
                     */
                    'externalUrl' => $item->externalUrl(),

                    // Current cheapest, so the owner sees whether it moved
                    // since they added it.
                    'currentPrice' => $item->group?->min_price,
                    'merchantCount' => $item->group?->merchant_count,
                    'inStock' => $item->group?->in_stock ?? false,
                    /*
                     * Claim state is DELIBERATELY absent.
                     *
                     * A gift list exists so the recipient does not learn what
                     * has been bought. This is the owner's view, so it must
                     * carry no hint — not a boolean, not a count, not an
                     * ordering difference.
                     *
                     * Contributions are the single exception, and only on a
                     * `group` list, where the owner is the organiser and the
                     * recipient is a third party who never opens this page.
                     * Everywhere else the key is absent for exactly the reason
                     * above.
                     */
                    ...isset($contributions[$item->id])
                        ? ['contributions' => $contributions[$item->id]]
                        : [],
                ]),
        ]);
    }

    public function update(Request $request, CurrentMarket $current, string $market, string $list): RedirectResponse
    {
        $owner = Owner::fromRequest($request);
        $wishlist = $owner->scope(Wishlist::query())->find($list);

        if ($wishlist === null) {
            throw new NotFoundHttpException;
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'visibility' => ['sometimes', 'string', 'in:private,link,public'],

            /*
             * Registry. An ordinary list with an occasion, a date and somewhere
             * to send the parcel — not a fourth kind of list, because it is
             * still yours, still claimable and still shared the same way.
             */
            'event_type' => ['nullable', 'string', 'in:'.implode(',', EventType::values())],
            'event_date' => ['nullable', 'date'],
            'delivery_address' => ['nullable', 'string', 'max:500'],
        ]);

        $wishlist->update($validated);

        return back();
    }

    public function destroy(Request $request, CurrentMarket $current, string $market, string $list): RedirectResponse
    {
        $owner = Owner::fromRequest($request);
        $wishlist = $owner->scope(Wishlist::query())->find($list);

        if ($wishlist === null) {
            throw new NotFoundHttpException;
        }

        $wishlist->delete();

        return redirect()->to($current->url('lists'));
    }

    /**
     * Go and fill this list.
     *
     * The link that used to sit here pointed at a bare `/search`, which knew
     * nothing about the list it had been reached from — so every product then
     * cost a trip through the picker to choose the destination that had just
     * been implied by pressing this. See {@see AddingMode} for why the mode
     * lives in the session rather than in the URL.
     *
     * Search is the landing place because it is the only surface that takes a
     * noun, and somebody who has decided to fill a named list usually has one
     * in mind. Every other discovery surface keeps the mode once it is on.
     */
    public function add(
        Request $request,
        CurrentMarket $current,
        AddingMode $mode,
        string $market,
        string $list,
    ): RedirectResponse {
        $owner = Owner::fromRequest($request);
        $wishlist = ListAccess::scope(Wishlist::query(), $owner)->find($list);

        if ($wishlist === null) {
            throw new NotFoundHttpException;
        }

        // A viewer was brought in to coordinate, not to curate — the same rule
        // `WishlistItemController::store()` enforces on every save.
        abort_unless(ListAccess::canEdit($wishlist, $owner), 403);

        $mode->start($wishlist);

        return redirect()->to($current->url('search'));
    }

    /**
     * Done filling it.
     *
     * Returns to the list rather than staying where they are: the question
     * "what did I just add?" is the one somebody has at this moment, and the
     * answer is on the list page.
     *
     * Needs no ownership check — it only ever clears the caller's own session,
     * and there is nothing to protect in stopping.
     */
    public function doneAdding(CurrentMarket $current, AddingMode $mode): RedirectResponse
    {
        $list = $mode->listId();
        $mode->stop();

        return redirect()->to($current->url($list === null ? 'lists' : "lists/{$list}"));
    }

    /**
     * The "what they asked for" lane.
     *
     * The payoff of linking a recipient to an account: instead of guessing, the
     * page shows what the person put on their own list, and lets me claim from
     * it right here. Claiming routes through the same `WishlistItem::claim()`
     * as the shared-list page — one claim mechanism, one place the privacy rule
     * is enforced.
     *
     * They never see any of this. It is their list, so `shouldHideClaimsFrom()`
     * suppresses claim state for them wherever they read it.
     *
     * **No contributions here, deliberately.** These are somebody else's `mine`
     * items rendered inside my page, so I am a giver and a total would be legal
     * — but this is the one place where copying the group-list branch would
     * hand a breakdown about one person's list to a different person entirely.
     * If it is ever added, it goes through `ContributionView` with `$isOwner`
     * false, and never by spreading whatever the shared view builds.
     *
     * @return list<array<string, mixed>>
     */
    private function asked(GiftTarget $target, Owner $viewer, CurrentMarket $current): array
    {
        $identity = $viewer->claimIdentity();
        $hash = $identity === null ? null : WishlistItem::identityHash($identity);

        return $target->statedWishes()
            ->flatMap(fn (Wishlist $list) => $list->items->map(fn (WishlistItem $item) => [
                'id' => $item->id,
                'token' => $list->share_token,
                'listTitle' => $list->title,
                'title' => $item->snapshot_title,
                'image' => $item->snapshot_image_url,
                'price' => $item->group?->min_price ?? $item->snapshot_price,
                'note' => $item->note,
                'live' => $item->rendersLive(),
                'url' => $item->group === null
                    ? null
                    : $current->url("p/{$item->group_id}/{$item->group->slug}"),
                'claimed' => $item->isClaimed(),
                'claimedByMe' => $hash !== null && $item->claimed_by_hash === $hash,
                'sent' => $hash !== null && $item->claimed_by_hash === $hash
                    ? $item->marked_sent_at !== null
                    : null,
            ]))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function summarise(Wishlist $list, CurrentMarket $current): array
    {
        return [
            'id' => $list->id,
            'title' => $list->title,
            'description' => $list->description,
            'kind' => $list->kind->value,
            'claimable' => $list->allowsClaiming(),
            'isDefault' => (bool) $list->is_default,
            'handedOver' => $list->handed_over_at !== null,
            'eventType' => $list->event_type?->value,
            'eventDate' => $list->event_date?->toDateString(),

            /*
             * Waiting suggestions, so the index says which list received one.
             *
             * The Gift Cove's suggestions card points at `/lists`, and that
             * destination was chosen deliberately — the index is where you see
             * which list has something waiting. It just did not say so, which
             * made the card look like it went to the wrong place.
             *
             * Owner-only, and absent rather than zero on a list somebody else
             * owns: a suggestion is a message addressed to the owner, and a
             * collaborator learning that one arrived is a leak of the fact that
             * somebody is thinking about this person.
             */
            'suggestions' => $list->suggestions_count ?? null,
            'visibility' => $list->visibility->value,
            'itemCount' => $list->items_count ?? $list->items()->count(),
            'recipient' => $list->recipient === null ? null : [
                'id' => $list->recipient->id,
                'name' => $list->recipient->name,
                'relationship' => $list->recipient->relationship,
            ],
            'url' => $current->url("lists/{$list->id}"),
            // Only meaningful once the list is shareable; the UI hides it
            // otherwise rather than offering a link that 404s.
            'shareUrl' => $list->visibility === ListVisibility::Private
                ? null
                : url($current->url("l/{$list->share_token}")),
        ];
    }
}

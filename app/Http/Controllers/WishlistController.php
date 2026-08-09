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
use App\Services\Wishlist\DefaultList;
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

        $lists = $owner->scope(Wishlist::query())
            ->with('recipient')
            ->withCount('items')
            ->latest('updated_at')
            ->get()
            ->map(fn (Wishlist $list) => $this->summarise($list, $current));

        return Inertia::render('Lists/Index', [
            'lists' => $lists,
            'recipients' => $owner->scope(Recipient::query())
                ->orderBy('name')
                ->get(['id', 'name', 'relationship', 'occasion']),
            'isSignedIn' => $owner->isSignedIn(),
        ]);
    }

    public function store(Request $request, CurrentMarket $current): RedirectResponse
    {
        $owner = Owner::fromRequest($request);
        abort_unless($owner->exists(), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'recipient_id' => ['nullable', 'uuid'],
        ]);

        // A recipient must belong to the same owner, or a guessed uuid would
        // attach someone else's person to this list.
        if (! empty($validated['recipient_id'])) {
            $owned = $owner->scope(Recipient::query())
                ->whereKey($validated['recipient_id'])
                ->exists();

            abort_unless($owned, 403);
        }

        $list = Wishlist::create([
            ...$owner->attributes(),
            'title' => $validated['title'],
            'market' => $current->get(),
            'recipient_id' => $validated['recipient_id'] ?? null,
            /*
             * The recipient decides the kind; there is no separate switch.
             * Letting the two be set independently is what allowed a list to
             * claim it was a registry while being private research about a
             * person — the ambiguity `kind` exists to remove.
             */
            'kind' => empty($validated['recipient_id'])
                ? ListKind::Mine
                : ListKind::ForSomeone,
        ]);

        return redirect()->to($current->url("lists/{$list->id}"));
    }

    public function show(Request $request, CurrentMarket $current, string $market, string $list): Response
    {
        $owner = Owner::fromRequest($request);

        // Collaborators see it too — a co-giver invited to help choose has to
        // be able to open the thing they were invited to.
        $wishlist = ListAccess::scope(Wishlist::query(), $owner)
            ->with(['recipient', 'items.group', 'collaborators.user'])
            ->find($list);

        if ($wishlist === null) {
            throw new NotFoundHttpException;
        }

        $target = $wishlist->recipient === null
            ? null
            : GiftTarget::fromRecipient($wishlist->recipient, $current->get());

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
            'canHandOver' => ListAccess::isOwner($wishlist, $owner)
                && $wishlist->kind === ListKind::ForSomeone
                && $wishlist->handed_over_at === null
                && $wishlist->recipient?->user_id !== null,

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
                     */
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

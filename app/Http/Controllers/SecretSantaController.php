<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ListKind;
use App\Enums\SantaStatus;
use App\Mail\SecretSantaAssignmentMail;
use App\Models\SecretSantaGroup;
use App\Models\SecretSantaMember;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Gift\DrawImpossible;
use App\Services\Gift\GiftTarget;
use App\Services\Gift\SantaRepair;
use App\Services\Gift\SecretSantaDraw;
use App\Support\CurrentMarket;
use App\Support\Owner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Number;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Secret Santa.
 *
 * An **assignment layer**, not a subsystem: the draw decides who your
 * {@see GiftTarget} is, and from there the experience is the ordinary gift page
 * — their list on one side, what you found on the other, claiming that already
 * stops duplicates, and a privacy rule that already holds.
 *
 * What is genuinely new here is the group, the membership and the pairing.
 * Everything else is reuse, and it is reuse on purpose: a second claim
 * mechanism grown inside this feature is how invariant #4 eventually breaks.
 */
class SecretSantaController extends Controller
{
    /**
     * Your groups, and the form to start one.
     *
     * Both the ones you organise and the ones you merely joined — from a
     * member's point of view those are the same thing, and separating them
     * would be organising the page around our schema rather than around what
     * they came to do.
     */
    public function index(Request $request, CurrentMarket $current): Response
    {
        $user = $request->user();

        $groups = $user === null
            ? collect()
            : SecretSantaGroup::query()
                ->where('market', $current->value())
                ->where(fn ($q) => $q
                    ->where('owner_user_id', $user->id)
                    ->orWhereExists(fn ($sub) => $sub
                        ->selectRaw('1')
                        ->from('secret_santa_members')
                        ->whereColumn('secret_santa_members.group_id', 'secret_santa_groups.id')
                        ->where('secret_santa_members.user_id', $user->id)))
                ->withCount('members')
                ->latest()
                ->get();

        return Inertia::render('Santa/Index', [
            'groups' => $groups->map(fn (SecretSantaGroup $group) => [
                'id' => $group->id,
                'title' => $group->title,
                'members' => $group->members_count,
                'drawn' => $group->status->isDrawn(),
                'exchangeDate' => $group->exchange_date?->toDateString(),
                'url' => $current->url("santa/{$group->id}"),
            ])->all(),
            'isSignedIn' => $user !== null,
        ]);
    }

    public function show(Request $request, CurrentMarket $current, string $market, string $group): Response
    {
        $santa = $this->find($group);
        $me = $this->membership($request, $santa);
        $isOrganiser = $santa->isOrganiser($request->user());

        return Inertia::render('Santa/Group', [
            'group' => [
                'id' => $santa->id,
                'title' => $santa->title,
                'budgetMin' => $santa->budget_min,
                'budgetMax' => $santa->budget_max,
                'exchangeDate' => $santa->exchange_date?->toDateString(),
                'theme' => $santa->theme,
                'drawn' => $santa->status->isDrawn(),
                'inviteUrl' => url($current->url("santa/{$santa->id}/join/{$santa->invite_token}")),
            ],
            'isOrganiser' => $isOrganiser,

            /*
             * Names only, and never assignments.
             *
             * The organiser needs to see who is in so they know when to draw.
             * They must not learn who drew whom — v1 let the organiser read the
             * pairings outright, which quietly makes one player a spectator of
             * everyone else's game.
             */
            'members' => $santa->members()->orderBy('display_name')->get()
                ->map(fn (SecretSantaMember $member) => [
                    'id' => $member->id,
                    'name' => $member->display_name,
                    'joined' => $member->joined_at !== null,
                    'hasList' => $member->wishlist_id !== null,
                    // Aggregate progress, not who bought what.
                    'done' => $member->marked_done_at !== null,
                ]),

            'me' => $me === null ? null : $this->mine($me, $current),
        ]);
    }

    public function store(Request $request, CurrentMarket $current): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'budget_min' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'budget_max' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'exchange_date' => ['nullable', 'date'],
            'theme' => ['nullable', 'string', 'max:120'],
        ]);

        $santa = SecretSantaGroup::create([
            'owner_user_id' => $user->id,
            'market' => $current->get(),
            'title' => $validated['title'],
            // Euros in, cents stored — invariant #7.
            'budget_min' => isset($validated['budget_min']) ? (int) round((float) $validated['budget_min'] * 100) : null,
            'budget_max' => isset($validated['budget_max']) ? (int) round((float) $validated['budget_max'] * 100) : null,
            'exchange_date' => $validated['exchange_date'] ?? null,
            'theme' => $validated['theme'] ?? null,
        ]);

        // The organiser plays too. Almost nobody wants to run one without being
        // in it, and joining separately is a step everyone forgets.
        SecretSantaMember::create([
            'group_id' => $santa->id,
            'user_id' => $user->id,
            'email' => $user->email,
            'display_name' => $user->displayName(),
            'joined_at' => now(),
        ]);

        return redirect()->to($current->url("santa/{$santa->id}"));
    }

    /**
     * The invite link, opened.
     *
     * This route was `POST` only. The URL the organiser shares — the one that
     * goes into a WhatsApp group and is the entire point of the feature — is
     * exactly this URL, so every invite ever sent answered a browser with 405
     * Method Not Allowed. There was no join form anywhere either: the only way
     * into a group was a hand-built POST, which means nobody but the organiser
     * (auto-joined at creation) had ever been in one.
     *
     * No account required, deliberately. Asking someone to sign up before they
     * can be in an office Secret Santa is how most of the office does not join.
     */
    public function invite(Request $request, CurrentMarket $current, string $market, string $group, string $token): Response|RedirectResponse
    {
        $santa = $this->find($group);

        abort_unless(hash_equals($santa->invite_token, $token), 404);

        // Somebody following their own invite a second time should land on their
        // own page rather than be asked to join again.
        $existing = $this->membership($request, $santa);

        if ($existing !== null) {
            return redirect()->to($current->url("santa/{$santa->id}/me/{$existing->join_token}"));
        }

        $user = $request->user();

        return Inertia::render('Santa/Join', [
            'group' => [
                'id' => $santa->id,
                'title' => $santa->title,
                'token' => $token,
                'budgetMin' => $santa->budget_min,
                'budgetMax' => $santa->budget_max,
                'exchangeDate' => $santa->exchange_date?->toDateString(),
                'theme' => $santa->theme,
                /*
                 * Joining closes at the draw: a member added afterwards has
                 * nobody to buy for and nobody buying for them. Said on the page
                 * rather than left to a 403 after they have typed their name in.
                 */
                'drawn' => $santa->status->isDrawn(),
                'members' => $santa->members()->count(),
            ],

            // Names only — who else is in is not a secret, and it is what tells
            // somebody they are joining the right group.
            'members' => $santa->members()->orderBy('display_name')->pluck('display_name'),

            'you' => [
                'name' => $user?->name,
                'email' => $user?->email,
            ],
        ]);
    }

    public function join(Request $request, CurrentMarket $current, string $market, string $group, string $token): RedirectResponse
    {
        $santa = $this->find($group);

        abort_unless(hash_equals($santa->invite_token, $token), 404);

        // Joining after the draw would leave the new member with nobody to buy
        // for and nobody buying for them.
        abort_if($santa->status->isDrawn(), 403, __('site.santa.already_drawn'));

        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'exclusions' => ['sometimes', 'array', 'max:20'],
            'exclusions.*' => ['string', 'max:190'],
        ]);

        $member = SecretSantaMember::updateOrCreate(
            ['group_id' => $santa->id, 'email' => mb_strtolower($validated['email'])],
            [
                'display_name' => $validated['display_name'],
                'user_id' => $request->user()?->id,
                'joined_at' => now(),
                'exclusions' => array_map(mb_strtolower(...), $validated['exclusions'] ?? []),
            ],
        );

        return redirect()
            ->to($current->url("santa/{$santa->id}/me/{$member->join_token}"))
            ->with('success', __('site.santa.joined'));
    }

    /**
     * Do the draw.
     *
     * Wrapped in a transaction, because a partial draw is the worst possible
     * state: some people know who they have, the rest do not, and re-running it
     * would change the assignments of people who were already emailed.
     */
    public function draw(Request $request, CurrentMarket $current, string $market, string $group, SecretSantaDraw $draw): RedirectResponse
    {
        $santa = $this->find($group);

        abort_unless($santa->isOrganiser($request->user()), 403, __('site.santa.organiser_only'));
        abort_if($santa->status->isDrawn(), 403, __('site.santa.already_drawn'));

        $members = $santa->members()->get();

        $exclusions = $this->exclusionMap($members);

        try {
            $assignments = $draw->assign($members->pluck('id')->all(), $exclusions);
        } catch (DrawImpossible $e) {
            $blocked = $e->blockedBy === null ? null : $members->firstWhere('id', $e->blockedBy);

            return back()->with('error', $blocked === null
                ? $e->getMessage()
                : $e->getMessage().' ('.$blocked->display_name.')');
        }

        /*
         * All or nothing.
         *
         * A half-finished draw is the worst state this feature can reach: some
         * people know who they have, the rest do not, and re-running it would
         * change assignments that have already been emailed — and an email
         * cannot be unsent.
         */
        DB::transaction(function () use ($santa, $members, $assignments): void {
            foreach ($members as $member) {
                $member->update(['assigned_member_id' => (string) $assignments[$member->id]]);
            }

            $santa->update(['status' => SantaStatus::Drawn, 'drawn_at' => now()]);
        });

        // Only after the transaction commits. Mail queued inside it would go out
        // even if the write rolled back.
        $this->notify($santa, $members->fresh(), $current);

        return back()->with('success', __('site.santa.drawn'));
    }

    /**
     * Remove somebody, and close the hole they leave.
     *
     * Before the draw this is a deletion and nothing else. After it, the draw
     * has to be repaired around them — their giver takes on their giftee — and
     * that is the whole reason this is not just an `update()`. See
     * {@see SantaRepair} for why a full re-draw would be the wrong answer.
     */
    public function removeMember(
        Request $request,
        CurrentMarket $current,
        string $market,
        string $group,
        string $member,
        SantaRepair $repair,
    ): RedirectResponse {
        $santa = $this->find($group);

        abort_unless($santa->isOrganiser($request->user()), 403, __('site.santa.organiser_only'));

        $leaving = $santa->members()->whereKey($member)->first();

        if ($leaving === null) {
            throw new NotFoundHttpException;
        }

        /*
         * Before the draw: no pairings exist, so nobody is affected and nobody
         * is emailed. Kept as its own branch rather than folded in, because the
         * ordinary case must not go anywhere near the repair path.
         */
        if (! $santa->status->isDrawn()) {
            $leaving->update(['removed_at' => now()]);

            return back()->with('success', __('site.santa.member_removed'));
        }

        $members = $santa->members()->get();

        $assignments = [];

        foreach ($members as $one) {
            // Encrypted, not hashed — reversible, which is what makes a partial
            // repair possible at all.
            if ($one->assigned_member_id !== null) {
                $assignments[$one->id] = (int) $one->assigned_member_id;
            }
        }

        try {
            $changed = $repair->remove($assignments, $leaving->id, $this->exclusionMap($members));
        } catch (DrawImpossible $e) {
            return back()->with('error', $e->getMessage());
        }

        DB::transaction(function () use ($leaving, $members, $changed): void {
            $leaving->update(['removed_at' => now(), 'assigned_member_id' => null]);

            foreach ($changed as $giverId => $gifteeId) {
                $members->firstWhere('id', $giverId)
                    ?->update(['assigned_member_id' => (string) $gifteeId]);
            }
        });

        /*
         * Only the people whose assignment moved, and only after commit.
         *
         * The removed member is emailed nothing — the same rule as deleting a
         * group. The organiser is talking to them anyway, and a "you have been
         * removed" mail from us is a worse way to find out.
         */
        $this->notify(
            $santa,
            $santa->members()->whereIn('id', array_keys($changed))->get(),
            $current,
            changed: true,
        );

        return back()->with('success', __('site.santa.member_removed'));
    }

    /**
     * "Not this person" — swap two givers' giftees.
     *
     * A transposition rather than a re-roll, which is what the copy has said
     * since before this existed: `santa.redrawn` reads "Redrawn. Both people
     * have been emailed."
     */
    public function redraw(
        Request $request,
        CurrentMarket $current,
        string $market,
        string $group,
        string $member,
        SantaRepair $repair,
    ): RedirectResponse {
        $santa = $this->find($group);

        abort_unless($santa->isOrganiser($request->user()), 403, __('site.santa.organiser_only'));
        abort_unless($santa->status->isDrawn(), 403, __('site.santa.not_drawn'));

        $members = $santa->members()->get();
        $subject = $members->firstWhere('id', (int) $member);

        if ($subject === null) {
            throw new NotFoundHttpException;
        }

        $assignments = [];

        foreach ($members as $one) {
            if ($one->assigned_member_id !== null) {
                $assignments[$one->id] = (int) $one->assigned_member_id;
            }
        }

        try {
            $changed = $repair->redraw($assignments, $subject->id, $this->exclusionMap($members));
        } catch (DrawImpossible $e) {
            return back()->with('error', $e->getMessage());
        }

        DB::transaction(function () use ($members, $changed): void {
            foreach ($changed as $giverId => $gifteeId) {
                $members->firstWhere('id', $giverId)?->update([
                    'assigned_member_id' => (string) $gifteeId,
                    'redrawn_at' => now(),
                ]);
            }
        });

        $this->notify(
            $santa,
            $santa->members()->whereIn('id', array_keys($changed))->get(),
            $current,
            changed: true,
        );

        return back()->with('success', __('site.santa.redrawn'));
    }

    /**
     * Who may not draw whom, as member ids.
     *
     * Exclusions are typed as names or emails by people who do not know each
     * other's account details, so they are resolved loosely here and applied
     * strictly inside the draw. Extracted because the draw and both repair
     * paths need exactly the same map, and three copies of it would drift.
     *
     * @param  Collection<int, SecretSantaMember>  $members
     * @return array<int, list<int>>
     */
    private function exclusionMap($members): array
    {
        $map = [];

        foreach ($members as $member) {
            $map[$member->id] = $members
                ->filter(fn (SecretSantaMember $other) => $other->id !== $member->id && $this->isExcluded($member, $other))
                ->pluck('id')
                ->all();
        }

        return $map;
    }

    /**
     * What one member sees: who they drew, and that person's list.
     *
     * Reached by join token, so a member without an account can read it. The
     * token identifies exactly one person, which is the whole of the
     * authorisation — same model as `/l/{token}` and `/for/{token}`.
     */
    public function me(Request $request, CurrentMarket $current, string $market, string $group, string $token): Response
    {
        $santa = $this->find($group);

        $member = $santa->members()->where('join_token', $token)->first();

        if ($member === null) {
            throw new NotFoundHttpException;
        }

        return Inertia::render('Santa/Me', [
            'group' => [
                'id' => $santa->id,
                'title' => $santa->title,
                'budgetMin' => $santa->budget_min,
                'budgetMax' => $santa->budget_max,
                'exchangeDate' => $santa->exchange_date?->toDateString(),
                'drawn' => $santa->status->isDrawn(),
            ],
            'me' => $this->mine($member, $current),
        ]);
    }

    /**
     * Point a group at the list you have already built.
     *
     * The join between the two halves of this feature. Without it a member's
     * own wishlist and their Secret Santa membership are two unrelated things,
     * and whoever drew them still has nothing to go on — which is the state
     * that makes a gift exchange a guessing game.
     */
    public function attachList(Request $request, CurrentMarket $current, string $market, string $group): RedirectResponse
    {
        $santa = $this->find($group);
        $member = $this->membership($request, $santa);

        // Only your own membership, and only a list you actually own.
        abort_if($member === null, 403);

        $validated = $request->validate(['wishlist_id' => ['nullable', 'uuid']]);

        $listId = $validated['wishlist_id'] ?? null;

        if ($listId !== null) {
            $owned = Owner::fromRequest($request)
                ->scope(Wishlist::query())
                ->whereKey($listId)
                ->where('kind', ListKind::Mine->value)
                ->exists();

            abort_unless($owned, 403);
        }

        $member->update(['wishlist_id' => $listId]);

        return back()->with('success', __('site.santa.list_attached'));
    }

    /**
     * Call the whole thing off.
     *
     * The organiser's alone. A member who wants out is a different act with a
     * different consequence — the remaining draw has to be repaired around them
     * — and giving one person a button that ends everybody else's exchange is
     * not that.
     *
     * Members go with the group, by the cascade the schema already declares.
     * Two things deliberately survive it:
     *
     * - **Wishlists.** A member attached a list they own; the group borrowed it
     *   and does not get to take it. The foreign key nulls rather than cascades
     *   for exactly this reason.
     * - **Claims.** Somebody genuinely said they would buy a thing, and may well
     *   already have. Deleting the group they arranged it through does not
     *   unbuy it, and quietly freeing those items would send a second person to
     *   the shops.
     */
    public function destroy(Request $request, CurrentMarket $current, string $market, string $group): RedirectResponse
    {
        $santa = $this->find($group);

        abort_unless($santa->isOrganiser($request->user()), 403, __('site.santa.organiser_only'));

        $santa->delete();

        return redirect()
            ->to($current->url('santa'))
            ->with('success', __('site.santa.deleted'));
    }

    public function markDone(Request $request, CurrentMarket $current, string $market, string $group, string $token): RedirectResponse
    {
        $santa = $this->find($group);
        $member = $santa->members()->where('join_token', $token)->firstOrFail();

        $member->update(['marked_done_at' => now()]);

        return back()->with('success', __('site.santa.marked_done'));
    }

    /**
     * Tell each member, and only that member, who they drew.
     *
     * One email per person, each naming exactly one pairing. This is the single
     * channel through which the game can be spoiled, which is why the group page
     * is aggregate-only and why nothing here is ever sent to the organiser as a
     * summary.
     *
     * @param  Collection<int, SecretSantaMember>  $members
     */
    private function notify(SecretSantaGroup $santa, $members, CurrentMarket $current, bool $changed = false): void
    {
        /*
         * Giftees are resolved from the whole group, not from `$members`.
         *
         * `$members` is who to *email*, and after a repair that is two people
         * out of twelve — whose giftees are almost certainly not among those
         * two. Keying off the passed collection silently emailed nobody, which
         * is the worst possible failure here: the write succeeds, the repair
         * looks done, and one person is buying for someone who has changed.
         */
        $byId = $santa->allMembers()->get()->keyBy('id');

        foreach ($members as $member) {
            $giftee = $byId->get((int) $member->assigned_member_id);

            if ($giftee === null) {
                continue;
            }

            Mail::to($member->email)->queue(new SecretSantaAssignmentMail(
                gifteeName: $giftee->display_name,
                groupTitle: $santa->title,
                market: $santa->market,
                meUrl: url($current->url("santa/{$santa->id}/me/{$member->join_token}")),
                budget: $santa->budget_max === null
                    ? null
                    : Number::currency($santa->budget_max / 100, $santa->market->currency()),
                exchangeDate: $santa->exchange_date?->toFormattedDateString(),
                gifteeHasList: $giftee->wishlist_id !== null,
                // "This has changed" rather than "here is your person". Somebody
                // who already read the first mail needs to know the second one
                // supersedes it, or they will assume it is a duplicate.
                changed: $changed,
            ));
        }
    }

    /**
     * Everything one member may know.
     *
     * The giftee is resolved here, at render time, from the encrypted column —
     * never by minting a `Recipient` row per giver. That shortcut would have put
     * the pairing back into `recipients.name` in plain text and made the
     * encryption decorative.
     *
     * @return array<string, mixed>
     */
    private function mine(SecretSantaMember $member, CurrentMarket $current): array
    {
        $giftee = $member->assigned_member_id === null
            ? null
            : SecretSantaMember::query()->find($member->assigned_member_id);

        $target = $giftee === null
            ? null
            : GiftTarget::fromPerson($giftee->display_name, $current->get(), $giftee->user);

        return [
            'joinToken' => $member->join_token,
            'name' => $member->display_name,
            'done' => $member->marked_done_at !== null,
            'hasList' => $member->wishlist_id !== null,
            'listUrl' => $member->wishlist_id === null
                ? null
                : $current->url("lists/{$member->wishlist_id}"),

            'giftee' => $target === null ? null : [
                'name' => $target->name,
                'isLinked' => $target->isLinked(),
                'wishes' => $this->wishes($giftee, $target, $current),
            ],
        ];
    }

    /**
     * The giftee's own list, if they made one.
     *
     * Two sources, in order: the list they attached to this group, then any
     * list they share publicly through a linked account. Both are ordinary
     * wishlists — this feature adds no way to hold a product.
     *
     * @return list<array<string, mixed>>
     */
    private function wishes(SecretSantaMember $giftee, GiftTarget $target, CurrentMarket $current): array
    {
        $lists = $giftee->wishlist_id !== null
            ? Wishlist::query()->whereKey($giftee->wishlist_id)->with('items.group')->get()
            : $target->statedWishes();

        return $lists
            ->flatMap(fn (Wishlist $list) => $list->items->map(fn (WishlistItem $item) => [
                'id' => $item->id,
                'token' => $list->share_token,
                'title' => $item->snapshot_title,
                'image' => $item->snapshot_image_url,
                'price' => $item->group?->min_price ?? $item->snapshot_price,
                'live' => $item->rendersLive(),
                'url' => $item->group === null
                    ? null
                    : $current->url("p/{$item->group_id}/{$item->group->slug}"),
                // So a Santa can keep an idea on their own shortlist while they
                // think about it. Already present in `url`; see the same note
                // in SharedListController.
                'groupId' => $item->group_id,
                // Claiming still matters inside a group: several people may hold
                // the same person's link when families overlap.
                'claimed' => $item->isClaimed(),
            ]))
            ->values()
            ->all();
    }

    /** Exclusions are typed by hand, so match on either name or address. */
    private function isExcluded(SecretSantaMember $member, SecretSantaMember $other): bool
    {
        $exclusions = array_map(mb_strtolower(...), (array) $member->exclusions);

        return in_array(mb_strtolower($other->email), $exclusions, true)
            || in_array(mb_strtolower($other->display_name), $exclusions, true);
    }

    private function membership(Request $request, SecretSantaGroup $santa): ?SecretSantaMember
    {
        $user = $request->user();

        return $user === null
            ? null
            : $santa->members()->where('user_id', $user->id)->first();
    }

    private function find(string $id): SecretSantaGroup
    {
        $santa = SecretSantaGroup::query()->find($id);

        if ($santa === null) {
            throw new NotFoundHttpException;
        }

        return $santa;
    }
}

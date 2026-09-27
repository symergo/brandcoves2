<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ListKind;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\RecipientGift;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Gift\GiftHistory;
use App\Services\Gift\GiftTags;
use App\Services\Gift\NextSteps;
use App\Services\Gift\PastGift;
use App\Services\Seo\PageMeta;
use App\Services\Social\FriendInvites;
use App\Services\Social\PersonProfile;
use App\Services\Wishlist\ListMaker;
use App\Support\CurrentMarket;
use App\Support\Owner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A saved person's page: who they are, their lists, what you gave them, and
 * what could come next.
 *
 * `/people/{id}`, the owner's only. Since 2026-09-27 a profile first: what you
 * know about them, editable in place, their own wish lists when they are a
 * friend, and the lists you are making for them ({@see PersonProfile}). Then
 * the gift history (what they noted, and their own claims on lists for this
 * person), the items on their lists for this person with an "I gave this"
 * button, and a short "next step" row.
 *
 * The reminder email lands here too: an idea's "add to the list" link opens
 * this page with `?add=<product>`, and the page shows that product at the top
 * with the save button. Adding from the email itself would be a GET that
 * writes, which mail scanners that open every link would press on the
 * reader's behalf. See docs/features/gift-history.md.
 */
class PersonController extends Controller
{
    public function show(Request $request, CurrentMarket $current, GiftHistory $history, NextSteps $nextSteps, PersonProfile $profile, FriendInvites $invites, string $market, string $recipient): Response
    {
        $person = $this->findOwned($request, $recipient);
        $past = $history->for($person);
        $steps = $nextSteps->cards($person, $current->get(), 4, $past);
        $highlight = $this->highlight($request);

        // The person's name, not "Gifts for Mum": the page is about them now
        // (2026-09-27, docs/features/my-people.md).
        app(PageMeta::class)->set(title: $person->name, robots: 'noindex, nofollow');

        /** @var User $viewer the route is behind `auth` */
        $viewer = $request->user();

        return Inertia::render('Recipients/Show', [
            'person' => [
                'id' => $person->id,
                'name' => $person->name,
                'relationship' => $person->relationship,
                'birthday' => $person->birthday === null ? null : [
                    'day' => $person->birthday->day,
                    'month' => $person->birthday->month,
                ],
                'isLinked' => $person->isLinked(),
            ],
            'profile' => $profile->for($viewer, $person, $current),
            /*
             * Find a gift's own vocabularies, so an interest edited here is
             * the same value the wizard and the engine read (the same call
             * GiftProfileCardController makes).
             */
            // Find a gift's own choices. `values` is not among them (its form
            // keeps the three in the page), so it is added here: without it
            // the "Over" form crashed on opening (found 2026-09-27).
            'options' => array_intersect_key(
                app(GiftController::class)->options(),
                array_flip(['interests', 'vibes', 'ages', 'relationships']),
            ) + ['values' => GiftTags::VALUE_OPTIONS],
            /*
             * Deleting fails at the database while a group gift is about
             * them: `wishlists.recipient_id` is set to null on delete, and a
             * group list must have a recipient (CHECK
             * wishlists_group_has_recipient). The page says so up front
             * instead of offering a button that cannot work.
             */
            'groupLists' => Wishlist::query()
                ->where('recipient_id', $person->id)
                ->where('kind', ListKind::Group->value)
                ->count(),
            'history' => array_map(fn (PastGift $gift) => $gift->toArray(), $past),
            'unmarked' => $history->unmarkedItems($person)->map(fn (WishlistItem $item) => [
                'id' => $item->id,
                'title' => $item->displayTitle(),
                'image' => $item->group?->image_url ?? $item->snapshot_image_url,
            ])->values()->all(),
            'nextSteps' => $steps,
            'highlight' => $highlight,
            'recipientList' => $this->theirList($request, $current, $person, $steps !== [] || $highlight !== null),
            'urls' => [
                'finder' => $current->url('gift').'?for='.$person->id,
                'taste' => $current->url('gift/taste').'?person='.$person->id,
                'ask' => $current->url('ask').'?person='.$person->id,
                'gifts' => $current->url("people/{$person->id}/gifts"),
                'recipient' => $current->url("recipients/{$person->id}"),
                'people' => $current->url('people'),
                /*
                 * Their own link, where they say what they like without
                 * seeing anything you picked (RecipientProfileController).
                 * Not offered once they are linked to an account: they have
                 * answered for themselves, or can, as a friend.
                 */
                'selfDescribe' => $person->isLinked() ? null : url($current->url("for/{$person->share_token}")),
                /*
                 * "Nodig uit op GiftCoves" (2026-09-27): the friends' own
                 * invitation, naming this person so the connection lands on
                 * them. Only while no account is behind them; the same rule
                 * FriendInvites::mayLink() applies on the way in.
                 */
                'invite' => $invites->mayLink($viewer, $person) ? $current->url('friends') : null,
            ],
            'thisYear' => (int) now()->year,
        ]);
    }

    /**
     * "I gave this": typed, or an item from their list.
     *
     * An item is accepted only from a list about this person that the owner
     * owns, or when it is the owner's own claim; anything else is a 404, the
     * same answer as an item that does not exist, so the endpoint cannot be
     * used to test ids.
     */
    public function store(Request $request, CurrentMarket $current, GiftHistory $history, string $market, string $recipient): RedirectResponse
    {
        $person = $this->findOwned($request, $recipient);
        $thisYear = (int) now()->year;

        $validated = $request->validate([
            'item_id' => ['nullable', 'integer'],
            'title' => ['required_without:item_id', 'nullable', 'string', 'max:200'],
            // A year, not a date: "last Christmas" is what people remember.
            'year' => ['nullable', 'integer', 'min:1950', 'max:'.$thisYear],
        ]);

        $year = (int) ($validated['year'] ?? $thisYear);

        if (! empty($validated['item_id'])) {
            $item = WishlistItem::query()->with('group')->find($validated['item_id']);

            if ($item === null || ! $history->canMark($person, $item)) {
                throw new NotFoundHttpException;
            }

            RecipientGift::query()->firstOrCreate(
                ['recipient_id' => $person->id, 'wishlist_item_id' => $item->id],
                ['group_id' => $item->group_id, 'title' => mb_substr($item->displayTitle(), 0, 200), 'given_year' => $year],
            );
        } else {
            $title = trim((string) $validated['title']);

            if ($title === '') {
                return back()->withErrors(['title' => __('site.gift_history.title_required')]);
            }

            $person->gifts()->create(['title' => $title, 'given_year' => $year]);
        }

        return back()->with('success', __('site.gift_history.added'));
    }

    public function destroy(Request $request, CurrentMarket $current, string $market, string $recipient, string $gift): RedirectResponse
    {
        $person = $this->findOwned($request, $recipient);

        $person->gifts()->whereKey((int) $gift)->delete();

        return back();
    }

    /**
     * The product an email's "add to the list" link named, if it can be shown.
     *
     * @return array<string, mixed>|null
     */
    private function highlight(Request $request): ?array
    {
        $id = $request->integer('add');

        if ($id <= 0) {
            return null;
        }

        $group = ProductGroup::query()->presentable()->whereNull('merged_into_id')->find($id);

        return $group === null ? null : [
            'id' => $group->id,
            'title' => $group->displayTitle(),
            'brand' => $group->brand,
            'image' => $group->image_url,
            'price' => $group->min_price,
            'url' => $group->path(),
        ];
    }

    /**
     * The list a save from this page lands on: the owner's newest list about
     * this person, made when there is something to save and none exists yet.
     * The same rule as Find a gift's (GiftController::recipientList()).
     *
     * @return array{id: string, title: string, kind: string}|null
     */
    private function theirList(Request $request, CurrentMarket $current, Recipient $person, bool $needed): ?array
    {
        $owner = Owner::fromRequest($request);

        $list = $owner->scope(Wishlist::query())
            ->where('recipient_id', $person->id)
            ->where('kind', ListKind::ForSomeone->value)
            ->latest('created_at')
            ->first();

        if ($list === null) {
            if (! $needed || ! $owner->isSignedIn()) {
                return null;
            }

            $list = app(ListMaker::class)->make(
                $owner,
                $current,
                __('site.lists.for_person', ['name' => $person->name]),
                recipientId: $person->id,
            );
        }

        return ['id' => $list->id, 'title' => $list->displayTitle(), 'kind' => $list->kind->value];
    }

    private function findOwned(Request $request, string $id): Recipient
    {
        $person = Owner::fromRequest($request)->scope(Recipient::query())->find($id);

        if ($person === null) {
            throw new NotFoundHttpException;
        }

        return $person;
    }
}

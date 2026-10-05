<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\Interest;
use App\Enums\ListKind;
use App\Enums\ListVisibility;
use App\Enums\RecipientStatus;
use App\Enums\RecipientType;
use App\Models\Friendship;
use App\Models\ListOpen;
use App\Models\Recipient;
use App\Models\TasteInvite;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistShare;
use App\Support\CurrentMarket;
use App\Support\DayAndMonth;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * "My people": everybody somebody buys for, on one list.
 *
 * Two kinds of row came from two places until 2026-09-26: saved people
 * (`recipients`, the owner's own notes about somebody, most of them without an
 * account) and friends (`friendships`, another account you are connected to).
 * The owner asked what the difference was, and there is no answer a visitor
 * needs: both are "somebody I buy for". So this merges them. A friend who is
 * linked to a saved person (`recipients.user_id`) is one row, not two.
 *
 * ## What each kind of row may carry
 *
 * - **A saved person** is the owner's own record. Everything on it is theirs to
 *   see, and nobody else's: the rows come from `recipients` scoped to this user
 *   and nothing else.
 * - **A friend** carries only what the friend shared: their published birthday
 *   (or the note you wrote on your own side of the connection), and the lists
 *   they shared with you, sent you the link to, or show to all their people.
 *   The same rules as the friends page had, plus that last one; see
 *   docs/features/friends.md and wish-list-for-my-people.md.
 *
 * Claim state is absent in every form (invariant 4). No list's items are
 * loaded, and nothing here counts, orders or labels by what has been claimed.
 * A list's occasion date is read, which the list's own page already shows to
 * anybody who may open it.
 */
class MyPeople
{
    public function __construct(
        private readonly Friends $friends,
        private readonly InCommon $inCommon,
    ) {}

    /**
     * One row per person, the nearest date first, then by name.
     *
     * @return list<array<string, mixed>>
     */
    public function for(User $user, CurrentMarket $current, ?CarbonInterface $today = null): array
    {
        $today = CarbonImmutable::parse(($today ?? now())->toDateString());

        $saved = Recipient::query()
            ->where('owner_user_id', $user->id)
            // Yourself is not somebody you buy for; This or that played "for
            // me" keeps a row like that.
            ->where('status', '!=', RecipientStatus::Self->value)
            ->oldest()
            ->get();

        $connections = $this->friends->forUser($user);
        $friendLists = $this->sharedWith($user, $connections);
        $inCommon = $this->inCommon->for(
            $user,
            $connections->pluck('friend_id')->map(fn ($id) => (int) $id)->values()->all(),
            $friendLists,
            $current,
        );
        $theySee = $this->seenBy($user, $current);

        /*
         * Which friend belongs to which saved person.
         *
         * `recipients.user_id` is the link, set by "this person is one of my
         * friends" when the person was saved, by "save what you know about
         * them" on this page, or by the person claiming their `/for/{token}`
         * link. Two saved people pointing at one friend is possible (made
         * twice); the friend joins the oldest, and the other stays a row of its
         * own, so nothing the owner wrote disappears from the page.
         */
        $friendsById = $connections->keyBy('friend_id');
        $claimed = [];
        $rows = [];

        $occasions = $this->upcomingOccasions($user, $saved, $today);
        $together = TasteInvite::query()
            ->whereIn('recipient_id', $saved->modelKeys())
            ->whereNull('revoked_at')
            ->pluck('recipient_id')
            ->flip();
        $theirLists = $this->listsAbout($user, $saved);

        foreach ($saved as $person) {
            $friendship = null;

            if ($person->user_id !== null && isset($friendsById[$person->user_id]) && ! isset($claimed[$person->user_id])) {
                $friendship = $friendsById[$person->user_id];
                $claimed[$person->user_id] = true;
            }

            $friend = $friendship === null ? null : $this->friendPart($friendship, $friendLists, $inCommon, $theySee, $today, $current);

            // The date you saved for them comes first: it is what the reminder
            // email reads. Theirs, when they share it, fills a gap.
            $birthday = DayAndMonth::fromDate($person->birthday)
                ?? ($friendship === null ? null : $this->birthdayFor($friendship));

            $rows[] = [
                'key' => 'p:'.$person->id,
                'name' => $person->name,
                'relationship' => $this->relationshipLabel($person->relationship),
                'personId' => $person->id,
                'birthday' => $birthday?->toString(),
                'next' => $this->nearest([
                    $this->birthdayNext($birthday, $today),
                    $occasions[$person->id] ?? null,
                    $friend['nextOccasion'] ?? null,
                ], $today),
                'friend' => $friend === null ? null : array_diff_key($friend, ['nextOccasion' => true]),
                'known' => $this->known($person),
                // Lists you are making for them (a gift list or a group gift
                // about them), counted for the line under the name.
                'listsForThem' => count($theirLists[$person->id] ?? []),
                /*
                 * "Nodig uit op GiftCoves" in the Meer menu (2026-09-27):
                 * offered while no account is behind this saved person. The
                 * invitation names them, so the connection lands on this row
                 * instead of a second one (FriendInvites).
                 */
                'invitable' => $person->user_id === null,
                'urls' => [
                    'person' => $current->url("people/{$person->id}"),
                    // The wizard with them chosen, every way open (2026-10-05).
                    'finder' => $current->url('gift').'?person='.$person->id,
                    'taste' => $current->url('gift/taste').'?person='.$person->id,
                    // "Vraag" (owner, 2026-09-27): the Ask others form, opened
                    // and filled in with what we know about them. AskPrefill
                    // never carries a name or a note onto the public board.
                    'ask' => $current->url('ask').'?person='.$person->id,
                    // "This or that together" runs on the list about them; the
                    // link is offered only while one is open.
                    'together' => isset($together[$person->id]) && isset($theirLists[$person->id])
                        ? $current->url('lists/'.end($theirLists[$person->id]))
                        : null,
                ],
            ];
        }

        foreach ($connections as $friendship) {
            if (isset($claimed[$friendship->friend_id])) {
                continue;
            }

            $friend = $this->friendPart($friendship, $friendLists, $inCommon, $theySee, $today, $current);
            $birthday = $this->birthdayFor($friendship);

            $rows[] = [
                'key' => 'f:'.$friendship->friend_id,
                'name' => $friendship->friend->displayName(),
                'relationship' => null,
                'personId' => null,
                'birthday' => $birthday?->toString(),
                'next' => $this->nearest([
                    $this->birthdayNext($birthday, $today),
                    $friend['nextOccasion'],
                ], $today),
                'friend' => array_diff_key($friend, ['nextOccasion' => true]),
                // Nothing of yours about somebody you have not saved.
                'known' => null,
                'listsForThem' => 0,
                // Already on GiftCoves.
                'invitable' => false,
                'urls' => ['person' => null, 'finder' => null, 'taste' => null, 'ask' => null, 'together' => null],
            ];
        }

        usort($rows, function (array $a, array $b): int {
            $dateA = $a['next']['date'] ?? null;
            $dateB = $b['next']['date'] ?? null;

            // Somebody with a date before somebody without one.
            if ($dateA !== $dateB) {
                if ($dateA === null) {
                    return 1;
                }

                if ($dateB === null) {
                    return -1;
                }

                return strcmp($dateA, $dateB);
            }

            // Folded, so "émile" sorts beside "Emma" rather than after "Zoë".
            return strcmp(Str::lower(Str::ascii($a['name'])), Str::lower(Str::ascii($b['name'])));
        });

        return $rows;
    }

    /**
     * Your own side: the birthday you publish, and whether friends see it.
     *
     * @return array{birthday: string|null, friendsSeeBirthday: bool}
     */
    public function settings(User $user): array
    {
        return [
            // `MM-DD`: the page asks day and month only (2026-09-27), and a
            // year somebody gave earlier is kept in the column, not shown.
            'birthday' => DayAndMonth::fromDate($user->birthday)?->toString(),
            'friendsSeeBirthday' => (bool) $user->friends_see_birthday,
        ];
    }

    /**
     * The friend's half of a row.
     *
     * @param  Collection<int, Collection<int, Wishlist>>  $friendLists
     * @param  array<int, array{lists: list<mixed>, groups: list<mixed>, santa: list<array{id: string, title: string, date: string|null, url: string}>}>  $inCommon
     * @param  callable(int): list<array{title: string, url: string}>  $theySee
     * @return array<string, mixed>
     */
    private function friendPart(Friendship $friendship, Collection $friendLists, array $inCommon, callable $theySee, CarbonImmutable $today, CurrentMarket $current): array
    {
        /*
         * Their own wish lists only. A list they are making for somebody else
         * sat here until 2026-09-27 and read as if they wanted what was on
         * their grandfather's list; it is counted under "Samen met" now
         * (InCommon). Its date is not their occasion either.
         *
         * @var Collection<int, Wishlist> $lists
         */
        $lists = ($friendLists[$friendship->friend_id] ?? collect())
            ->filter(fn (Wishlist $list) => $list->kind === ListKind::Mine);

        $together = $inCommon[$friendship->friend_id] ?? ['lists' => [], 'groups' => [], 'santa' => []];

        $upcoming = $lists
            ->filter(fn (Wishlist $list) => $list->event_date !== null && $list->event_date->greaterThanOrEqualTo($today))
            ->sortBy(fn (Wishlist $list) => $list->event_date->toDateString())
            ->first();

        return [
            'id' => $friendship->friend_id,
            'birthday' => $this->birthdayFor($friendship)?->toString(),
            /*
             * Lets the page label a date you typed as your own note and offer
             * to change it. Without it a guess reads back as a fact they
             * published.
             */
            'birthdayIsMine' => ! $this->publishesBirthday($friendship)
                && $friendship->friend_birthday_day !== null,
            // What they share with you. The share link, the only page of
            // theirs a visitor may open.
            'lists' => $lists->map(fn (Wishlist $list) => [
                'title' => $list->displayTitle(),
                // For the list-name style in text (ListName): its kind's icon.
                'kind' => $list->kind->value,
                'url' => $current->url("l/{$list->share_token}"),
            ])->values()->all(),
            // What you share with them.
            'theySee' => $theySee($friendship->friend_id),
            // "Samen met": their lists for others that reached you, group
            // gifts and Secret Santas you are both in. A count here; the
            // person's page lists them.
            'inCommon' => count($together['lists']) + count($together['groups']) + count($together['santa']),
            // The mark beside "op GiftCoves" (owner, 2026-09-27): the next
            // Secret Santa you are both in. Membership only, never the draw.
            'santa' => $this->inCommon->nextSanta($together['santa'], $today),
            'nextOccasion' => $upcoming === null ? null : [
                'date' => $upcoming->event_date->toDateString(),
                'kind' => 'occasion',
                'title' => $upcoming->displayTitle(),
            ],
        ];
    }

    /**
     * Their lists: the ones they shared with you, sent you the link to, or
     * show to all their people.
     *
     * The first two are the query the friends page ran: both ways in are acts
     * by the owner, and `visibility != private` stays in front of them, so a
     * list whose sharing was turned off leaves this page as its link stops
     * working. The third is a wish list with "visible to my people" on
     * (docs/features/wish-list-for-my-people.md), which needs no link: it
     * leaves this page when they switch it off or remove you as a friend.
     *
     * Public because a person's page (PersonProfile) shows the same lists
     * for one friend, and a second copy of this query is how the two pages
     * would come to disagree about what a friend shared.
     *
     * @param  Collection<int, Friendship>  $connections
     * @return Collection<int, Collection<int, Wishlist>>
     */
    public function sharedWith(User $user, Collection $connections): Collection
    {
        return Wishlist::query()
            ->whereIn('owner_user_id', $connections->pluck('friend_id'))
            ->where(fn ($either) => $either
                ->where(fn ($given) => $given
                    ->where('visibility', '!=', ListVisibility::Private->value)
                    ->where(fn ($q) => $q
                        ->whereHas('shares', fn ($share) => $share->where('user_id', $user->id))
                        ->orWhereHas('opens', fn ($open) => $open->where('user_id', $user->id))))
                ->orWhere(fn ($shown) => $shown->visibleToFriend($user)))
            // Never a list they are making about you, even one whose link
            // reached you: it is your own surprise.
            ->notAbout($user)
            ->with('recipient:id,name')
            ->get()
            ->groupBy('owner_user_id');
    }

    /**
     * What each friend sees of yours: lists you shared with them, or whose
     * link they opened. Two lookups indexed by list, not a query per friend.
     *
     * @return callable(int): list<array{title: string, url: string}>
     */
    private function seenBy(User $user, CurrentMarket $current): callable
    {
        // Shared by link, or a wish list shown to all your people.
        $mine = Wishlist::query()
            ->where('owner_user_id', $user->id)
            ->where(fn ($q) => $q
                ->where('visibility', '!=', ListVisibility::Private->value)
                ->orWhere(fn ($shown) => $shown
                    ->where('kind', ListKind::Mine->value)
                    ->where('visible_to_friends', true)))
            ->latest()
            ->get();

        $reachedBy = WishlistShare::query()
            ->whereIn('wishlist_id', $mine->modelKeys())
            ->get()
            ->groupBy('wishlist_id')
            ->map(fn ($shares) => $shares->pluck('user_id')->all());

        $openedBy = ListOpen::query()
            ->whereIn('wishlist_id', $mine->modelKeys())
            ->whereNotNull('user_id')
            ->get()
            ->groupBy('wishlist_id')
            ->map(fn ($opens) => $opens->pluck('user_id')->all());

        return fn (int $friendId): array => $mine
            // Every friend sees a list shown to all your people; the other two
            // routes count only while the list is shared by link.
            ->filter(fn (Wishlist $list) => $list->isVisibleToFriends()
                || ($list->visibility !== ListVisibility::Private && (
                    in_array($friendId, $reachedBy[$list->id] ?? [], true)
                    || in_array($friendId, $openedBy[$list->id] ?? [], true))))
            // Your own page, where you can edit it; the share link is for them.
            ->map(fn (Wishlist $list) => [
                'title' => $list->displayTitle(),
                'url' => $current->url("lists/{$list->id}"),
            ])
            ->values()
            ->all();
    }

    /**
     * The nearest dated list you made about each saved person.
     *
     * @param  Collection<int, Recipient>  $saved
     * @return array<string, array{date: string, kind: string, title: string}>
     */
    private function upcomingOccasions(User $user, Collection $saved, CarbonImmutable $today): array
    {
        $out = [];

        $lists = Wishlist::query()
            ->where('owner_user_id', $user->id)
            ->whereIn('recipient_id', $saved->modelKeys())
            ->whereNotNull('event_date')
            ->whereDate('event_date', '>=', $today->toDateString())
            ->orderBy('event_date')
            ->get();

        foreach ($lists as $list) {
            $out[$list->recipient_id] ??= [
                'date' => $list->event_date->toDateString(),
                'kind' => 'occasion',
                'title' => $list->displayTitle(),
            ];
        }

        return $out;
    }

    /**
     * The lists you made for each saved person, oldest first, by person id.
     *
     * The last one is the newest, which is where "This or that together"
     * points; the count is the "2 lijsten voor hen" under the name.
     *
     * @param  Collection<int, Recipient>  $saved
     * @return array<string, list<string>>
     */
    private function listsAbout(User $user, Collection $saved): array
    {
        return Wishlist::query()
            ->where('owner_user_id', $user->id)
            ->whereIn('recipient_id', $saved->modelKeys())
            ->whereIn('kind', [ListKind::ForSomeone->value, ListKind::Group->value])
            ->oldest()
            ->get(['id', 'recipient_id', 'created_at'])
            ->groupBy('recipient_id')
            ->map(fn (Collection $lists) => $lists->pluck('id')->all())
            ->all();
    }

    /**
     * What you know about a saved person, for the one line under their name:
     * their interests in the reader's language. No budget since 2026-10-05: it
     * is a list's, not a person's (ListBudget).
     *
     * Interests outside the closed vocabulary (typed by hand, "wielrennen")
     * show as typed.
     *
     * @return array{interests: list<string>}
     */
    private function known(Recipient $person): array
    {
        return [
            'interests' => array_values(array_map(
                fn (string $interest) => Interest::tryFrom($interest)?->label() ?? $interest,
                array_filter((array) $person->interests, fn ($v) => is_string($v) && trim($v) !== ''),
            )),
        ];
    }

    /** @return array{date: string, kind: string, title: null}|null */
    private function birthdayNext(?DayAndMonth $birthday, CarbonImmutable $today): ?array
    {
        return $birthday === null ? null : [
            'date' => $birthday->nextFrom($today)->toDateString(),
            'kind' => 'birthday',
            'title' => null,
        ];
    }

    /**
     * The earliest of the candidates, with how many days away it is.
     *
     * @param  list<array{date: string, kind: string, title: string|null}|null>  $candidates
     * @return array{date: string, kind: string, title: string|null, days: int}|null
     */
    private function nearest(array $candidates, CarbonImmutable $today): ?array
    {
        $candidates = array_values(array_filter($candidates));

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (array $a, array $b) => strcmp($a['date'], $b['date']));

        return [
            ...$candidates[0],
            'days' => (int) $today->diffInDays(CarbonImmutable::parse($candidates[0]['date'])),
        ];
    }

    /**
     * "mother" as "Mama" in the reader's language; anything typed by hand as typed.
     */
    public function relationshipLabel(?string $relationship): ?string
    {
        return RecipientType::describe($relationship);
    }

    /**
     * Theirs if they publish it, otherwise yours if you wrote one down. Day
     * and month either way: a year is their age, and not this page's to show.
     */
    public function birthdayFor(Friendship $friendship): ?DayAndMonth
    {
        return $this->publishesBirthday($friendship)
            ? DayAndMonth::fromDate($friendship->friend->birthday)
            : DayAndMonth::fromColumns($friendship->friend_birthday_day, $friendship->friend_birthday_month);
    }

    private function publishesBirthday(Friendship $friendship): bool
    {
        return $friendship->friend->friends_see_birthday
            && $friendship->friend->birthday !== null;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Wishlist;

use App\Enums\EventType;
use App\Enums\ListKind;
use App\Enums\Market;
use App\Enums\RecipientType;
use App\Models\Friendship;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use App\Services\Social\Friends;
use App\Support\DayAndMonth;
use App\Support\Owner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What the list wizard can offer: the people a list can be for, and the
 * occasions with the date each falls on.
 *
 * Gathered here rather than in a controller because the wizard is now on two
 * pages — the Gift Cove and, since 2026-09-07, behind "New list" on My Lists —
 * and two hand-written copies of "which friends, which profiles, whose
 * birthday" would have drifted the first time one of them was fixed. They
 * already had: My Lists sent a friend list that dropped anybody who had a
 * profile, the bug the Gift Cove had fixed a day earlier.
 */
final class WizardOffer
{
    public function __construct(
        private readonly OccasionDate $dates,
        private readonly Friends $friends,
    ) {}

    /**
     * @return array{
     *     friends: list<array{id: int, name: string, recipientId: string|null, relationship: string|null, birthday: string|null, next: array{date: string, days: int, kind: string, title: null}|null}>,
     *     recipients: list<array{id: string, name: string, relationship: string|null, birthday: string|null, next: array{date: string, days: int, kind: string, title: null}|null}>,
     *     occasions: list<array{value: string, label: string, date: string|null}>,
     *     myLists: list<array{id: string, title: string}>,
     * }
     */
    public function for(Owner $owner, ?User $user, Market $market): array
    {
        /*
         * `$linked` is my people keyed by the account behind them, which makes
         * "does this friend already have a profile" a lookup rather than a
         * query per friend.
         */
        $recipients = $owner->exists()
            ? $owner->scope(Recipient::query())->orderBy('name')->get()
            : collect();
        $friendships = $user === null ? collect() : $this->friends->forUser($user);
        $linked = $recipients->whereNotNull('user_id')->keyBy('user_id');
        $nextBirthday = fn (?DayAndMonth $birthday) => $birthday === null
            ? null
            : $this->dates->for(EventType::Birthday, $market, $birthday)?->toDateString();

        /*
         * The same birthday as the date line on a person's card
         * (`PersonPicker`, consistency review round 3, 2026-09-27): the date
         * and how many days away, in the shape My people sends, so the list
         * wizard draws the card Find a gift draws.
         */
        $today = CarbonImmutable::today();
        $next = fn (?string $date) => $date === null ? null : [
            'date' => $date,
            'days' => (int) $today->diffInDays(CarbonImmutable::parse($date)),
            'kind' => 'birthday',
            'title' => null,
        ];

        return [
            /*
             * **Every** friend, always. Friends who already had a profile with
             * me used to be dropped from this group on the reasoning that they
             * were listed under their own name among my people. So using a
             * friend once removed them from "from your friends" for good, and
             * somebody whose only friend already had a profile saw a heading
             * with nothing under it. They keep one entry either way: a friend
             * who has a profile is offered *as* that profile, so picking them
             * can never make a second one.
             *
             * Birthdays ride along, so the wizard can fill in the date for
             * "Birthday" rather than ask for something it is holding.
             */
            'friends' => $friendships
                ->map(function (Friendship $friendship) use ($linked, $nextBirthday, $next) {
                    $birthday = $nextBirthday($this->birthdayOf($friendship, $linked));

                    return [
                        'id' => $friendship->friend_id,
                        'name' => $friendship->friend->displayName(),
                        'recipientId' => $linked->get($friendship->friend_id)?->id,
                        // What you called them on the profile you keep, if any.
                        'relationship' => RecipientType::describe($linked->get($friendship->friend_id)?->relationship),
                        'birthday' => $birthday,
                        'next' => $next($birthday),
                    ];
                })
                ->values()
                ->all(),

            // The people who are not one of those friends. Somebody with both
            // an account and a profile is one person, and belongs in one list.
            'recipients' => $recipients
                ->reject(fn (Recipient $r) => $r->user_id !== null
                    && $friendships->contains('friend_id', $r->user_id))
                ->map(function (Recipient $r) use ($nextBirthday, $next) {
                    $birthday = $nextBirthday(DayAndMonth::fromDate($r->birthday));

                    return [
                        'id' => $r->id,
                        'name' => $r->name,
                        'relationship' => RecipientType::describe($r->relationship),
                        'birthday' => $birthday,
                        'next' => $next($birthday),
                    ];
                })
                ->values()
                ->all(),

            /*
             * Each occasion with the date it falls on, where that is knowable.
             *
             * Christmas is the 25th, and Mother's Day is a rule this market
             * either keeps or does not; see OccasionDate. Null means the wizard
             * has to ask, which is the honest answer for a wedding. A birthday
             * is null here too and answered by the person instead.
             */
            'occasions' => array_map(
                fn (EventType $type) => [
                    'value' => $type->value,
                    'label' => $type->label(),
                    'date' => $type === EventType::Birthday
                        ? null
                        : $this->dates->for($type, $market)?->toDateString(),
                ],
                EventType::cases(),
            ),

            /*
             * My own lists, about myself, on this market: what a Secret Friend
             * group may be pointed at. The wizard has made groups since
             * 2026-09-12 (its fourth kind), and its last step offers the list
             * whoever draws me will see. Only `mine`: a list about somebody
             * else is research they must never see, and a group list is other
             * people's money. Titled as the owner sees it, in the language of
             * the page.
             */
            'myLists' => $owner->isSignedIn()
                ? $owner->scope(Wishlist::query())
                    ->where('market', $market->value)
                    ->where('kind', ListKind::Mine->value)
                    ->orderBy('created_at')
                    ->get()
                    ->map(fn (Wishlist $list) => [
                        'id' => $list->id,
                        'title' => $list->displayTitle($market->language()),
                    ])
                    ->values()
                    ->all()
                : [],
        ];
    }

    /**
     * A friend's birthday, from whichever place it is known.
     *
     * Their own account first, if they let friends see it; then what I wrote
     * on the friendship; then the profile I keep for them.
     */
    private function birthdayOf(Friendship $friendship, Collection $linked): ?DayAndMonth
    {
        $friend = $friendship->friend;

        if ($friend?->friends_see_birthday === true && $friend->birthday !== null) {
            return DayAndMonth::fromDate($friend->birthday);
        }

        return DayAndMonth::fromColumns(
            $friendship->friend_birthday_day,
            $friendship->friend_birthday_month,
        ) ?? DayAndMonth::fromDate($linked->get($friendship->friend_id)?->birthday);
    }
}

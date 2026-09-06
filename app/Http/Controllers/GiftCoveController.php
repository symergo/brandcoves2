<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EventType;
use App\Enums\ListKind;
use App\Models\Friendship;
use App\Models\Recipient;
use App\Models\SecretSantaGroup;
use App\Models\SecretSantaMember;
use App\Models\Wishlist;
use App\Services\Seo\PageMeta;
use App\Services\Social\Friends;
use App\Services\Wishlist\DefaultList;
use App\Services\Wishlist\OccasionDate;
use App\Support\CurrentMarket;
use App\Support\DayAndMonth;
use App\Support\Owner;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Gift Cove: one place that explains every gifting tool and shows what you
 * already have.
 *
 * These features accumulated one at a time and were each reachable from
 * somewhere different — the wizard from the nav, Secret Santa from its own
 * page, lists from the header, the quiz from inside a list, handover and
 * registries from nowhere at all. Individually discoverable, collectively
 * invisible: nobody could see that they were parts of one thing.
 *
 * Deliberately **explains as well as links**. "Secret Santa" is self-evident;
 * "a list you build for somebody and then hand to them" is not, and a tool
 * nobody understands is a tool nobody opens.
 *
 * Public, not auth-gated: somebody has to be able to read what this offers
 * before deciding to sign up. Counts simply come back as zero.
 */
class GiftCoveController extends Controller
{
    public function __invoke(Request $request, CurrentMarket $current): Response
    {
        app(PageMeta::class)->set(
            title: __('site.gift_cove.seo_title'),
            description: __('site.gift_cove.seo_description'),
            canonical: url($current->url('gift-cove')),
        );

        $owner = Owner::fromRequest($request);
        $user = $request->user();

        // Everyone gets "My wishlist", created here on the first visit rather
        // than on the first save — a page describing lists should not describe
        // one you do not have yet.
        if ($owner->isSignedIn()) {
            app(DefaultList::class)->for($owner, $current);
        }

        /*
         * The people and dates the wizard offers, gathered once.
         *
         * `$linked` is my people keyed by the account behind them, which makes
         * "does this friend already have a profile" a lookup rather than a
         * query per friend.
         */
        $dates = app(OccasionDate::class);
        $recipients = $owner->exists()
            ? $owner->scope(Recipient::query())->orderBy('name')->get()
            : collect();
        $friendships = $user === null ? collect() : app(Friends::class)->forUser($user);
        $linked = $recipients->whereNotNull('user_id')->keyBy('user_id');
        $nextBirthday = fn (?DayAndMonth $birthday) => $birthday === null
            ? null
            : $dates->for(EventType::Birthday, $current->get(), $birthday)?->toDateString();

        $lists = $owner->exists()
            ? $owner->scope(Wishlist::query())
                ->where('market', $current->value())
                ->withCount('items')
                ->get()
            : collect();

        /*
         * Wishlists, plural.
         *
         * There is still exactly one *default* — the list a one-tap save lands
         * in, so "where did my save go?" keeps its single answer — but that was
         * being rendered as though it were the only list a person may keep for
         * themselves. It never was: the picker and the create form have always
         * made more, and this page then showed one of them and hid the rest,
         * which reads as a limit rather than as an omission.
         *
         * Occasions are the reason it matters. A registry is an ordinary wish
         * list with a date on it, so "my wedding" and "things I want" are two
         * lists for me, not one list I have to choose between.
         *
         * Default first, then most recently touched: the one a save lands in
         * without being asked about is the one that has to be at the top.
         */
        $wishlists = $lists
            ->where('kind', ListKind::Mine)
            ->sortByDesc(fn (Wishlist $list) => $list->updated_at?->getTimestamp() ?? 0)
            // Two passes rather than one composite key: PHP's sorts are
            // stable, so the recency order survives inside each half.
            ->sortByDesc(fn (Wishlist $list) => $list->is_default ? 1 : 0)
            ->values()
            ->map(fn (Wishlist $list) => [
                'id' => $list->id,
                'title' => $list->displayTitle(),
                'items' => $list->items_count,
                'shared' => $list->visibility?->isShareable() ?? false,
                'isDefault' => (bool) $list->is_default,

                /*
                 * The occasion, where there is one. This is the whole visible
                 * difference between two wish lists of mine, and without it a
                 * registry for a wedding and a running list of things I want
                 * are two cards with two titles and nothing to tell them apart.
                 */
                'occasion' => $list->event_type?->label(),
                'occasionDate' => $list->event_date?->toDateString(),
                'url' => $current->url("lists/{$list->id}"),
            ])
            ->all();

        return Inertia::render('GiftCove', [
            'signedIn' => $owner->isSignedIn(),

            'wishlists' => $wishlists,

            'counts' => [
                'giftLists' => $lists->where('kind', ListKind::ForSomeone)->count(),

                /*
                 * Group lists were creatable and uncounted.
                 *
                 * `giftLists` is `ForSomeone` alone, so a page whose whole job
                 * is showing what you already have could not show that a group
                 * gift existed — the one kind of list that is useless until
                 * other people are on it, and therefore the one most worth
                 * being reminded of.
                 */
                'groupLists' => $lists->where('kind', ListKind::Group)->count(),
                'people' => $owner->exists() ? $owner->scope(Recipient::query())->count() : 0,
                /*
                 * Registries proper, not every list with an occasion.
                 *
                 * This counted `event_type` alone, which was the same thing
                 * while only a wish list could carry one. Now that any kind
                 * can, an unrestricted count would badge the *registry* tool
                 * with the number of gift lists you have put a birthday on —
                 * and a registry is specifically your own list, with a date and
                 * an address, that people post things to.
                 */
                'registries' => $lists
                    ->whereNotNull('event_type')
                    ->where('kind', ListKind::Mine)
                    ->count(),
                'santa' => $user === null
                    ? 0
                    : SecretSantaMember::query()->where('user_id', $user->id)->count(),
                // One indexed count; `friendships` is keyed on `user_id`.
                'friends' => $user === null
                    ? 0
                    : Friendship::query()->where('user_id', $user->id)->count(),
                'suggestions' => $owner->exists()
                    ? Wishlist::query()
                        ->whereIn('id', $lists->pluck('id'))
                        ->withCount('suggestions')
                        ->get()
                        ->sum('suggestions_count')
                    : 0,
            ],

            /*
             * Who the wizard can make a list for, and who it can share with.
             *
             * **Every** friend, always, and that is the fix to a picker that
             * emptied itself: friends who already had a profile with me were
             * dropped from the group on the reasoning that they were listed
             * under their own name among my people. So using a friend once
             * removed them from "from your friends" for good, and somebody
             * whose only friend already had a profile saw a heading with
             * nothing under it. They keep one entry either way: a friend who
             * has a profile is offered *as* that profile, so picking them can
             * never make a second one.
             *
             * Birthdays ride along, so the wizard can fill in the date for
             * "Birthday" rather than ask for something it is holding.
             */
            'friends' => $friendships
                ->map(fn (Friendship $friendship) => [
                    'id' => $friendship->friend_id,
                    'name' => $friendship->friend->displayName(),
                    'recipientId' => $linked->get($friendship->friend_id)?->id,
                    'birthday' => $nextBirthday($this->birthdayOf($friendship, $linked)),
                ])
                ->values()
                ->all(),

            // The people who are not one of those friends. Somebody with both
            // an account and a profile is one person, and belongs in one list.
            'recipients' => $recipients
                ->reject(fn (Recipient $r) => $r->user_id !== null
                    && $friendships->contains('friend_id', $r->user_id))
                ->map(fn (Recipient $r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'birthday' => $nextBirthday(DayAndMonth::fromDate($r->birthday)),
                ])
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
                        : $dates->for($type, $current->get())?->toDateString(),
                ],
                EventType::cases(),
            ),

            'santaGroups' => $user === null
                ? []
                : SecretSantaGroup::query()
                    ->where('market', $current->value())
                    ->whereExists(fn ($q) => $q
                        ->selectRaw('1')
                        ->from('secret_santa_members')
                        ->whereColumn('secret_santa_members.group_id', 'secret_santa_groups.id')
                        ->where('secret_santa_members.user_id', $user->id))
                    ->latest()
                    ->limit(5)
                    ->get()
                    ->map(fn (SecretSantaGroup $group) => [
                        'title' => $group->title,
                        'drawn' => $group->status->isDrawn(),
                        'url' => $current->url("santa/{$group->id}"),
                    ])
                    ->all(),

            /*
             * The rest of the site, for the two bands that are not list tools.
             *
             * Built here rather than on the client, so there is one place that
             * knows the route table — the same reason `lists` and `santa` are
             * here. A page that says "here is everything you can do" and then
             * guesses at half the addresses is a page whose links rot.
             */
            'urls' => [
                // "How each one works" is its own page rather than the bottom
                // half of this one; see GiftCoveManualController.
                'manual' => $current->url('gift-cove/how-it-works'),
                'gift' => $current->url('gift'),
                'search' => $current->url('search'),
                'ask' => $current->url('ask'),
                'notifications' => $current->url('notifications'),
                'friends' => $current->url('friends'),
                'daily' => $current->get()->covePath(),
                'guides' => $current->url('guides'),
                'ideas' => $current->url('gift-ideas'),
                'surprise' => $current->url('surprise'),
                'lists' => $current->url('lists'),
                'santa' => $current->url('santa'),
            ],
        ]);
    }

    /**
     * A friend's birthday, from whichever of the three places holds one.
     *
     * What they published wins over my note about them, the same order the
     * Friends page reads them in. The profile I already made for them is the
     * last resort, and carries whatever was true when it was made.
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

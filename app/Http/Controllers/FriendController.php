<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ListVisibility;
use App\Models\Friendship;
use App\Models\ListOpen;
use App\Models\Wishlist;
use App\Models\WishlistShare;
use App\Services\Social\FriendInvites;
use App\Services\Social\Friends;
use App\Support\CurrentMarket;
use App\Support\DayAndMonth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The people you share lists with.
 *
 * ## Why this shows lists and birthdays and not just names
 *
 * A roster of names is a page nobody opens twice. What somebody wants from it
 * is the thing the friendship was made of — where is that registry again, and
 * when is her birthday — so each person is listed with the lists of theirs you
 * hold and the date you are buying for.
 *
 * ## What is deliberately absent
 *
 * Claim state, in every form. Not a count, not a badge, not "2 of 8 spoken
 * for". Invariant #4 is about what a list's owner may learn, and this is the
 * friend's side of it — but a page that started reporting progress here would
 * be one join from telling somebody's friend what their own list must not show
 * them. `Wishlist::items` is not loaded at all, and should stay that way.
 *
 * ## Two birthdays, and they are not the same fact
 *
 * `users.birthday` is what somebody publishes about themselves, shown only if
 * they left `friends_see_birthday` on. `friendships.friend_birthday_day` and
 * `_month` are what you wrote down about them: yours, private to your side of
 * the connection, and used when they have published nothing. A date you guessed
 * must never become their account's answer for everybody else.
 *
 * Only ever a day and a month leaves here, whichever of the two it came from.
 * What a friend needs is when to buy something; a year is somebody's age on a
 * page other people read, and the only person who may put one on this site is
 * its owner, about themselves, on their own settings below.
 */
class FriendController extends Controller
{
    public function index(Request $request, CurrentMarket $current, Friends $friends): Response
    {
        $user = $request->user();
        $connections = $friends->forUser($user);

        /*
         * Their lists: the ones they invited you to.
         *
         * Two ways in, and both are acts by the owner rather than a setting:
         *
         * - **shared with you** — they picked your name in "Share with
         *   friends", which wrote a `wishlist_shares` row and emailed you.
         * - **you opened its link** — they sent it to you and you followed it.
         *   `list_opens` has recorded this since long before friends existed.
         *
         * Nothing reaches somebody who has done neither. There was a
         * `show_to_friends` boolean here for a day meaning "everybody I am
         * connected to"; a friendship is made by opening any share link, so it
         * published to a set the owner had never chosen and could not see. See
         * docs/features/friends.md.
         *
         * This is also why a group gift needs no rule of its own any more. Its
         * audience was always "the people who were sent the link", which is the
         * second case above.
         *
         * `visibility != private` stays in front of both: a list whose sharing
         * was turned off has to disappear from here, for the same reason its
         * token stops working. Neither an invitation nor a friendship is a
         * grant.
         */
        $lists = Wishlist::query()
            ->whereIn('owner_user_id', $connections->pluck('friend_id'))
            ->where('visibility', '!=', ListVisibility::Private->value)
            ->where(fn ($q) => $q
                ->whereHas('shares', fn ($share) => $share->where('user_id', $user->id))
                ->orWhereHas('opens', fn ($open) => $open->where('user_id', $user->id)))
            ->get()
            ->groupBy('owner_user_id');

        /*
         * And the other direction: what each of them sees of *yours*.
         *
         * Genuinely per person now, because sharing is per person. Two lookups
         * — who you shared each list with, and who opened it — fetched once and
         * indexed by list, rather than a query per friend row.
         *
         * "What does Anna actually see of mine" is the question a friend list
         * raises and the one nobody thinks to check; it is answered under her
         * name, where the consequence is concrete.
         */
        $mine = Wishlist::query()
            ->where('owner_user_id', $user->id)
            ->where('visibility', '!=', ListVisibility::Private->value)
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

        $seenBy = fn (int $friendId) => $mine
            ->filter(fn (Wishlist $list) => in_array($friendId, $reachedBy[$list->id] ?? [], true)
                || in_array($friendId, $openedBy[$list->id] ?? [], true))
            ->map(fn (Wishlist $list) => [
                'title' => $list->displayTitle(),
                /*
                 * Your own page, not the share link.
                 *
                 * These are *your* lists, and following one should land you
                 * where you can edit it — the visitor view is what a friend
                 * gets, and it deliberately has none of the owner's controls
                 * on it. Sending an owner there makes their own list look
                 * read-only. Their lists, above, keep the share token, because
                 * that is the only page a visitor may see.
                 */
                'url' => $current->url("lists/{$list->id}"),
            ])
            ->values();

        return Inertia::render('Friends/Index', [
            'friends' => $connections->map(fn (Friendship $friendship) => [
                'id' => $friendship->friend_id,
                // The name if they gave one, otherwise the part of the address
                // before the @ — the same fallback the account menu uses, and
                // never the address itself.
                'name' => $friendship->friend->displayName(),
                'since' => $friendship->created_at?->toDateString(),

                /*
                 * `MM-DD`, or nothing. `birthdayIsMine` is what lets the page
                 * label a date you typed as your own note and offer to change
                 * it — without it, a guess would read back to you as a fact
                 * they published.
                 */
                'birthday' => $this->birthdayFor($friendship)?->toString(),
                'birthdayIsMine' => ! $this->publishesBirthday($friendship)
                    && $friendship->friend_birthday_day !== null,

                // What they share with you.
                'lists' => ($lists[$friendship->friend_id] ?? collect())
                    ->map(fn (Wishlist $list) => [
                        'title' => $list->displayTitle(),
                        'url' => $current->url("l/{$list->share_token}"),
                    ])
                    ->values(),

                // And what you share with them: the lists shown to every
                // friend, plus any group gift this person actually opened.
                'theySee' => $seenBy($friendship->friend_id),
            ])->values(),

            /*
             * Your side of the page: what these people see of you.
             *
             * Birthday only. Lists are settled on each list's own settings
             * panel and nowhere else — there was a second copy of that switch
             * here for a day, and two payloads for one setting is how the two
             * came to disagree about whether an untouched list was on or off.
             * Each friend row above still says what they can see of yours,
             * which is the question this page is actually asked.
             */
            'settings' => [
                'birthday' => $user->birthday?->toDateString(),
                'friendsSeeBirthday' => $user->friends_see_birthday,
            ],

        ]);
    }

    /**
     * Add somebody by their address.
     *
     * The response says the same thing whether or not that address has an
     * account here — see {@see FriendInvites} for why that is the point rather
     * than vagueness. Throttled on the route for the same reason.
     */
    public function store(Request $request, FriendInvites $invites): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            /*
             * `MM-DD`, and no year.
             *
             * What you write down about somebody else is when to buy them
             * something. A year would be their age, recorded by a third party
             * who was not asked and may be wrong — and they can give one on
             * their own settings, which is the only place a year belongs.
             */
            'birthday' => ['nullable', 'string', 'regex:/^\d{2}-\d{2}$/'],
        ]);

        $invites->invite(
            $request->user(),
            $validated['email'],
            DayAndMonth::fromString($validated['birthday'] ?? null),
        );

        return back()->with('success', __('site.friends.added'));
    }

    /**
     * Your side of the page: what your friends see of you.
     *
     * The one place a year may be given, and only by the person it belongs to.
     *
     * Lists are deliberately not here, and there is no switch for them
     * anywhere. Which of your lists a friend sees is decided by an act — you
     * shared it with them, or you sent them the link and they opened it — not
     * by a setting. Two settings were tried and both were the wrong shape; see
     * App\Services\Social\ListSharer.
     */
    public function settings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            /*
             * A full date here, unlike everywhere else.
             *
             * `before:today` rather than a range: a birthday in the future is a
             * typo every time, and a lower bound would be a guess at how old
             * somebody's grandmother is allowed to be.
             */
            'birthday' => ['nullable', 'date', 'before:today'],
            'friends_see_birthday' => ['required', 'boolean'],
        ]);

        $request->user()->update($validated);

        return back()->with('success', __('site.friends.settings_saved'));
    }

    /**
     * The date you wrote down about somebody.
     *
     * Written on your own side of the friendship and nowhere else. It is not an
     * edit to their account, and somebody who later publishes their own date
     * takes precedence over it on the page.
     */
    public function note(Request $request, string $market, int $friend): RedirectResponse
    {
        $validated = $request->validate([
            'birthday' => ['nullable', 'string', 'regex:/^\d{2}-\d{2}$/'],
        ]);

        $birthday = DayAndMonth::fromString($validated['birthday'] ?? null);

        Friendship::query()
            ->where('user_id', $request->user()->id)
            ->where('friend_id', $friend)
            ->update([
                'friend_birthday_day' => $birthday?->day,
                'friend_birthday_month' => $birthday?->month,
                'updated_at' => now(),
            ]);

        return back()->with('success', __('site.friends.settings_saved'));
    }

    /**
     * End a connection — for both people at once.
     *
     * See {@see Friends::unlink()} for why it is symmetric. Nothing else is
     * destroyed: lists, claims and anything already shared are untouched, and
     * following the link again re-creates the connection.
     */
    public function destroy(Request $request, string $market, int $friend, Friends $friends): RedirectResponse
    {
        $friends->unlink($request->user(), $friend);

        // Nothing to announce: their row leaves the page. See the note on
        // `SharedListController::claim()` for the rule.
        return back();
    }

    /**
     * Theirs if they publish it, otherwise yours if you wrote one down.
     *
     * Day and month either way. Their own full date is trimmed on the way out
     * rather than on the way in: the year is real and theirs, it is simply not
     * this page's to show.
     */
    private function birthdayFor(Friendship $friendship): ?DayAndMonth
    {
        return $this->publishesBirthday($friendship)
            ? DayAndMonth::fromDate($friendship->friend->birthday)
            : DayAndMonth::fromColumns(
                $friendship->friend_birthday_day,
                $friendship->friend_birthday_month,
            );
    }

    /** Has this friend published a birthday, and left it visible? */
    private function publishesBirthday(Friendship $friendship): bool
    {
        return $friendship->friend->friends_see_birthday
            && $friendship->friend->birthday !== null;
    }
}

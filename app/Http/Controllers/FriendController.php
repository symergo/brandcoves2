<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\InviteOutcome;
use App\Models\Friendship;
use App\Models\Recipient;
use App\Services\Social\FriendInvites;
use App\Services\Social\Friends;
use App\Support\CurrentMarket;
use App\Support\DayAndMonth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The people you share lists with: adding, noting a birthday, removing.
 *
 * ## The page moved
 *
 * Friends had a page of their own at `/friends` until 2026-09-26, beside a
 * separate idea of "saved people" that had no page at all. The owner asked what
 * the difference was, and a visitor needs none, so both are one list now:
 * "My people" at `/people` ({@see PeopleController}, built by
 * App\Services\Social\MyPeople, which also carries this page's rules on which
 * lists and which birthday a friend row shows). `/friends` redirects there,
 * because emails, help pages and bookmarks carry it. The actions below stayed
 * where they were, for the same reason, and all of them answer with `back()`.
 *
 * ## What is deliberately absent
 *
 * Claim state, in every form. Not a count, not a badge, not "2 of 8 spoken
 * for". Invariant #4 is about what a list's owner may learn, and this is the
 * friend's side of it.
 *
 * ## Two birthdays, and they are not the same fact
 *
 * `users.birthday` is what somebody publishes about themselves, shown only if
 * they left `friends_see_birthday` on. `friendships.friend_birthday_day` and
 * `_month` are what you wrote down about them: yours, private to your side of
 * the connection, and used when they have published nothing. A date you guessed
 * must never become their account's answer for everybody else. Only a day and
 * a month ever leaves, whichever of the two it came from.
 */
class FriendController extends Controller
{
    /** The old address of the list, kept working. See the class comment. */
    public function index(CurrentMarket $current): RedirectResponse
    {
        return redirect()->to($current->url('people'), 301);
    }

    /**
     * Add somebody by their address.
     *
     * The response says the same thing whether or not that address has an
     * account here — see {@see FriendInvites} for why that is the point rather
     * than vagueness. Throttled on the route for the same reason. Since
     * 2026-09-26 it also emails the address, in the language of the market the
     * member is on (the only guess we have at the other person's).
     */
    public function store(Request $request, FriendInvites $invites, CurrentMarket $current): RedirectResponse
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
            // "Nodig uit op GiftCoves" on a saved person (2026-09-27): the
            // person to link once the invitation becomes a connection.
            'recipient_id' => ['nullable', 'uuid'],
        ]);

        /*
         * Yours, or nothing happens at all.
         *
         * Looked up with the owner in the query, so an id that belongs to
         * somebody else is a 404 before anything is recorded or emailed: a
         * crafted request cannot touch another member's saved person. One that
         * is yours but already linked (another tab, or they claimed their own
         * link) is passed on and ignored by FriendInvites::mayLink(), and the
         * invitation goes as it would from the plain form.
         */
        $person = null;

        if (($validated['recipient_id'] ?? null) !== null) {
            $person = Recipient::query()
                ->where('owner_user_id', $request->user()->id)
                ->findOrFail($validated['recipient_id']);
        }

        $outcome = $invites->invite(
            $request->user(),
            $validated['email'],
            DayAndMonth::fromString($validated['birthday'] ?? null),
            $current->get(),
            $person,
        );

        // Each answer is about the member's own actions; see InviteOutcome.
        return match ($outcome) {
            InviteOutcome::Sent => back()->with('success', __('site.friends.added')),
            InviteOutcome::AlreadyInvited => back()->with('success', __('site.people.invite_again', [
                'days' => (int) config('giftcoves.invites.repeat_days', 30),
            ])),
            InviteOutcome::DailyLimit => back()->withErrors(['email' => __('site.people.invite_limit', [
                'count' => (int) config('giftcoves.invites.daily_limit', 20),
            ])]),
            InviteOutcome::OwnAddress => back()->withErrors(['email' => __('site.people.invite_self')]),
        };
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
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\InviteOutcome;
use App\Enums\ListKind;
use App\Enums\RecipientStatus;
use App\Enums\TasteSource;
use App\Http\Requests\RecipientTasteRequest;
use App\Models\Friendship;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use App\Services\Social\FriendInvites;
use App\Services\Social\InviteRefused;
use App\Support\CurrentMarket;
use App\Support\DayAndMonth;
use App\Support\Owner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * People you might buy for.
 *
 * A recipient can be created with little more than a name — the wizard fills in
 * the rest, which is where asking about someone's interests actually makes
 * sense. But the fields must all be *writable* from here, because for a long
 * time they were not: only name, relationship, occasion and birthday could be
 * set, while the engine reads interests, preferences, avoid and budget. The
 * "use what we know about Mum" shortcut therefore restored an empty brief every
 * time, and looked for all the world like a working feature.
 */
class RecipientController extends Controller
{
    public function store(RecipientTasteRequest $request, CurrentMarket $current, FriendInvites $invites): RedirectResponse
    {
        $owner = Owner::fromRequest($request);
        abort_unless($owner->exists(), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            /*
             * "Toevoegen en uitnodigen" (owner, 2026-10-25): one form on Mijn
             * mensen adds somebody and, when an address is given and that
             * button pressed, invites them too, linked to the person just
             * saved, as "Nodig uit" does from their own page. The address is
             * never stored on the person; it goes to FriendInvites only.
             */
            'email' => ['nullable', 'required_if_accepted:invite', 'email', 'max:255'],
            'invite' => ['sometimes', 'boolean'],
            /*
             * "This person is one of my friends."
             *
             * Optional, and it does something quite different from typing the
             * same name: it links the profile to a real account, so what *they*
             * say about their own taste outranks what you guessed. That is the
             * same `user_id` the "this is me" link sets when somebody claims a
             * profile you made for them — see `RecipientProfileController` —
             * and reaching it from a name you already have saves the round trip
             * through a token entirely.
             */
            'friend_id' => ['nullable', 'integer'],
        ]);

        /*
         * Only an actual friend, checked here rather than trusted.
         *
         * The picker offers nobody else, so an id that is not one arrived by
         * hand — and it is dropped in silence, because a validation error here
         * would answer "is this person your friend" to whoever asked. The
         * profile is still created, under the name they typed.
         */
        $friend = $this->friendOf($owner, $validated['friend_id'] ?? null);

        // Inviting is between two accounts, so a guest cannot; the page only
        // offers the button to somebody signed in.
        $inviting = $request->boolean('invite');
        abort_if($inviting && $owner->user === null, 403);

        /*
         * The person and the invitation together, or neither.
         *
         * A refused invitation (your own address, today's limit) writes and
         * sends nothing (FriendInvites::invite() returns before both), so
         * rolling the person back leaves the form as it was. Keeping the
         * person would make pressing the button again save them twice.
         */
        try {
            $outcome = $this->saveAndInvite($owner, $request, $friend, $inviting ? (string) $validated['email'] : null, $invites, $current);
        } catch (InviteRefused $refused) {
            $outcome = $refused->outcome;
        }

        // Each answer is about the member's own actions, worded as on the
        // plain invite form (FriendController::store).
        return match ($outcome) {
            null => back()->with('success', __('site.lists.recipient_added')),
            InviteOutcome::Sent => back()->with('success', __('site.people.added_and_invited')),
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
     * Save the person and, given an address, invite them, in one transaction.
     * A refused invitation throws, which rolls the person back.
     */
    private function saveAndInvite(
        Owner $owner,
        RecipientTasteRequest $request,
        ?User $friend,
        ?string $email,
        FriendInvites $invites,
        CurrentMarket $current,
    ): ?InviteOutcome {
        return DB::transaction(function () use ($owner, $request, $friend, $email, $invites, $current): ?InviteOutcome {
            $recipient = Recipient::create([
                ...$owner->attributes(),
                ...$request->context(),
                ...$friend === null ? [] : [
                    'user_id' => $friend->id,
                    // Linked, not a stub: there is a person behind this one, and
                    // `RecipientStatus` is what the taste engine reads to know
                    // whose answers to prefer.
                    'status' => RecipientStatus::Linked,
                ],
            ]);

            // Anything the creator already knows is a guess, however confident.
            $recipient->describeTaste($request->taste(), TasteSource::Suggested);

            if ($email === null) {
                return null;
            }

            $outcome = $invites->invite(
                $owner->user,
                $email,
                DayAndMonth::fromDate($recipient->birthday),
                $current->get(),
                $recipient,
            );

            if (in_array($outcome, [InviteOutcome::OwnAddress, InviteOutcome::DailyLimit], true)) {
                throw new InviteRefused($outcome);
            }

            return $outcome;
        });
    }

    /**
     * That id, if it belongs to somebody this person is actually connected to.
     *
     * Reads `friendships` rather than the form. Null for an anonymous owner,
     * who has no friends to pick from — a friendship is between two accounts.
     */
    private function friendOf(Owner $owner, ?int $friendId): ?User
    {
        if ($friendId === null || $owner->user === null) {
            return null;
        }

        $connected = Friendship::query()
            ->where('user_id', $owner->user->id)
            ->where('friend_id', $friendId)
            ->exists();

        return $connected ? User::query()->find($friendId) : null;
    }

    public function update(RecipientTasteRequest $request, CurrentMarket $current, string $market, string $recipient): RedirectResponse
    {
        $model = $this->findOwned($request, $recipient);

        $model->update($request->context());

        /*
         * Silently ignored once the person has answered for themselves, rather
         * than rejected. The owner is not doing anything wrong by having an
         * older opinion — it is simply no longer the best evidence, and an
         * error message here would be scolding them for it.
         */
        $model->describeTaste($request->taste(), TasteSource::Suggested);

        return back();
    }

    public function destroy(Request $request, CurrentMarket $current, string $market, string $recipient): RedirectResponse
    {
        $person = $this->findOwned($request, $recipient);

        /*
         * Not while a group gift is about them. The foreign key would set the
         * group list's `recipient_id` to null, and a group list must name who
         * it is for (CHECK wishlists_group_has_recipient), so Postgres refuses
         * the delete and the visitor got a server error. Said in words instead;
         * the person's page says it before the button is pressed.
         */
        if (Wishlist::query()->where('recipient_id', $person->id)->where('kind', ListKind::Group->value)->exists()) {
            return back()->withErrors(['person' => __('site.people.delete_has_group', ['name' => $person->name])]);
        }

        // Lists survive: the foreign key nulls out rather than cascading, so
        // deleting a person never destroys the gift research done for them.
        // Their gift history and This or that links go with them (cascade).
        $person->delete();

        /*
         * From the person's own page there is no "back" to go to: it is the
         * page that was just deleted, and following the redirect there would
         * be a 404. `then=people` lands on My people instead.
         */
        if ($request->input('then') === 'people') {
            return redirect($current->url('people'))->with('success', __('site.lists.recipient_removed'));
        }

        return back()->with('success', __('site.lists.recipient_removed'));
    }

    private function findOwned(Request $request, string $id): Recipient
    {
        $recipient = Owner::fromRequest($request)
            ->scope(Recipient::query())
            ->find($id);

        if ($recipient === null) {
            throw new NotFoundHttpException;
        }

        return $recipient;
    }
}

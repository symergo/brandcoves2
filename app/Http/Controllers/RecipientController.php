<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\RecipientStatus;
use App\Enums\TasteSource;
use App\Http\Requests\RecipientTasteRequest;
use App\Models\Friendship;
use App\Models\Recipient;
use App\Models\User;
use App\Support\CurrentMarket;
use App\Support\Owner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * People you might buy for.
 *
 * A recipient can be created with little more than a name — the wizard fills in
 * the rest, which is where asking about someone's interests actually makes
 * sense. But the fields must all be *writable* from here, because for a long
 * time they were not: only name, relationship, occasion and birthday could be
 * set, while the engine reads interests, vibe, values, avoid and budget. The
 * "use what we know about Mum" shortcut therefore restored an empty brief every
 * time, and looked for all the world like a working feature.
 */
class RecipientController extends Controller
{
    public function store(RecipientTasteRequest $request, CurrentMarket $current): RedirectResponse
    {
        $owner = Owner::fromRequest($request);
        abort_unless($owner->exists(), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
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

        return back()->with('success', __('site.lists.recipient_added'));
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
        // Lists survive: the foreign key nulls out rather than cascading, so
        // deleting a person never destroys the gift research done for them.
        $this->findOwned($request, $recipient)->delete();

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

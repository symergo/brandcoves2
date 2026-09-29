<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Models\Friendship;
use App\Models\Recipient;
use App\Models\User;
use App\Models\UserTaste;

/**
 * "Mijn smaak": the taste a person keeps about themselves, and the one rule
 * for when somebody else's search may use it (docs/features/my-taste.md).
 *
 * ## Who may use it
 *
 * Only a friend: somebody whose saved person is linked to this account
 * ("Dit ben ik", or a friend saved from the people cards) *and* who is this
 * person's friend on GiftCoves. The link alone is not enough, because a link
 * outlives an unfriending; the friendship is checked at every read.
 *
 * ## Whose word counts
 *
 * What a person says about themselves outranks what a giver noted about them,
 * the same order `TasteSource::Self` has over `Suggested` on a saved person.
 * So each part of the own taste that says something replaces the giver's;
 * a part left empty leaves the giver's as it was. What to avoid is the one
 * exception: both lists count, because leaving something out that either of
 * them named costs nothing, and giving it costs a gift.
 *
 * There is no budget in it (owner, 2026-09-29): what to spend is the giver's.
 */
final class OwnTaste
{
    public function of(User $user): ?UserTaste
    {
        $taste = UserTaste::query()->find($user->id);

        return $taste === null || $taste->isEmpty() ? null : $taste;
    }

    /** The own taste behind a saved person, when its owner may use it. */
    public function sharedWith(Recipient $recipient): ?UserTaste
    {
        if ($recipient->user_id === null || $recipient->owner_user_id === null || ! $recipient->isLinked()) {
            return null;
        }

        $friends = Friendship::query()
            ->where('user_id', $recipient->owner_user_id)
            ->where('friend_id', $recipient->user_id)
            ->exists();

        if (! $friends) {
            return null;
        }

        $taste = UserTaste::query()->find($recipient->user_id);

        return $taste === null || $taste->isEmpty() ? null : $taste;
    }

    /**
     * The giver's picture of somebody with their own word laid over it.
     *
     * @param  array{interests?: list<string>, preferences?: list<string>, avoid?: list<string>, age_band?: string|null}  $fields
     * @return array{interests: list<string>, preferences: list<string>, avoid: list<string>, age_band: string|null}
     */
    public static function overlay(array $fields, UserTaste $own): array
    {
        $pick = fn (array $mine, array $theirs): array => $mine !== [] ? array_values($mine) : array_values($theirs);

        return [
            'interests' => $pick((array) $own->interests, (array) ($fields['interests'] ?? [])),
            'preferences' => $pick((array) $own->preferences, (array) ($fields['preferences'] ?? [])),
            'avoid' => array_values(array_unique([...(array) ($fields['avoid'] ?? []), ...(array) $own->avoid])),
            'age_band' => $own->age_band ?? ($fields['age_band'] ?? null),
        ];
    }
}

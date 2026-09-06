<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Models\FriendInvite;
use App\Models\Friendship;
use App\Models\User;
use App\Support\DayAndMonth;

/**
 * Adding somebody by their email address.
 *
 * ## The thing this is careful about
 *
 * "Add a friend by email" wants to answer *did that work* — and the honest
 * answer is either "yes, they have an account" or "no, they do not", which
 * together make this endpoint a way to test whether any address you like has an
 * account on a site that holds people's wish lists. Somebody could walk a
 * mailing list through it and learn who shops here. That is worth more to an
 * abuser than the feature is to anybody, so:
 *
 * **The two cases are indistinguishable from the outside.** An address with an
 * account is connected immediately; one without is written down and applied the
 * moment that person signs in. The caller gets the same sentence either way,
 * and it is a true one in both: they will see you when they are here.
 *
 * ## Why an invite is not a notification
 *
 * Nothing is emailed. Sending "Bob added you as a friend" to an address that
 * has never been near this site turns the feature into a way of mailing
 * strangers on somebody else's behalf, and the connection is worth nothing
 * until they arrive of their own accord anyway. When they do, the invite is
 * waiting.
 */
class FriendInvites
{
    public function __construct(private readonly Friends $friends) {}

    /**
     * Connect now if we can, remember if we cannot.
     *
     * Returns nothing on purpose. A caller that could branch on the outcome
     * would eventually report the two cases differently, which is the exact
     * disclosure this class exists to prevent.
     */
    public function invite(User $inviter, string $email, ?DayAndMonth $birthday = null): void
    {
        // Lowercased on the way in and on the way out, because `users.email` is
        // unique and case-sensitive in Postgres: an invite that differed only
        // in capitals would sit unmatched forever beside the account it meant.
        $email = mb_strtolower(trim($email));

        $friend = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($friend !== null) {
            $this->friends->link($inviter, $friend, 'invited');
            $this->rememberBirthday($inviter, $friend->id, $birthday);

            return;
        }

        // Their own address. Nothing to connect, and nothing to hold — but the
        // answer must not say so, so this returns quietly like every other path.
        if (mb_strtolower((string) $inviter->email) === $email) {
            return;
        }

        FriendInvite::query()->updateOrCreate(
            ['inviter_id' => $inviter->id, 'email' => $email],
            ['birthday_day' => $birthday?->day, 'birthday_month' => $birthday?->month],
        );
    }

    /**
     * Apply everything that was waiting for this person's address.
     *
     * Called on sign-in rather than on account creation: the two are the same
     * moment for a magic link, and are not for Google, where a first sign-in
     * creates the account inside the same request. Doing it on `Login` covers
     * both, and being idempotent makes running it on every subsequent sign-in
     * harmless — by then there is nothing left to find.
     */
    public function applyTo(User $user): void
    {
        $invites = FriendInvite::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower((string) $user->email)])
            ->get();

        foreach ($invites as $invite) {
            $inviter = User::query()->find($invite->inviter_id);

            if ($inviter === null) {
                continue;
            }

            $this->friends->link($inviter, $user, 'invited');
            $this->rememberBirthday(
                $inviter,
                $user->id,
                DayAndMonth::fromColumns($invite->birthday_day, $invite->birthday_month),
            );
        }

        // Consumed, not kept. The connection is the record now, and a stale
        // invite would re-apply a friendship somebody had since removed.
        FriendInvite::query()->whereIn('id', $invites->pluck('id'))->delete();
    }

    /**
     * The date the inviter wrote down, on the inviter's own row.
     *
     * One direction only: it is what *they* know about their friend, not a fact
     * the friend has published about themselves. Day and month, never a year —
     * see the migration.
     */
    private function rememberBirthday(User $inviter, int $friendId, ?DayAndMonth $birthday): void
    {
        if ($birthday === null) {
            return;
        }

        Friendship::query()
            ->where('user_id', $inviter->id)
            ->where('friend_id', $friendId)
            ->update([
                'friend_birthday_day' => $birthday->day,
                'friend_birthday_month' => $birthday->month,
                'updated_at' => now(),
            ]);
    }
}

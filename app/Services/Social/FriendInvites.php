<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\InviteOutcome;
use App\Enums\Market;
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
 * ## Since 2026-09-26 an invitation is an email
 *
 * Until then nothing was emailed, on the argument that "Bob added you" sent to
 * an address that has never been near this site makes the feature a way of
 * mailing strangers on somebody else's behalf. The owner asked for the email
 * anyway, because an invitation nobody hears about is one the member has to
 * deliver themselves. The argument still holds, so the email comes with brakes
 * (a daily limit, one email per address a month, a "this is spam" link that
 * works without an account, and complaints that stop a member's emails), all
 * in {@see InviteMailer}.
 *
 * The email goes out whether or not the address has an account, and says the
 * same thing: one that has an account is simply signed in by its button. Only
 * that keeps the two cases indistinguishable to the member.
 */
class FriendInvites
{
    public function __construct(
        private readonly Friends $friends,
        private readonly InviteMailer $mailer,
    ) {}

    /**
     * Connect now if we can, remember if we cannot, and email the address.
     *
     * The outcome reports only what the member did themselves (their own
     * address, their daily limit, an address they invited recently), never
     * anything about the address: an account, a "no invitations" request and
     * a complaint all come back as `Sent`. A caller that could branch on those
     * would eventually say them out loud, which is the disclosure this class
     * exists to prevent.
     */
    public function invite(User $inviter, string $email, ?DayAndMonth $birthday, Market $market): InviteOutcome
    {
        // Lowercased on the way in and on the way out, because `users.email` is
        // unique and case-sensitive in Postgres: an invite that differed only
        // in capitals would sit unmatched forever beside the account it meant.
        $email = mb_strtolower(trim($email));

        // Their own address. Said plainly: the member knows their own address,
        // so the answer discloses nothing, and we never email it.
        if (mb_strtolower((string) $inviter->email) === $email) {
            return InviteOutcome::OwnAddress;
        }

        $hash = InviteMailer::hash($email);

        // Invited within the month already: connect as before (a birthday typed
        // the second time is still kept), but send nothing.
        if ($this->mailer->invitedRecently($inviter, $hash) !== null) {
            $this->connectOrRemember($inviter, $email, $birthday);

            return InviteOutcome::AlreadyInvited;
        }

        if ($this->mailer->atDailyLimit($inviter)) {
            return InviteOutcome::DailyLimit;
        }

        $this->connectOrRemember($inviter, $email, $birthday);
        $this->mailer->deliver($inviter, $email, $market);

        return InviteOutcome::Sent;
    }

    private function connectOrRemember(User $inviter, string $email, ?DayAndMonth $birthday): void
    {
        $friend = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($friend !== null) {
            $this->friends->link($inviter, $friend, 'invited');
            $this->rememberBirthday($inviter, $friend->id, $birthday);

            return;
        }

        $invite = FriendInvite::query()->updateOrCreate(
            ['inviter_id' => $inviter->id, 'email' => $email],
            ['birthday_day' => $birthday?->day, 'birthday_month' => $birthday?->month],
        );

        // Inviting again restarts the year bc:prune-personal-data keeps a
        // pending invitation for, even when nothing on it changed (which is
        // when updateOrCreate leaves `updated_at` alone).
        if (! $invite->wasRecentlyCreated) {
            $invite->touch();
        }
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

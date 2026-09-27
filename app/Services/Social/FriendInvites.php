<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\InviteOutcome;
use App\Enums\Market;
use App\Enums\RecipientStatus;
use App\Models\FriendInvite;
use App\Models\Friendship;
use App\Models\Recipient;
use App\Models\User;
use App\Support\DayAndMonth;
use Illuminate\Database\UniqueConstraintViolationException;

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
 *
 * ## Inviting a saved person (2026-09-27)
 *
 * "Nodig uit op GiftCoves" on somebody the member saved (Mama, a `recipients`
 * row with no account behind it) sends this same invitation, and names the
 * saved person. When the connection is made (at once for an address with an
 * account, at their sign-in otherwise, through `friend_invites.recipient_id`)
 * the saved person is linked to that account, exactly as "Bewaar wat je over
 * Sam weet" links one (RecipientController::store(): `user_id` and
 * status `linked`). Without it Mama and her new account would be two rows on
 * My people, with the lists and "what you know" split between them.
 *
 * The answer the member gets is still the same either way. What differs is
 * what the page shows afterwards: a saved person linked at once is "op
 * GiftCoves" at once. That is the same disclosure the plain invitation already
 * makes (a friend row appears at once), not a new one; see
 * docs/features/friend-invite-mail.md, "What this does not close".
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
    public function invite(User $inviter, string $email, ?DayAndMonth $birthday, Market $market, ?Recipient $person = null): InviteOutcome
    {
        // Only the inviter's own saved person, and only one no account is
        // behind yet. The controller already looked it up owner-scoped; this
        // is the second lock, because linking somebody else's saved person to
        // an account would hand that account their notes' subject.
        if ($person !== null && ! $this->mayLink($inviter, $person)) {
            $person = null;
        }

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
            $this->connectOrRemember($inviter, $email, $birthday, $person);

            return InviteOutcome::AlreadyInvited;
        }

        if ($this->mailer->atDailyLimit($inviter)) {
            return InviteOutcome::DailyLimit;
        }

        $this->connectOrRemember($inviter, $email, $birthday, $person);
        $this->mailer->deliver($inviter, $email, $market);

        return InviteOutcome::Sent;
    }

    private function connectOrRemember(User $inviter, string $email, ?DayAndMonth $birthday, ?Recipient $person): void
    {
        $this->keepBirthdayOn($person, $birthday);

        $friend = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($friend !== null) {
            $this->friends->link($inviter, $friend, 'invited');
            $this->rememberBirthday($inviter, $friend->id, $birthday);
            $this->linkPerson($inviter, $person, $friend);

            return;
        }

        $invite = FriendInvite::query()->updateOrCreate(
            ['inviter_id' => $inviter->id, 'email' => $email],
            [
                'birthday_day' => $birthday?->day,
                'birthday_month' => $birthday?->month,
                // Only when this invitation names somebody: sending the plain
                // form again to the same address must not forget that it was
                // Mama's. Naming a different saved person replaces it; one
                // address becomes one account, and one saved person per
                // account can be linked to it.
                ...$person === null ? [] : ['recipient_id' => $person->id],
            ],
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

            // The saved person the invitation was for, read fresh: it may have
            // been linked another way, or deleted (the column is then null).
            if ($invite->recipient_id !== null) {
                $person = Recipient::query()->find($invite->recipient_id);

                if ($person !== null && $this->mayLink($inviter, $person)) {
                    $this->linkPerson($inviter, $person, $user);
                }
            }
        }

        // Consumed, not kept. The connection is the record now, and a stale
        // invite would re-apply a friendship somebody had since removed.
        FriendInvite::query()->whereIn('id', $invites->pluck('id'))->delete();
    }

    /**
     * Whether an invitation may name this saved person: the inviter's own,
     * with no account behind it yet, and not the inviter themselves (status
     * `self`, This or that played "for me").
     */
    public function mayLink(User $inviter, Recipient $person): bool
    {
        return $person->owner_user_id === $inviter->id
            && $person->user_id === null
            && $person->status !== RecipientStatus::Self;
    }

    /**
     * Make the saved person this account, as "Bewaar wat je weet" does.
     *
     * Never re-points a saved person that is linked already: the update is
     * conditional on `user_id IS NULL`, so a person linked in the meantime
     * (they claimed their `/for/{token}` link) keeps the account they chose.
     *
     * **One saved person per account.** When the inviter already saved this
     * account as somebody else (they made "Sam" from the friend row, then
     * invited Sam's address again from an older "Sam" nobody linked), the
     * earlier link stands and this saved person stays as it was, unlinked. A
     * merge of the two would have to pick whose interests and history win, and
     * the owner's rule for My people is that nothing he wrote disappears. The
     * unique index `recipients_owner_user_idx` enforces it as well; the check
     * here is what keeps a sign-in from ever meeting that index as an error.
     */
    private function linkPerson(User $inviter, ?Recipient $person, User $friend): void
    {
        if ($person === null || $friend->id === $inviter->id) {
            return;
        }

        $taken = Recipient::query()
            ->where('owner_user_id', $inviter->id)
            ->where('user_id', $friend->id)
            ->exists();

        if ($taken) {
            return;
        }

        try {
            Recipient::query()
                ->whereKey($person->id)
                ->where('owner_user_id', $inviter->id)
                ->whereNull('user_id')
                ->update([
                    'user_id' => $friend->id,
                    // Linked, not a stub, as RecipientController::store sets
                    // it: the taste engine reads the status to know whose
                    // answers to prefer.
                    'status' => RecipientStatus::Linked->value,
                    'updated_at' => now(),
                ]);
        } catch (UniqueConstraintViolationException) {
            // Two sign-ins racing to link the same account. The other one
            // won, which is the same outcome; the sign-in must not fail on it.
        }
    }

    /**
     * A birthday typed into the form, kept on the saved person when they have
     * none. Before the account exists the friendship note that also holds it
     * is not on the page yet, so without this a date the member typed for Mama
     * would not show on Mama's row until she signed in. One that is already
     * saved is not overwritten: it is what the reminder email reads.
     */
    private function keepBirthdayOn(?Recipient $person, ?DayAndMonth $birthday): void
    {
        if ($person === null || $birthday === null || $person->birthday !== null) {
            return;
        }

        $person->update(['birthday' => Recipient::birthdayFrom($birthday->day, $birthday->month)]);
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

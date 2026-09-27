<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\Market;
use App\Mail\FriendInviteMail;
use App\Models\FriendInviteToken;
use App\Models\InviteComplaint;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * The email an invitation sends, and everything that can stop it.
 *
 * Since 2026-09-26 (owner's request) "Nodig uit op GiftCoves" emails the
 * address. That turns a form into a way to mail a stranger on a member's
 * behalf, so the email comes with three brakes, all decided here:
 *
 * 1. **Limits on the member**: `giftcoves.invites.daily_limit` addresses a day
 *    and one email per address per `repeat_days`. Checked by
 *    {@see FriendInvites} before anything is recorded.
 * 2. **"Wil je geen uitnodigingen meer ontvangen?"** in every email, a signed
 *    link that needs no account. It puts the address on a suppression list, so
 *    no invitation from anybody is emailed to it again. Only the separate
 *    "Meld als spam" button on the page it opens counts a complaint against
 *    the member.
 * 3. **Complaints stop a member's emails**: after `complaint_limit` of them,
 *    their invitations are still recorded but no longer emailed.
 *
 * ## What the member never learns
 *
 * Whether the address has an account, asked for no invitations, or complained
 * about them. All three look exactly like an email that went out: the same
 * sentence on the page, the invitation recorded, the connection made when the
 * person signs in. A member who could tell would have an oracle for "is this
 * address on GiftCoves" or "did this person block me", and the first is the
 * disclosure FriendInvites was written to prevent.
 *
 * ## Why a hash
 *
 * The suppression list must outlive everything else we know about an address,
 * including our never having known it: it is the record of somebody who does
 * not want to hear from us. Holding the address itself to honour that would be
 * keeping personal data of a person who explicitly wants nothing to do with
 * us. A keyed hash of the lower-cased address answers "is this the address that
 * asked?" and cannot be read back. Keyed with CLAIM_HASH_SECRET rather than
 * APP_KEY for the reason that secret exists: rotating APP_KEY must not quietly
 * empty the list and start mailing people who asked us to stop. A plain sha256
 * would be reversible by hashing a list of addresses.
 *
 * ## Queued, like the list-sharing email
 *
 * Not the magic link's synchronous send: nothing here expires in fifteen
 * minutes, and a slow mail server must not slow the form. A mail that cannot
 * be queued is logged and the invitation stands, as in ListSharer::notify().
 */
class InviteMailer
{
    public const STATUS_SENT = 'sent';

    public const STATUS_SUPPRESSED = 'suppressed';

    public const STATUS_SENDER_MUTED = 'sender_muted';

    /** The keyed hash every table here stores instead of an address. */
    public static function hash(string $email): string
    {
        return hash_hmac(
            'sha256',
            'invite-address|'.mb_strtolower(trim($email)),
            (string) config('giftcoves.wishlist.claim_hash_secret'),
        );
    }

    /** When this member last invited this address, if within the repeat window. */
    public function invitedRecently(User $inviter, string $hash): ?CarbonInterface
    {
        $at = DB::table('friend_invite_mails')
            ->where('inviter_id', $inviter->id)
            ->where('email_hash', $hash)
            ->where('created_at', '>=', now()->subDays((int) config('giftcoves.invites.repeat_days', 30)))
            ->max('created_at');

        return $at === null ? null : Carbon::parse($at);
    }

    /** Whether the member has used up today's invitations (a rolling 24 hours). */
    public function atDailyLimit(User $inviter): bool
    {
        $count = DB::table('friend_invite_mails')
            ->where('inviter_id', $inviter->id)
            ->where('created_at', '>=', now()->subDay())
            ->count();

        return $count >= (int) config('giftcoves.invites.daily_limit', 20);
    }

    /**
     * Email the invitation, unless the address or the member has been stopped,
     * and record it either way.
     *
     * The row is written in all three cases because it is what the limits
     * count: an address that asked for no emails must use up the member's day
     * exactly like one that did not, or the limit itself would tell them.
     */
    public function deliver(User $inviter, string $email, Market $market): void
    {
        $hash = self::hash($email);

        $status = match (true) {
            $this->isSuppressed($hash) => self::STATUS_SUPPRESSED,
            $this->senderIsMuted($inviter) => self::STATUS_SENDER_MUTED,
            default => self::STATUS_SENT,
        };

        DB::table('friend_invite_mails')->insert([
            'inviter_id' => $inviter->id,
            'email_hash' => $hash,
            'status' => $status,
            'created_at' => now(),
        ]);

        if ($status !== self::STATUS_SENT) {
            return;
        }

        /*
         * The button: accept in one press (2026-09-27). A token is issued for
         * every email, with an account behind the address or not, so the
         * email stays the same in both cases. What the button does differs
         * only once pressed: it creates and signs in a new account, and sends
         * an existing one to the sign-in page. See InviteAcceptance.
         */
        $token = FriendInviteToken::issue($inviter, $email);

        try {
            Mail::to($email)->queue(new FriendInviteMail(
                inviterName: $inviter->displayName(),
                language: $market->language(),
                url: url("/{$market->value}/invites/accept/{$token}"),
                notWantedUrl: $this->notWantedUrl($inviter->id, $hash, $market),
            ));
        } catch (\Throwable $e) {
            // The invitation stands whatever the mail does. See the class comment.
            Log::warning('An invitation could not be emailed', [
                'inviter_id' => $inviter->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * "Wil je geen uitnodigingen meer ontvangen?", in the email and its
     * List-Unsubscribe header.
     *
     * Permanent (no expiry): an unsubscribe link that stops working after a
     * week is one that makes people press the mail client's spam button
     * instead. The signature is what stops anybody silencing another address,
     * or complaining against another member.
     */
    public function notWantedUrl(int $inviterId, string $hash, Market $market): string
    {
        return URL::signedRoute('invites.not-wanted', [
            'market' => $market->value,
            'inviter' => $inviterId,
            'hash' => $hash,
        ]);
    }

    public function undoUrl(int $inviterId, string $hash, Market $market): string
    {
        return URL::signedRoute('invites.not-wanted.undo', [
            'market' => $market->value,
            'inviter' => $inviterId,
            'hash' => $hash,
        ]);
    }

    public function isSuppressed(string $hash): bool
    {
        return DB::table('invite_suppressions')->where('email_hash', $hash)->exists();
    }

    public function senderIsMuted(User $inviter): bool
    {
        return InviteComplaint::query()->where('inviter_id', $inviter->id)->count()
            >= (int) config('giftcoves.invites.complaint_limit', 3);
    }

    /** The spam button on the page the link opens. */
    public function spamUrl(int $inviterId, string $hash, Market $market): string
    {
        return URL::signedRoute('invites.not-wanted.spam', [
            'market' => $market->value,
            'inviter' => $inviterId,
            'hash' => $hash,
        ]);
    }

    public function isReported(int $inviterId, string $hash): bool
    {
        return DB::table('invite_complaints')
            ->where('inviter_id', $inviterId)
            ->where('email_hash', $hash)
            ->exists();
    }

    /**
     * "Wil je geen uitnodigingen meer ontvangen?": no more invitations from
     * anybody, and nothing counted against the member who sent it.
     *
     * Not a complaint since 2026-09-27 (owner's decision). Saying "no thanks"
     * is not saying the friend did something wrong, and counting it as one
     * would stop a member's emails after three polite refusals. The complaint
     * is its own button on the page, {@see report()}.
     */
    public function stop(string $hash): void
    {
        DB::table('invite_suppressions')->insertOrIgnore([
            'email_hash' => $hash,
            'created_at' => now(),
        ]);
    }

    /**
     * "Meld als spam": stops the invitations like {@see stop()}, and counts
     * one complaint against the member who sent it. Pressing twice is still
     * one complaint.
     */
    public function report(int $inviterId, string $hash): void
    {
        $this->stop($hash);

        // A member deleted since the email went out has nobody left to count
        // against; the suppression above still stands.
        if (User::query()->whereKey($inviterId)->exists()) {
            DB::table('invite_complaints')->insertOrIgnore([
                'inviter_id' => $inviterId,
                'email_hash' => $hash,
                'created_at' => now(),
            ]);
        }
    }

    /**
     * The undo on the confirmation page: invitations may reach this address
     * again, and the complaint against this member is withdrawn. Somebody who
     * pressed by mistake should not leave a mark on the friend who invited them.
     */
    public function undo(int $inviterId, string $hash): void
    {
        DB::table('invite_suppressions')->where('email_hash', $hash)->delete();
        DB::table('invite_complaints')
            ->where('inviter_id', $inviterId)
            ->where('email_hash', $hash)
            ->delete();
    }
}

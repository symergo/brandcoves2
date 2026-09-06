<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Models\User;
use App\Services\Wishlist\PendingClaim;
use Illuminate\Contracts\Session\Session;

/**
 * "Somebody sent me this link" — held until there is an account to attach it to.
 *
 * The sibling of {@see PendingClaim}, and it exists for
 * the same reason: the fact that matters is established *before* the sign-in,
 * and the sign-in is where it would otherwise be lost. Somebody opens a
 * registry a friend sent, signs in to claim something off it, and by the time
 * the account exists nothing in the request remembers whose link brought them.
 *
 * One referral, not a queue. The relevant one is the link that made them sign
 * in, which is the last one they opened — a week of half-followed links
 * replayed at once would connect somebody to five people they do not remember
 * meeting.
 *
 * A day, not an hour. `PendingClaim` expires fast because it *acts* — it
 * commits somebody to buying a present. This only records that two people are
 * coordinating, which is still true tomorrow, and the commonest journey here
 * is a magic link read on a phone hours after the list was opened on a laptop.
 */
class ShareReferral
{
    private const KEY = 'social.shared_by';

    private const LIFETIME_SECONDS = 86400;

    public function __construct(private readonly Session $session) {}

    public function remember(int $ownerUserId): void
    {
        $this->session->put(self::KEY, ['user_id' => $ownerUserId, 'at' => now()->timestamp]);
    }

    public function forget(): void
    {
        $this->session->forget(self::KEY);
    }

    /**
     * Connect whoever just signed in to whoever sent them the link.
     *
     * Single-use whatever happens: a referral that fails to apply must not be
     * retried on the next sign-in, when the person at the keyboard may be
     * somebody else on the same machine.
     */
    public function applyTo(User $user, Friends $friends): void
    {
        $referral = $this->session->get(self::KEY);

        $this->forget();

        if (! is_array($referral) || ! is_int($referral['user_id'] ?? null) || ! is_int($referral['at'] ?? null)) {
            return;
        }

        if (now()->timestamp - $referral['at'] > self::LIFETIME_SECONDS) {
            return;
        }

        $sharer = User::query()->find($referral['user_id']);

        if ($sharer === null) {
            return;
        }

        // `link()` swallows the self case, which is the ordinary one here: an
        // owner following their own share link to check what it looks like.
        $friends->link($user, $sharer);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Wishlist;

use App\Enums\ListVisibility;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Auth\IdentityMerger;
use App\Support\Owner;
use Illuminate\Contracts\Session\Session;

/**
 * The claim somebody pressed before they had an account.
 *
 * ## Why claiming needs an account at all
 *
 * It did not, deliberately, for most of this feature's life: somebody follows a
 * link once, and making them register to say "I'll get this" is how a gift list
 * stops working as a coordination tool. That reasoning was sound about the
 * press and wrong about everything after it.
 *
 * A claim hangs off a hash of the claimer's identity, and an anonymous
 * identity is a cookie. So the person who claimed the scarf could not open the
 * link on their phone and see they had — the phone is a different cookie and
 * the list showed the scarf as taken by a stranger. They could not release it
 * from that phone either, and clearing the browser lost the claim outright:
 * an item marked as spoken for, by nobody reachable, that no one could hand
 * back. {@see IdentityMerger} spells out why the hash cannot
 * simply be re-parented at sign-in — recomputing it for a person we can now
 * name is the one thing the hash exists to prevent.
 *
 * An account is the only identity that survives a second device, and a claim
 * that cannot be revisited or undone is worse for the list than one that was
 * never made.
 *
 * ## What this class is for
 *
 * The same job {@see PendingSave} does for a save, and it exists for the same
 * reason: the decision — *this item, on this list* — is made before the sign-in
 * and would otherwise be thrown away by it. Somebody who lands back on a
 * fifty-item list and has to find the right row again mostly does not.
 *
 * One intent, not a queue: a person presses claim, is asked to sign in, and
 * signs in. That is the whole story.
 *
 * An hour, single-use, for the same reason a pending save expires — a claim
 * replayed days later, plausibly on a shared machine and plausibly by somebody
 * else, commits a stranger to buying a present.
 */
class PendingClaim
{
    private const KEY = 'wishlist.pending_claim';

    /** Long enough to read an email and click a link; short enough not to be a surprise. */
    private const LIFETIME_SECONDS = 3600;

    public function __construct(private readonly Session $session) {}

    /**
     * Remember a claim, and where the visitor was standing when they pressed it.
     *
     * `url.intended` is Laravel's own key, so both sign-in paths already honour
     * it — `MagicLinkController` and `GoogleController` each end in
     * `redirect()->intended(...)` and need no change.
     */
    public function remember(string $token, int $itemId, ?string $name, ?string $returnTo): void
    {
        $this->session->put(self::KEY, [
            'token' => $token,
            'item' => $itemId,
            'name' => $name,
            'at' => now()->timestamp,
        ]);

        if ($returnTo !== null) {
            $this->session->put('url.intended', $returnTo);
        }
    }

    public function forget(): void
    {
        $this->session->forget(self::KEY);
    }

    /**
     * Apply whatever was waiting, to the account that just signed in.
     *
     * Every guard the endpoint applies is applied again here, against the
     * *account* rather than the anonymous visitor who pressed the button. That
     * is not belt-and-braces: signing in changes the answers. The person who
     * followed the link may turn out to be the list's own owner, in which case
     * `shouldHideClaimsFrom()` is now true and the claim must not happen —
     * a claim of one's own wish list is how the owner learns what is taken.
     *
     * Returns the item's title so the caller can say what was claimed, null
     * when there was nothing to do or the claim did not land.
     */
    public function claimFor(User $user): ?string
    {
        $pending = $this->session->get(self::KEY);

        // Single-use, whatever happens below. A replay that fails must not
        // leave an intent behind to be retried on the next sign-in.
        $this->forget();

        if (! is_array($pending) || ! is_string($pending['token'] ?? null) || ! is_int($pending['item'] ?? null)) {
            return null;
        }

        if (! is_int($pending['at'] ?? null) || now()->timestamp - $pending['at'] > self::LIFETIME_SECONDS) {
            return null;
        }

        $list = Wishlist::query()
            ->where('share_token', $pending['token'])
            // Sharing may have been turned off between the press and the
            // sign-in. Turning it off has to actually turn it off.
            ->where('visibility', '!=', ListVisibility::Private->value)
            ->first();

        if ($list === null || ! $list->allowsClaiming()) {
            return null;
        }

        $owner = new Owner(user: $user, anonymous: null);

        if ($list->shouldHideClaimsFrom($owner)) {
            return null;
        }

        $item = $list->items()->whereKey($pending['item'])->first();

        if ($item === null) {
            return null;
        }

        /*
         * The name, only when the list asks for one — the same rule
         * `SharedListController::claim()` applies, asked of the list as it
         * stands now rather than as it stood when the button was pressed. A
         * name posted to an anonymous list is dropped rather than refused.
         *
         * Falling back to the account's own name is what the page does for
         * somebody already signed in: it prefills the field from `auth.user`.
         * Without the fallback a claim made through the sign-in would land
         * nameless on a list that names people, and render as "spoken for" —
         * which reads as a claim by a stranger rather than by the person whose
         * account had just been created.
         */
        $name = $list->claim_visibility->namesClaimers()
            ? (filled($pending['name'] ?? null) ? (string) $pending['name'] : $user->name)
            : null;

        $identity = $owner->claimIdentity();

        if ($identity === null) {
            return null;
        }

        // Somebody else may have claimed it during the round trip through an
        // inbox, which is precisely what the atomic claim is for.
        return $item->claim(WishlistItem::identityHash($identity), $name)
            ? $item->snapshot_title
            : null;
    }
}

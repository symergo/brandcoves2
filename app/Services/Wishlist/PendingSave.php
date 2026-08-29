<?php

declare(strict_types=1);

namespace App\Services\Wishlist;

use App\Enums\Market;
use App\Enums\Source;
use App\Models\ProductGroup;
use App\Models\User;
use App\Support\CurrentMarket;
use App\Support\Owner;
use Illuminate\Contracts\Session\Session;

/**
 * The save somebody pressed before they had an account.
 *
 * ## The moment this exists for
 *
 * Keeping a list requires an account — decided deliberately, and unchanged
 * here; see docs/features/wishlists.md. What that decision did *not* settle is
 * what happens to the product in the visitor's hand at the moment they are
 * asked to sign in. Until now: nothing. The picker navigated to the login page
 * client-side, before any request reached the server, so Laravel never recorded
 * an intended URL — and signing in landed the visitor on My Lists, with an
 * empty list, on a page they had not asked for, having forgotten what the
 * product was called.
 *
 * The visit was lost at precisely the point the person was most willing to act.
 * This class is the difference between "sign in to keep this" and "sign in, and
 * good luck finding it again".
 *
 * ## Why one intent and not a queue
 *
 * A person presses save, is asked to sign in, and signs in. That is the whole
 * story. A queue would mean deciding what to do with six intents accumulated
 * across a week of browsing, and replaying five products somebody has forgotten
 * choosing is a worse outcome than dropping them.
 *
 * ## Why it expires
 *
 * An hour, single-use. A save replayed days later — plausibly on a shared
 * machine, plausibly by somebody else — is not what anybody asked for, and a
 * gift list is the wrong place to be surprised by an item you did not put
 * there.
 */
class PendingSave
{
    private const KEY = 'wishlist.pending_save';

    /** Long enough to read an email and click a link; short enough not to be a surprise. */
    private const LIFETIME_SECONDS = 3600;

    public function __construct(private readonly Session $session) {}

    /**
     * Remember a save, and where the visitor was standing when they pressed it.
     *
     * `url.intended` is Laravel's own key, so both sign-in paths already honour
     * it — `MagicLinkController` and `GoogleController` each end in
     * `redirect()->intended(...)` and need no change.
     *
     * @param  array<string, mixed>  $payload
     */
    public function remember(array $payload, Market $market, ?string $returnTo): void
    {
        $this->session->put(self::KEY, [
            'payload' => $payload,
            'market' => $market->value,
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
     * Lands in the default list rather than opening the picker again. The
     * visitor already made the only decision that matters — *this product* —
     * and asking them to make a second one after a detour through an inbox is
     * how the first decision gets abandoned.
     *
     * Returns the list's title so the caller can say where it went, or null
     * when there was nothing to do.
     */
    public function replayFor(User $user, ItemSaver $saver, DefaultList $lists): ?string
    {
        $pending = $this->session->get(self::KEY);

        // Single-use, whatever happens below. A replay that throws must not
        // leave an intent behind to be retried on the next sign-in.
        $this->forget();

        if (! is_array($pending) || ! is_array($pending['payload'] ?? null)) {
            return null;
        }

        if (! is_int($pending['at'] ?? null) || now()->timestamp - $pending['at'] > self::LIFETIME_SECONDS) {
            return null;
        }

        $market = Market::tryFrom((string) ($pending['market'] ?? ''));

        if ($market === null) {
            return null;
        }

        $current = new CurrentMarket($market);
        $owner = new Owner(user: $user, anonymous: null);
        $payload = $pending['payload'];

        $list = $lists->for($owner, $current);

        // A group id is only meaningful inside its own market — `product_groups`
        // is unique on (market, identity_key), so the same product in two
        // markets is two rows and one of them is the wrong price.
        if (! empty($payload['group_id'])) {
            $group = ProductGroup::query()
                ->forMarket($market)
                ->find($payload['group_id']);

            if ($group === null) {
                return null;
            }

            $saver->saveGroup($list, $group, $current);

            return $list->displayTitle();
        }

        $source = Source::tryFrom((string) ($payload['source'] ?? ''));

        if ($source === null || $source === Source::Manual || empty($payload['external_id'])) {
            return null;
        }

        /*
         * The snapshot fields stay hints, exactly as they are on the ordinary
         * path: `ItemSaver::saveExternal()` decides per source whether any of
         * them may be stored, so a stale intent naming Amazon cannot smuggle a
         * mirrored title and price into the catalogue (invariant #6).
         */
        $saver->saveExternal(
            list: $list,
            source: $source,
            externalId: (string) $payload['external_id'],
            snapshot: [
                'title' => $payload['title'] ?? null,
                'image_url' => $payload['image_url'] ?? null,
                'price' => $payload['price'] ?? null,
            ],
        );

        return $list->displayTitle();
    }
}

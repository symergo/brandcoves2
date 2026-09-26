<?php

declare(strict_types=1);

namespace App\Services\Wishlist;

use App\Enums\Market;
use App\Enums\Source;
use App\Models\DailyPickSet;
use App\Models\OfflineIdea;
use App\Models\ProductGroup;
use App\Models\User;
use App\Services\Cove\CommunityCoves;
use App\Services\Cove\SavedCoves;
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
     * Returns the list's title and the language to say it in, or null when
     * there was nothing to do. The language is the pending market's, not the
     * sign-in request's: the visitor is sent on to the page they left, which
     * is in that market, and a "Saved to" flashed under whatever locale the
     * login ran in (the admin's, a magic link opened from another market)
     * reads in the wrong language on arrival (owner, 2026-09-13).
     *
     * The list's kind rides along so the confirmation can draw the name as a
     * list's name, with its kind's icon (see App\Support\ListName); null for a
     * Cove that was only bookmarked, which is not a list of yours.
     *
     * @return array{title: string, kind: string|null, language: string, message?: string}|null
     */
    public function replayFor(User $user, ItemSaver $saver, DefaultList $lists): ?array
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

        // A Cove, not a product: saved, or made into a list. Before the
        // default list is looked up, which a Cove does not need.
        if (! empty($payload['cove_id'])) {
            return $this->replayCove($user, $current, (int) $payload['cove_id'], (string) ($payload['cove_action'] ?? 'save'));
        }

        if (! empty($payload['community_cove'])) {
            return $this->replayCommunityCove($user, $current, (string) $payload['community_cove'], (string) ($payload['cove_action'] ?? 'save'));
        }

        $list = $lists->for($owner, $current);

        // An approved offline idea, pressed before signing in. Its wording is
        // read now, from the idea: the session held only its id, and an idea
        // withdrawn in the meantime is simply not added.
        if (! empty($payload['idea_id'])) {
            $idea = OfflineIdea::query()->approved()->find((int) $payload['idea_id']);

            if ($idea === null) {
                return null;
            }

            $saver->saveManual($list, $idea->title);

            return ['title' => $list->displayTitle($market->language()), 'kind' => $list->kind->value, 'language' => $market->language()];
        }

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

            return ['title' => $list->displayTitle($market->language()), 'kind' => $list->kind->value, 'language' => $market->language()];
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

        return ['title' => $list->displayTitle($market->language()), 'kind' => $list->kind->value, 'language' => $market->language()];
    }

    /**
     * Finish a guest's press on a Cove's Save or "Make it my list".
     *
     * @return array{title: string, kind: string|null, language: string, message: string}|null
     */
    private function replayCove(User $user, CurrentMarket $current, int $coveId, string $action): ?array
    {
        $cove = DailyPickSet::query()->find($coveId);

        if ($cove === null || ! $cove->isPublished()) {
            return null;
        }

        $saved = app(SavedCoves::class);
        $language = $current->get()->language();

        if ($action === 'copy') {
            $list = $saved->copyToList($user, $cove);
            // Land on the new list, not back on the Cove.
            $this->session->put('url.intended', $current->url("lists/{$list->id}"));

            return ['title' => (string) $cove->theme_title, 'kind' => $list->kind->value, 'language' => $language, 'message' => 'site.saved_coves.copied'];
        }

        $saved->save($user, $cove);

        return ['title' => (string) $cove->theme_title, 'kind' => null, 'language' => $language, 'message' => 'site.saved_coves.saved_flash'];
    }

    /**
     * The same, for a Community Cove (a list somebody published). Only while
     * it is still on the site: an owner may have taken it down in between.
     *
     * @return array{title: string, kind: string|null, language: string, message: string}|null
     */
    private function replayCommunityCove(User $user, CurrentMarket $current, string $slug, string $action): ?array
    {
        $list = app(CommunityCoves::class)->find($current->get(), $slug);

        if ($list === null) {
            return null;
        }

        $saved = app(SavedCoves::class);
        $language = $current->get()->language();
        $title = (string) $list->public_title;

        if ($action === 'copy') {
            $copy = $saved->copyListToList($user, $list);
            $this->session->put('url.intended', $current->url("lists/{$copy->id}"));

            return ['title' => $title, 'kind' => $copy->kind->value, 'language' => $language, 'message' => 'site.saved_coves.copied'];
        }

        $saved->saveList($user, $list);

        return ['title' => $title, 'kind' => null, 'language' => $language, 'message' => 'site.saved_coves.saved_flash'];
    }
}

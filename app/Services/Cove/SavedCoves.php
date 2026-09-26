<?php

declare(strict_types=1);

namespace App\Services\Cove;

use App\Models\DailyPick;
use App\Models\DailyPickSet;
use App\Models\SavedCove;
use App\Models\User;
use App\Models\Wishlist;
use App\Services\Wishlist\ItemSaver;
use App\Services\Wishlist\ListMaker;
use App\Support\CurrentMarket;
use App\Support\Owner;
use Illuminate\Support\Facades\DB;

/**
 * Saved Coves: a bookmark, and a copy on request (docs/features/saved-coves.md).
 *
 * - **Save** keeps a link from the person to the Cove. The Cove stays ours and
 *   changes whenever we edit it; an unpublished Cove is hidden from the saved
 *   view, not deleted, so it comes back if it is republished.
 * - **Make it my list** copies the Cove's products into a new list of the
 *   person's own. A snapshot: later edits to the Cove do not reach it, and the
 *   bookmark stays independent. It goes through `ItemSaver::saveGroup()`, the
 *   same path a hand-made list is filled by, so the copy is an ordinary list.
 *
 * Both need an account (lists do since 2026-09-06), and both are idempotent
 * where it matters: saving twice is one bookmark.
 */
class SavedCoves
{
    public function __construct(
        private readonly ListMaker $lists,
        private readonly ItemSaver $saver,
    ) {}

    public function save(User $user, DailyPickSet $cove): void
    {
        SavedCove::query()->firstOrCreate(['user_id' => $user->id, 'set_id' => $cove->id]);
    }

    public function unsave(User $user, DailyPickSet $cove): void
    {
        SavedCove::query()->where('user_id', $user->id)->where('set_id', $cove->id)->delete();
    }

    public function isSaved(?User $user, int $coveId): bool
    {
        return $user !== null
            && SavedCove::query()->where('user_id', $user->id)->where('set_id', $coveId)->exists();
    }

    /**
     * What a Cove page sends its Save button.
     *
     * @return array{coveId: int, isSaved: bool}
     */
    public function button(int $coveId): array
    {
        $user = auth()->user();

        return ['coveId' => $coveId, 'isSaved' => $this->isSaved($user instanceof User ? $user : null, $coveId)];
    }

    /**
     * Save a Community Cove: a list somebody published
     * (docs/features/community-coves.md). The same bookmark, pointing at the
     * list instead of an editorial Cove.
     *
     * Saving your own is a no-op: it is already under My Coves, and a save by
     * its owner would count towards the "most saved" order and the indexing
     * rule as though a stranger had found it worth keeping.
     */
    public function saveList(User $user, Wishlist $list): void
    {
        if ($list->owner_user_id === $user->id) {
            return;
        }

        SavedCove::query()->firstOrCreate(['user_id' => $user->id, 'wishlist_id' => $list->id]);
    }

    public function unsaveList(User $user, Wishlist $list): void
    {
        SavedCove::query()->where('user_id', $user->id)->where('wishlist_id', $list->id)->delete();
    }

    /**
     * What a Community Cove's page sends its Save button: the same component
     * as an editorial Cove's, pointed at other addresses.
     *
     * @return array<string, mixed>
     */
    public function listButton(Wishlist $list, string $base): array
    {
        $user = auth()->user();

        return [
            'isSaved' => $user instanceof User
                && SavedCove::query()->where('user_id', $user->id)->where('wishlist_id', $list->id)->exists(),
            'saveUrl' => "{$base}/coves/community/{$list->public_slug}/save",
            'copyUrl' => "{$base}/coves/community/{$list->public_slug}/copy",
            'intent' => ['community_cove' => (string) $list->public_slug],
            // The owner sees their own page without a Save button.
            'isOwn' => $user instanceof User && $list->owner_user_id === $user->id,
        ];
    }

    /**
     * A Community Cove as a new list of the person's own: what a stranger may
     * see of it, and nothing else. Products by their group, hand-written items
     * by title and price only (no link, no photo, no note), in the same order.
     * A snapshot, like copying an editorial Cove.
     */
    public function copyListToList(User $user, Wishlist $source): Wishlist
    {
        $current = new CurrentMarket($source->market);
        $owner = new Owner(user: $user, anonymous: null);
        $items = app(CommunityCoves::class)->publicItems($source);

        return DB::transaction(function () use ($source, $current, $owner, $items): Wishlist {
            $list = $this->lists->make(owner: $owner, current: $current, title: (string) $source->public_title);

            foreach ($items as $index => $item) {
                $copy = $item->group !== null
                    // In the product's own market: a list may hold products
                    // from several, and a group id belongs to exactly one.
                    ? $this->saver->saveGroup($list, $item->group, new CurrentMarket($item->group->market))
                    : $this->saver->saveManual($list, (string) $item->snapshot_title, price: $item->snapshot_price);

                $copy->forceFill(['created_at' => now()->subSeconds($index)])->save();
            }

            return $list;
        });
    }

    /**
     * The Cove's products as a new list of the person's own, in the Cove's
     * order. A pick whose product is gone (or an Amazon pick, which has no
     * product we may keep; invariant 6) is skipped.
     */
    public function copyToList(User $user, DailyPickSet $cove): Wishlist
    {
        $current = new CurrentMarket($cove->market);
        $owner = new Owner(user: $user, anonymous: null);

        return DB::transaction(function () use ($cove, $current, $owner): Wishlist {
            $list = $this->lists->make(owner: $owner, current: $current, title: (string) $cove->theme_title);

            $picks = $cove->picks()->with('group')->get()
                ->filter(fn (DailyPick $pick) => $pick->group !== null)
                ->values();

            /*
             * A list shows its newest items first (Lists/Show), so each pick
             * is dated a second before the one above it: the list reads in the
             * Cove's own order without a sort column of its own.
             */
            foreach ($picks as $index => $pick) {
                $item = $this->saver->saveGroup($list, $pick->group, $current);
                $item->forceFill(['created_at' => now()->subSeconds($index)])->save();
            }

            return $list;
        });
    }
}

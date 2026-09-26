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

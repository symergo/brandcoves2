<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\ListKind;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\RecipientGift;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What one giver gave one of their saved people.
 *
 * Asked about a {@see Recipient}, which belongs to exactly one owner, so "the
 * giver" is always that owner and nobody else. Two sources:
 *
 * 1. **Written down** (`recipient_gifts`): typed on the person's page, or an
 *    item from a list about them marked "I gave this".
 * 2. **The owner's own claims**, read live from `wishlist_items` by the
 *    owner's own claim hash, on the lists that are about this person.
 *
 * ## Only the giver's own claims, and only to the giver
 *
 * Invariant 4, from the other side. A claim is stored as a one-way hash of the
 * claimer, so the only claims this can find are the ones hashed from the
 * owner's own identity; `whereNotNull('claimed_by_hash')` is never written
 * here. What another giver claimed stays invisible to everyone, and the person
 * the list is about learns nothing: the history is read on pages only the
 * owner can open (the person's page, the Gift Finder, their own reminders).
 *
 * Claims are read live rather than copied in. A claim that is handed back
 * leaves the history at once, which is right (they are not getting it after
 * all), and no second copy of claim state exists to go stale. The cost: a
 * claim on a list that is later deleted leaves the history with it. "I gave
 * this" on the person's page is how to keep it.
 *
 * ## Which lists are about this person
 *
 * - lists that name this person as their recipient (the owner's own gift
 *   lists and group lists);
 * - when the person is linked to an account: their own wish lists, and lists
 *   other people made about the same account. That is where "I'll get this"
 *   on Mum's own wish list lives.
 */
final class GiftHistory
{
    /**
     * Newest first. Noted gifts win over the live claim on the same list item,
     * so an item claimed and then marked "I gave this" is one line.
     *
     * @return list<PastGift>
     */
    public function for(Recipient $recipient): array
    {
        $noted = $recipient->gifts()->with('group')->get();
        $notedItems = $noted->pluck('wishlist_item_id')->filter()->map(fn ($id) => (int) $id)->all();

        $gifts = $noted->map(fn (RecipientGift $gift) => new PastGift(
            source: PastGift::NOTED,
            title: $gift->group?->displayTitle() ?? $gift->title,
            groupId: $gift->group_id,
            year: $gift->given_year,
            brand: $gift->group?->brand,
            category: $gift->group?->category,
            image: $gift->group?->image_url,
            url: $gift->group?->path(),
            recordId: $gift->id,
            itemId: $gift->wishlist_item_id,
        ))->all();

        foreach ($this->ownClaims($recipient) as $item) {
            if (in_array((int) $item->id, $notedItems, true)) {
                continue;
            }

            $sent = $item->marked_sent_at !== null;

            $gifts[] = new PastGift(
                source: $sent ? PastGift::SENT : PastGift::CLAIMED,
                title: $item->displayTitle(),
                groupId: $item->group_id === null ? null : (int) $item->group_id,
                year: ($sent ? $item->marked_sent_at : $item->claimed_at)?->year,
                brand: $item->group?->brand,
                category: $item->group?->category,
                image: $item->group?->image_url ?? $item->snapshot_image_url,
                url: $item->productPath(),
                itemId: (int) $item->id,
            );
        }

        usort($gifts, fn (PastGift $a, PastGift $b) => [$b->year ?? 0, $b->recordId ?? 0, $b->itemId ?? 0]
            <=> [$a->year ?? 0, $a->recordId ?? 0, $a->itemId ?? 0]);

        return $gifts;
    }

    /**
     * Products never to suggest for this person again: every past gift's
     * product, the product it was merged into, and anything merged into it.
     *
     * A merge keeps the old product row and points it at the new one
     * (GroupMerger), so "the same thing" can be two ids. Both are left out.
     *
     * @param  list<PastGift>|null  $past  when the caller already has the history
     * @return list<int>
     */
    public function excludedGroupIds(Recipient $recipient, ?array $past = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            fn (PastGift $gift) => $gift->groupId,
            $past ?? $this->for($recipient),
        ))));

        if ($ids === []) {
            return [];
        }

        $winners = ProductGroup::query()->whereIn('id', $ids)->whereNotNull('merged_into_id')
            ->pluck('merged_into_id')->map(fn ($id) => (int) $id)->all();

        $losers = ProductGroup::query()->whereIn('merged_into_id', [...$ids, ...$winners])
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_unique([...$ids, ...$winners, ...$losers]));
    }

    /**
     * Items on the owner's own lists about this person that are not in the
     * history yet: what "I gave this" is offered beside on the person's page.
     *
     * Only lists the owner owns, and only accepted items. Nothing about claims
     * is read or shown: an item somebody else claimed looks exactly like one
     * nobody has.
     *
     * @return Collection<int, WishlistItem>
     */
    public function unmarkedItems(Recipient $recipient, int $limit = 30): Collection
    {
        $owned = Wishlist::query()
            ->where('recipient_id', $recipient->id)
            ->when(
                $recipient->owner_user_id !== null,
                fn ($q) => $q->where('owner_user_id', $recipient->owner_user_id),
                fn ($q) => $q->where('owner_anon_id', $recipient->owner_anon_id),
            )
            ->pluck('id');

        if ($owned->isEmpty()) {
            return collect();
        }

        $hash = $this->claimHash($recipient);

        return WishlistItem::query()
            ->whereIn('wishlist_id', $owned)
            ->whereNotNull('accepted_at')
            ->whereNotIn('id', $recipient->gifts()->whereNotNull('wishlist_item_id')->select('wishlist_item_id'))
            // The owner's own claims are in the history already.
            ->when($hash !== null, fn ($q) => $q->where(fn ($q) => $q
                ->whereNull('claimed_by_hash')
                ->orWhere('claimed_by_hash', '!=', $hash)))
            ->with('group')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * May the owner mark this item as given? Only an item from a list about
     * this person that they own, or one they claimed themselves. One already
     * marked answers yes, so pressing twice is harmless.
     */
    public function canMark(Recipient $recipient, WishlistItem $item): bool
    {
        if ($recipient->gifts()->where('wishlist_item_id', $item->id)->exists()) {
            return true;
        }

        if ($this->unmarkedItems($recipient, 500)->contains('id', $item->id)) {
            return true;
        }

        return $this->ownClaims($recipient)->contains('id', $item->id);
    }

    /**
     * The owner's own claims on the lists about this person.
     *
     * @return Collection<int, WishlistItem>
     */
    private function ownClaims(Recipient $recipient): Collection
    {
        $hash = $this->claimHash($recipient);

        if ($hash === null) {
            return collect();
        }

        return WishlistItem::query()
            ->whereIn('wishlist_id', $this->listsAbout($recipient))
            // Their own hash, never "any claim": see the class comment.
            ->where('claimed_by_hash', $hash)
            ->with('group')
            ->get();
    }

    /**
     * The lists that are about this person, whoever made them.
     *
     * @return Builder<Wishlist>
     */
    private function listsAbout(Recipient $recipient)
    {
        return Wishlist::query()
            ->select('id')
            ->where(function ($q) use ($recipient): void {
                $q->where('recipient_id', $recipient->id);

                if ($recipient->user_id !== null) {
                    $q->orWhereIn('recipient_id', Recipient::query()->select('id')->where('user_id', $recipient->user_id))
                        ->orWhere(fn ($q) => $q
                            ->where('owner_user_id', $recipient->user_id)
                            ->where('kind', ListKind::Mine->value));
                }
            });
    }

    /**
     * The owner's claim hash: the same value `SharedListController::claim()`
     * stores for them, computed the same way.
     */
    private function claimHash(Recipient $recipient): ?string
    {
        $identity = match (true) {
            $recipient->owner_user_id !== null => 'user:'.$recipient->owner_user_id,
            $recipient->owner_anon_id !== null => 'anon:'.$recipient->owner_anon_id,
            default => null,
        };

        return $identity === null ? null : WishlistItem::identityHash($identity);
    }
}

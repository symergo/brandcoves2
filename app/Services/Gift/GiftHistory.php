<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\ListKind;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What one giver gave one of their saved people.
 *
 * Asked about a {@see Recipient}, which belongs to exactly one owner, so "the
 * giver" is always that owner and nobody else. One source: **the owner's own
 * claims** ("Ik koop dit", and the same marked as sent), read live from
 * `wishlist_items` by the owner's own claim hash, on the lists that are about
 * this person. They keep those products out of new ideas for the person
 * ({@see excludedGroupIds()}) and are what "De volgende stap" follows on from.
 *
 * Until 2026-09-29 there was a second source, gifts the owner wrote down on
 * the person's page or marked "I gave this" (`recipient_gifts`, "Wat je gaf").
 * The owner removed it; the table is no longer read and goes in a later
 * release. See docs/features/gift-history.md.
 *
 * ## Only the giver's own claims, and only to the giver
 *
 * Invariant 4, from the other side. A claim is stored as a one-way hash of the
 * claimer, so the only claims this can find are the ones hashed from the
 * owner's own identity; `whereNotNull('claimed_by_hash')` is never written
 * here. What another giver claimed stays invisible to everyone, and the person
 * the list is about learns nothing: the history is read on pages only the
 * owner can open (the person's page, Find a gift, their own reminders).
 *
 * Claims are read live rather than copied in. A claim that is handed back
 * leaves the history at once, which is right (they are not getting it after
 * all), and no second copy of claim state exists to go stale. The cost: a
 * claim on a list that is later deleted leaves the history with it.
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
     * Newest first.
     *
     * @return list<PastGift>
     */
    public function for(Recipient $recipient): array
    {
        $gifts = [];

        foreach ($this->ownClaims($recipient) as $item) {
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

        usort($gifts, fn (PastGift $a, PastGift $b) => [$b->year ?? 0, $b->itemId ?? 0]
            <=> [$a->year ?? 0, $a->itemId ?? 0]);

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

<?php

declare(strict_types=1);

namespace App\Services\Wishlist;

use App\Enums\ListKind;
use App\Models\Recipient;
use App\Models\Wishlist;
use App\Support\CurrentMarket;
use App\Support\Owner;

/**
 * What you mean to spend on somebody, which is a fact about a list.
 *
 * The owner, 2026-10-05: a budget is not a property of a person but of a list.
 * Mama has one birthday list and one Christmas list, and what you spend on each
 * is yours to set per list. So the budget lives on `wishlists`, and everything
 * that used to read `recipients.budget_*` asks here.
 *
 * "The person's list" is the one Find a gift saves into: their newest list
 * about them (GiftResults::recipientList). The person's own budget columns are
 * gone (owner: "out of the code and the database"); the move copied every one
 * of them onto that person's lists first.
 */
final class ListBudget
{
    /** @return array{min: int|null, max: int|null} cents */
    public function forRecipient(Recipient $recipient): array
    {
        $list = $this->listFor($recipient);

        return ['min' => $list?->budget_min, 'max' => $list?->budget_max];
    }

    /**
     * Keep a budget for this person, on their list. With no list yet, an
     * account's person is given the one Find a gift would make for them
     * (GiftResults::recipientList), so a budget is never dropped. Outside a
     * request there is no market to make it in, and nothing is kept.
     */
    public function rememberFor(Recipient $recipient, ?int $min, ?int $max): void
    {
        $values = array_filter(['budget_min' => $min, 'budget_max' => $max], fn ($v) => $v !== null);

        if ($values === []) {
            return;
        }

        $list = $this->listFor($recipient);

        if ($list === null && $recipient->owner_user_id !== null && app()->bound(CurrentMarket::class)) {
            $list = app(ListMaker::class)->make(
                new Owner($recipient->owner, null),
                app(CurrentMarket::class),
                __('site.lists.for_person', ['name' => $recipient->name]),
                recipientId: $recipient->id,
            );
        }

        $list?->update($values);
    }

    private function listFor(Recipient $recipient): ?Wishlist
    {
        return Wishlist::query()
            ->where('recipient_id', $recipient->id)
            ->where('kind', ListKind::ForSomeone->value)
            ->latest('created_at')
            ->first();
    }
}

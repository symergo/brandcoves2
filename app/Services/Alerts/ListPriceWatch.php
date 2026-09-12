<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Models\Wishlist;
use App\Models\WishlistItem;

/**
 * Watching the prices on a whole list.
 *
 * The per-product alert asks "is this cheaper than when I pressed the button".
 * This asks, for every item on a list at once, "did it drop by at least the
 * percentage the owner chose since we last told them" — which is what the
 * bstore.be wishlist mail does, and what this brings over. One switch on the
 * list, one reference price per item, one digest a day.
 *
 * ## The reference moves
 *
 * After a drop is reported the reference becomes the new price, so the next
 * mail needs a further drop of the same size rather than repeating the first
 * one every morning. A rise also moves it up, silently: the person watching
 * wants to hear "20% cheaper than last week", and a reference frozen at the
 * lowest price ever seen would never say that again. A price that disappears
 * (no trackable in-stock offer) sets the reference to null, silently, and a
 * price that comes back is reported as "available again" — bstore's NA → NEW.
 *
 * ## What counts as the price
 *
 * `AlertEligibility::trackablePrice()`: the cheapest in-stock offer from a
 * source whose programme allows price tracking. COMPLIANCE: an Amazon offer
 * being cheapest can neither seed a reference nor trigger a line in the mail.
 * See docs/features/amazon-compliance.md.
 *
 * Cents throughout, and the threshold is compared in integer arithmetic
 * (`now * 100 <= was * (100 - percent)`), so a float never decides a mail.
 */
final class ListPriceWatch
{
    /**
     * The drops an owner may ask to hear about, in whole percent.
     *
     * A short list rather than a free number: 1% is noise on a €30 item and
     * 50% is a mail that never comes. The client offers exactly these and the
     * server refuses anything else.
     *
     * @var list<int>
     */
    public const PERCENTAGES = [5, 10, 15, 20, 30];

    public function __construct(private readonly AlertEligibility $eligibility) {}

    /**
     * Take a reference price for every item that has none yet.
     *
     * Silent by design: an item seeded today reports nothing until its price
     * moves from here. Called when the switch is turned on, and by the digest
     * job for anything added since. Returns how many were seeded.
     */
    public function seed(Wishlist $list): int
    {
        $seeded = 0;

        $list->items()
            ->whereNull('watch_seeded_at')
            ->each(function (WishlistItem $item) use (&$seeded): void {
                $this->seedItem($item);
                $seeded++;
            });

        return $seeded;
    }

    /** One item, at today's trackable price (null when it has none). */
    public function seedItem(WishlistItem $item): void
    {
        $item->update([
            'watch_reference_price' => $item->group_id === null
                ? null
                : $this->eligibility->trackablePrice((int) $item->group_id),
            'watch_seeded_at' => now(),
        ]);
    }

    /**
     * The switch went off: forget every reference.
     *
     * So that switching it on again next year starts from that day's prices
     * instead of reporting everything that happened in between.
     */
    public function forget(Wishlist $list): void
    {
        $list->items()->update([
            'watch_reference_price' => null,
            'watch_seeded_at' => null,
        ]);
    }

    /**
     * What moved since the last pass, for every seeded item with a product.
     *
     * Every entry carries the item and the new price so {@see apply()} can
     * move the references; only `drop` and `back` are worth a line in a mail.
     * `percent` is the drop as a whole number for display, null when there is
     * no "was" to measure from.
     *
     * @return list<array{item: WishlistItem, kind: string, was: int|null, now: int|null, percent: int|null}>
     */
    public function changes(Wishlist $list): array
    {
        $threshold = $list->price_watch_percent;

        if ($threshold === null) {
            return [];
        }

        $changes = [];

        $list->items()
            ->whereNotNull('group_id')
            ->whereNotNull('watch_seeded_at')
            ->each(function (WishlistItem $item) use ($threshold, &$changes): void {
                $was = $item->watch_reference_price === null ? null : (int) $item->watch_reference_price;
                $now = $this->eligibility->trackablePrice((int) $item->group_id);
                $kind = self::classify($was, $now, (int) $threshold);

                if ($kind === null) {
                    return;
                }

                $changes[] = [
                    'item' => $item,
                    'kind' => $kind,
                    'was' => $was,
                    'now' => $now,
                    'percent' => $was !== null && $now !== null && $was > 0
                        ? (int) round(($was - $now) * 100 / $was)
                        : null,
                ];
            });

        return $changes;
    }

    /**
     * Pure: what one price movement means.
     *
     * - `drop`: cheaper by at least the threshold. Reported.
     * - `back`: had no trackable price, has one now. Reported.
     * - `gone`: had a price, has none now. Silent; sets up a later `back`.
     * - `up`:   dearer. Silent; the reference follows it so the next drop is
     *           measured from the new, higher price.
     * - null:   nothing worth moving the reference for.
     */
    public static function classify(?int $was, ?int $now, int $percent): ?string
    {
        if ($was === null && $now === null) {
            return null;
        }

        if ($was === null) {
            return 'back';
        }

        if ($now === null) {
            return 'gone';
        }

        if ($now * 100 <= $was * (100 - $percent)) {
            return 'drop';
        }

        return $now > $was ? 'up' : null;
    }

    /**
     * Move every reference to the price it was compared with.
     *
     * Reported or not: `gone` and `up` move it too, which is the whole reason
     * they are returned by {@see changes()} at all.
     *
     * @param  list<array{item: WishlistItem, kind: string, was: int|null, now: int|null, percent: int|null}>  $changes
     */
    public function apply(array $changes): void
    {
        foreach ($changes as $change) {
            $change['item']->update(['watch_reference_price' => $change['now']]);
        }
    }
}

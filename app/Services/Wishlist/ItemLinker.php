<?php

declare(strict_types=1);

namespace App\Services\Wishlist;

use App\Models\ProductGroup;
use App\Models\WishlistItem;
use App\Services\Alerts\ListPriceWatch;
use App\Services\Images\ImageStore;
use App\Services\PageReading\PageProduct;

/**
 * Fill in a hand-written item from what we found out about it.
 *
 * Two outcomes, and both change the row **in place**: its id is what a claim,
 * a vote, a pledge and the highlight on the list page all point at, so
 * replacing it with a fresh catalogue row would quietly detach them.
 *
 * - {@see link()}: we hold the product. The item becomes a catalogue item, the
 *   same as if it had been picked from search: offers, current price, stock.
 * - {@see fill()}: we do not. What the shop's page said fills the gaps, and
 *   nothing the person typed is overwritten.
 */
class ItemLinker
{
    public function __construct(private readonly ImageStore $images) {}

    /**
     * Make a hand-written item a catalogue item.
     *
     * False when the list already holds that product: two rows for one product
     * would break the list's uniqueness, and the person's typed row is kept
     * rather than silently merged away.
     */
    public function link(WishlistItem $item, ProductGroup $group): bool
    {
        $duplicate = WishlistItem::query()
            ->where('wishlist_id', $item->wishlist_id)
            ->where('group_id', $group->id)
            ->whereKeyNot($item->getKey())
            ->exists();

        if ($duplicate) {
            return false;
        }

        $this->images->forget($item->snapshot_image_url);

        $item->forceFill([
            'group_id' => $group->id,
            // No longer hand-written: the snapshot now records what the
            // catalogue said, which is what `manual` must stop claiming.
            'source' => null,
            'external_id' => null,
            'snapshot_title' => $this->keepsTypedTitle($item) ? $item->snapshot_title : $group->displayTitle(),
            'snapshot_image_url' => $group->image_url,
            'snapshot_price' => $group->min_price,
            'snapshot_url' => $group->path(),
            'link_status' => 'linked',
        ])->save();

        // Same as ItemSaver::saveGroup: a list that watches prices watches
        // the newcomer from today's price.
        $list = $item->wishlist;

        if ($list !== null && $list->watchesPrices() && $item->watch_seeded_at === null) {
            app(ListPriceWatch::class)->seedItem($item);
        }

        $list?->touch();

        return true;
    }

    /** Fill what is missing from what the page said. */
    public function fill(WishlistItem $item, PageProduct $page, string $currency): void
    {
        $changes = ['link_status' => 'read'];

        if (! $this->keepsTypedTitle($item)) {
            $changes['snapshot_title'] = $page->title;
        }

        // A price in another currency is not a price in this market; showing
        // "£40" as "€40" is a wrong number, which is worse than none.
        if ($item->snapshot_price === null && $page->price !== null && $page->currency === $currency) {
            $changes['snapshot_price'] = $page->price;
        }

        if ($item->gtin === null && $page->gtin !== null) {
            $changes['gtin'] = $page->gtin;
        }

        if ($item->snapshot_image_url === null && $page->imageUrl !== null) {
            $changes['snapshot_image_url'] = $this->images->fromUrl($page->imageUrl);
        }

        $item->forceFill($changes)->save();
        $item->wishlist?->touch();
    }

    /**
     * Did the person write the title, or is it the placeholder we showed while
     * reading?
     *
     * An item saved from a bare link is titled with the shop's host name until
     * the page has been read ("coolblue.nl"). Anything else was typed, and the
     * person's words win over the shop's.
     */
    private function keepsTypedTitle(WishlistItem $item): bool
    {
        return trim((string) $item->snapshot_title) !== WishlistItem::placeholderTitle($item->snapshot_url);
    }
}

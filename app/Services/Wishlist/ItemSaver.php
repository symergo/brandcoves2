<?php

declare(strict_types=1);

namespace App\Services\Wishlist;

use App\Enums\Source;
use App\Models\ProductGroup;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Support\CurrentMarket;

/**
 * The one place a product becomes a list entry.
 *
 * Both ways of filling a list end here — a typed search and a suggestion from
 * the engine — and that is deliberate. Two save paths means the Amazon rule
 * gets applied in one of them and forgotten in the other, and the forgotten one
 * is a compliance breach that looks exactly like a working feature.
 */
class ItemSaver
{
    /**
     * Save a product we hold a group for.
     *
     * Snapshot, not just a reference: a feed can drop or rename this product
     * tomorrow, and the list must still show what the person actually chose, at
     * the price they saw. That is the record of their decision and it should not
     * silently rewrite itself.
     */
    public function saveGroup(Wishlist $list, ProductGroup $group, CurrentMarket $current, ?string $note = null): WishlistItem
    {
        $item = WishlistItem::updateOrCreate(
            ['wishlist_id' => $list->id, 'group_id' => $group->id],
            [
                'snapshot_title' => $group->title,
                'snapshot_image_url' => $group->image_url,
                'snapshot_price' => $group->min_price,
                'snapshot_url' => $current->url("p/{$group->id}/{$group->slug}"),
                'note' => $note,
            ],
        );

        $list->touch();

        return $item;
    }

    /**
     * Save a product from a source we do not mirror.
     *
     * Live bol results and Amazon products are both reachable from search and
     * were both unsaveable until now, for the same reason: no stored group. They
     * are stored differently from each other, though, and the difference is not
     * cosmetic.
     *
     * `Source::allowsCatalogueStorage()` is the gate. For bol we may keep a
     * snapshot, so the entry behaves like every other one. For Amazon we may
     * store **the decision and nothing else** — the ASIN — and re-fetch title,
     * image, price and availability at render (invariant #6). Writing a snapshot
     * "just in case" would be a mirror of the catalogue, which is precisely what
     * the rule forbids.
     *
     * @param  array{title?: string|null, image_url?: string|null, price?: int|null, url?: string|null}  $snapshot
     */
    public function saveExternal(
        Wishlist $list,
        Source $source,
        string $externalId,
        array $snapshot = [],
        ?string $note = null,
    ): WishlistItem {
        $storable = $source->allowsCatalogueStorage();

        $item = WishlistItem::updateOrCreate(
            [
                'wishlist_id' => $list->id,
                'group_id' => null,
                'source' => $source->value,
                'external_id' => $externalId,
            ],
            [
                // A title is NOT NULL, and a live-rendered row genuinely has
                // none to store. The placeholder is never displayed — see
                // WishlistItem::rendersLive() — but the column has to hold
                // something, and an empty string would read as "we lost it".
                'snapshot_title' => $storable
                    ? (string) ($snapshot['title'] ?? '')
                    : $source->label(),
                'snapshot_image_url' => $storable ? ($snapshot['image_url'] ?? null) : null,
                'snapshot_price' => $storable ? ($snapshot['price'] ?? null) : null,
                'snapshot_url' => $storable ? ($snapshot['url'] ?? null) : null,
                'note' => $note,
            ],
        );

        $list->touch();

        return $item;
    }
}

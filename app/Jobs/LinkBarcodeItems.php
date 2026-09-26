<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ProductGroup;
use App\Models\WishlistItem;
use App\Services\Wishlist\ItemLinker;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A scanned barcode we did not know, joined to its product once we do.
 *
 * Somebody in a shop scans a thing no shop in our catalogue sells yet, and
 * saves it anyway (AddProduct, "add it anyway"). The item keeps the barcode.
 * Twice a day, after grouping has run, this looks for a product with that
 * barcode in the list's market and, when there is one, turns the item into a
 * catalogue item: the picture, the price and the other shops arrive by
 * themselves.
 *
 * Market-scoped, per invariant 2: a barcode sold only in another market is a
 * different offer set, with different tax and shipping.
 */
class LinkBarcodeItems implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $uniqueFor = 3600;

    public function handle(ItemLinker $linker): void
    {
        WishlistItem::query()
            ->whereNotNull('gtin')
            ->whereNull('group_id')
            ->with('wishlist')
            ->chunkById(500, function ($items) use ($linker): void {
                foreach ($items as $item) {
                    if ($item->wishlist === null || ! $item->isManual()) {
                        continue;
                    }

                    // GTIN-13 is the identity key of an EAN-grouped product.
                    $group = ProductGroup::query()
                        ->forMarket($item->wishlist->market)
                        ->where('identity_key', $item->gtin)
                        ->first()?->followMerge();

                    if ($group !== null) {
                        $linker->link($item, $group);
                    }
                }
            });
    }
}

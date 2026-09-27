<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Source;
use App\Jobs\Concerns\RunsOneAtATime;
use App\Models\ProductGroup;
use App\Models\WishlistItem;
use App\Services\Wishlist\ItemLinker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Facades\DB;

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
 *
 * The last step of the catalogue run since 2026-09-28, and one run at a time
 * (see RunsOneAtATime).
 */
#[Queue('batch')]
class LinkBarcodeItems implements ShouldQueue
{
    use Queueable, RunsOneAtATime;

    public int $timeout = 600;

    protected function overlapKey(): string
    {
        return 'all';
    }

    /**
     * The matches come from ONE join, not a lookup per item (2026-09-28).
     *
     * It used to walk every unlinked barcode item and ask `product_groups`
     * about each, twice a day, and nearly all of those asks found nothing:
     * the items are unlinked precisely because no shop sells the thing. The
     * join finds only the items that now have a product, through the
     * `(market, identity_key)` unique index, and the loop below touches only
     * those.
     */
    public function handle(ItemLinker $linker): void
    {
        $matches = DB::table('wishlist_items as wi')
            ->join('wishlists as w', 'w.id', '=', 'wi.wishlist_id')
            // GTIN-13 is the identity key of an EAN-grouped product.
            ->join('product_groups as g', function ($join): void {
                $join->on('g.market', '=', 'w.market')->on('g.identity_key', '=', 'wi.gtin');
            })
            ->whereNotNull('wi.gtin')
            ->whereNull('wi.group_id')
            ->where('wi.source', Source::Manual->value)
            ->orderBy('wi.id')
            ->get(['wi.id as item_id', 'g.id as group_id']);

        foreach ($matches as $match) {
            $item = WishlistItem::query()->find($match->item_id);
            $group = ProductGroup::query()->find($match->group_id)?->followMerge();

            if ($item !== null && $group !== null && $item->group_id === null) {
                $linker->link($item, $group);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ProductGroup;
use App\Models\WishlistItem;
use App\Services\Identity\IdentityResolver;
use App\Services\PageReading\FetchRefused;
use App\Services\PageReading\LinkRouter;
use App\Services\PageReading\PageProduct;
use App\Services\PageReading\PageReader;
use App\Services\PageReading\SlugTitle;
use App\Services\Wishlist\ItemLinker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Find out what a pasted link is, and fill in the item it was pasted into.
 *
 * Queued, always: it may call a connector and may fetch a shop's page, and a
 * visitor's request must wait for neither. The list page shows the item at once
 * under the shop's name and asks again for a few seconds (see `Lists/Show`).
 *
 * The order is {@see LinkRouter}'s: our catalogue and connectors first, the
 * page only for a shop nothing recognises. Whatever happens, the item survives
 * as the person typed it; the worst case is that nothing gets filled in.
 */
class ReadItemLink implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly int $itemId) {}

    public function handle(LinkRouter $router, PageReader $reader, ItemLinker $linker): void
    {
        $item = WishlistItem::query()->with('wishlist')->find($this->itemId);

        // Deleted, edited back to no link, or already done by an earlier try.
        if ($item === null || $item->wishlist === null || $item->link_status !== 'pending' || ! $item->isManual()) {
            return;
        }

        $url = $item->externalUrl();

        if ($url === null) {
            $item->forceFill(['link_status' => 'skipped'])->save();

            return;
        }

        $market = $item->wishlist->market;

        try {
            $route = $router->resolve($url, $market);

            if ($route->group !== null && $linker->link($item, $route->group)) {
                return;
            }

            if (! $route->mayReadPage()) {
                if ($route->offer !== null && $route->offer->source->allowsCatalogueStorage()) {
                    $linker->fill($item, new PageProduct(
                        title: $route->offer->title,
                        imageUrl: $route->offer->imageUrl,
                        price: $route->offer->price,
                        currency: $route->offer->currency,
                        gtin: $route->offer->ean,
                    ), $market->currency());

                    return;
                }

                $item->forceFill(['link_status' => 'skipped'])->save();

                return;
            }

            $page = $reader->read($url);

            if ($page === null) {
                $this->giveUp($item, $url);

                return;
            }

            // The page's barcode, or its brand and title, may name a product
            // we already hold in this market — the same identity rules a feed
            // row is grouped by, so this can never merge more than a feed can.
            $identity = IdentityResolver::resolve($page->gtin, $page->brand, $page->title);
            $group = $identity === null
                ? null
                : ProductGroup::query()->forMarket($market)->where('identity_key', $identity->key)->first();

            if ($group !== null && $linker->link($item, $group)) {
                return;
            }

            $linker->fill($item, $page, $market->currency());
        } catch (FetchRefused $e) {
            /*
             * Our own per-shop limit, or a shop's bot protection answering
             * 403/429 at random: ask again in 30 s, then 60 s. The item stays
             * pending, which is the truth. See PageReader::isPassing().
             */
            if (PageReader::isPassing($e) && $this->attempts() < $this->tries) {
                $this->release(30 * $this->attempts());

                return;
            }

            Log::info('pasted link not read', ['item' => $item->id, 'reason' => $e->getMessage()]);
            $this->giveUp($item, $url);
        }
    }

    /**
     * The page could not be read. Keep what the person typed, but a title that
     * is still the shop's host gets the product's name from the link itself
     * when the link carries one ("Bialetti moka express percolator 6 kops").
     */
    private function giveUp(WishlistItem $item, string $url): void
    {
        $changes = ['link_status' => 'failed'];

        if (trim((string) $item->snapshot_title) === WishlistItem::placeholderTitle($item->snapshot_url)
            && ($title = SlugTitle::fromUrl($url)) !== null) {
            $changes['snapshot_title'] = $title;
        }

        $item->forceFill($changes)->save();
        $item->wishlist?->touch();
    }

    /** After the last try: stop the list page waiting on it. */
    public function failed(?Throwable $e): void
    {
        $item = WishlistItem::query()->whereKey($this->itemId)->where('link_status', 'pending')->first();

        if ($item !== null) {
            $this->giveUp($item, (string) $item->snapshot_url);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ProductGroup;
use App\Models\WishlistItem;
use App\Services\Identity\IdentityResolver;
use App\Services\Images\ImageStore;
use App\Services\PageReading\FetchRefused;
use App\Services\PageReading\IframelyReader;
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

    public function handle(LinkRouter $router, PageReader $reader, ItemLinker $linker, IframelyReader $iframely, ImageStore $images): void
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

            try {
                $page = $reader->read($url);
            } catch (FetchRefused $e) {
                /*
                 * The shop's bot protection said no (403, 429, a timeout).
                 * Before waiting to ask again, ask Iframely, which shops let
                 * through the way they let WhatsApp's link previews through.
                 * Our own per-shop limit ("busy") and a refusal that will not
                 * change (a 404) are not a reason to spend a call.
                 */
                $page = PageReader::isPassing($e) && $e->reason !== 'busy' ? $iframely->read($url) : null;

                if ($page === null) {
                    throw $e;
                }
            }

            // Read, but no product on it that we could make out: a page built
            // by script, or one without the tags shops publish for Google.
            $page ??= $iframely->read($url);

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
                : ProductGroup::query()->forMarket($market)->where('identity_key', $identity->key)->first()?->followMerge();

            if ($group !== null && $linker->link($item, $group)) {
                return;
            }

            $linker->fill($item, $page, $market->currency());
            $this->pictureThroughIframely($item, $url, $iframely, $images);
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
     * The page named a picture but its server would not give it to us (de
     * Bijenkorf's refuses us as its pages do). Iframely's thumbnail endpoint
     * sends the image itself, which is stored as ours like any other.
     */
    private function pictureThroughIframely(WishlistItem $item, string $url, IframelyReader $iframely, ImageStore $images): void
    {
        if ($item->snapshot_image_url !== null || ! $iframely->enabled()) {
            return;
        }

        $bytes = $iframely->thumbnail($url);
        $stored = $bytes === null ? null : $images->store($bytes);

        if ($stored !== null) {
            $item->forceFill(['snapshot_image_url' => $stored])->save();
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

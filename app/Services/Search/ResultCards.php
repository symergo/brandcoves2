<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\Market;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Amazon\AmazonMatches;
use App\Services\Images\ImageProxy;
use App\Services\Images\ShopImageSizes;

/**
 * What a results page sends for its products: the cards of the grid, a page of
 * them, and the by-store view's columns.
 *
 * Moved out of SearchController on 2026-11-24 so the brand page sends the same
 * (owner: "The brand page should look the same as the search page"; then "I don't
 * see a grid/store button"). BrandController had its own copy of the card, without
 * the Amazon link, and no columns at all.
 */
final class ResultCards
{
    /**
     * One page and whether another follows. No total and no last page (owner's
     * decision, 2026-09-27): nothing counts the matches, and the page says
     * neither a number nor a word standing in for one. See
     * SearchService::page().
     */
    public function page(SearchResult $result, Market $market): array
    {
        return [
            'empty' => $result->isEmpty(),
            'currentPage' => $result->groups->currentPage(),
            'hasMore' => $result->groups->hasMorePages(),
            'items' => $this->cards($result->groups->items(), $market),
        ];
    }

    /**
     * One column per shop, each with the shop's own mark.
     *
     * `faviconUrl()` is a guess derived from the merchant's domain unless a
     * `logo_url` was stored, so it is sent as a maybe and the browser decides —
     * the client hides the image `onError`. It deliberately does not fall back
     * to the affiliate network's icon: Awin's mark on every column identifies
     * nothing, on the one view whose whole job is telling shops apart.
     *
     * @param  list<array{merchant: Merchant, groups: list<ProductGroup>}>  $lanes
     * @return list<array<string, mixed>>
     */
    public function lanes(array $lanes): array
    {
        return array_map(
            fn (array $lane) => [
                'shop' => $lane['merchant']->displayName(),
                'logo' => $lane['merchant']->faviconUrl(),
                // Where the column's links go, said once in its header.
                'domain' => $this->shopDomain($lane['offers'][0] ?? null),
                'items' => array_values(array_filter(array_map(
                    fn (ProductGroup $group, Product $offer) => $this->laneCard($group, $offer),
                    $lane['groups'],
                    $lane['offers'],
                ))),
            ],
            $lanes,
        );
    }

    /** The shop's own address without "www.", from its deep link; null when it has none. */
    private function shopDomain(?Product $offer): ?string
    {
        $domain = $offer?->merchantDomain();

        return $domain === null ? null : (string) preg_replace('/^www\./', '', $domain);
    }

    /**
     * A row in a shop's column: the card, plus that shop's offer for it.
     *
     * The row opens the shop itself (owner, 2026-11-07: "link the products on
     * the winkel view directly to the shop (affiliate link)"), so it carries
     * the shop's own price rather than the cheapest of all shops.
     *
     * The shop's own affiliate link, as a plain anchor, never our redirector
     * (owner, the same day: "don't use clickouts (not allowed by amazon), it
     * should be clear that a link goes to the store"). So the URL is checked
     * here, where the redirector would have (invariant 5:
     * `hasSafeAffiliateUrl()`, https only), and the click is reported by the
     * browser through the beacon, as Amazon's links on the product page are.
     * The column's header names the shop's address once (`shopDomain()`): the
     * affiliate URL itself is a network's tracking address (awin1.com) and
     * says nothing about the shop. No safe link, no row: an offer with an
     * unsafe URL is left out rather than drawn dead.
     *
     * @return array<string, mixed>|null
     */
    private function laneCard(ProductGroup $group, Product $offer): ?array
    {
        if (! $offer->hasSafeAffiliateUrl()) {
            return null;
        }

        return [
            ...$this->card($group),
            'shop' => [
                'url' => $offer->affiliate_url,
                'price' => $offer->price,
                'direct' => true,
                'beacon' => route('click.beacon', ['market' => $offer->market->value]),
                'offerId' => $offer->id,
                // Where the programme asks for it, as on the product page.
                'needsPriceTimestamp' => $offer->source->requiresPriceTimestamp(),
            ],
        ];
    }

    /**
     * A page of cards, each knowing its cheapest offer (one query for the
     * page, CheapestOffer): the card's "Koop bij …" opens that shop.
     *
     * @param  iterable<ProductGroup>  $groups
     * @return list<array<string, mixed>>
     */
    public function cards(iterable $groups, Market $market): array
    {
        $groups = collect($groups)->values();
        CheapestOffer::attach($groups);

        // "Bekijk op Amazon" on the cards whose barcode is already matched, one
        // query for the page. A search page queues no lookups (owner, 2026-11-23:
        // "don't do a batch"): only a product page a person opens asks, one
        // barcode at a time, so credits follow interest rather than result lists.
        $amazon = app(AmazonMatches::class)->links($market, $groups);

        return $groups->map(fn (ProductGroup $group) => $this->card($group) + [
            'amazon' => $group->identity_kind?->value === 'ean' ? ($amazon[$group->identity_key] ?? null) : null,
        ])->all();
    }

    /**
     * The offer-comparison card.
     *
     * Everything a shopper needs to decide whether to open the product: what it
     * is, the cheapest price, how many shops have it, and whether that price is
     * actually a good one.
     *
     * @return array<string, mixed>
     */
    public function card(ProductGroup $group): array
    {
        return [
            'id' => $group->id,
            'title' => $group->displayTitle(),
            'slug' => $group->slug,
            'brand' => $group->brand,
            'image' => $group->image_url,
            // The same picture on our own address at the card's size, or null
            // when it keeps the shop's URL (docs/features/image-proxy.md).
            'imageToken' => app(ImageProxy::class)->token($group->image_url),
            // The shop's own picture per width, for the srcset while the proxy is
            // off (ShopImageSizes, 2026-10-25). Null where the server takes no size.
            'imageSizes' => ShopImageSizes::for($group->image_url),
            // Cents cross the wire exactly as stored; the client formats them
            // for the market, so a float never enters the pipeline.
            'minPrice' => $group->min_price,
            'maxPrice' => $group->max_price,
            'offerCount' => $group->offer_count,
            'merchantCount' => $group->merchant_count,
            'inStock' => $group->in_stock,
            'discountPercent' => $group->discountPercent(),
            // The cheapest shop, when CheapestOffer::attach() ran: the card opens it.
            'buy' => CheapestOffer::present($group),
        ];
    }
}

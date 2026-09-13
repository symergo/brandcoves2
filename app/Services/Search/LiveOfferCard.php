<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\Availability;
use App\Models\Merchant;
use App\Services\Connectors\Offer;

/**
 * A live offer as a card: what the page needs to show one and to keep one.
 *
 * Lifted out of `BrandController` on 2026-09-13, when the search landing
 * started showing live offers too. One shape for both pages, so the save
 * path and the price rules cannot drift between them.
 */
final class LiveOfferCard
{
    /** @return array<string, mixed> */
    public static function present(Offer $offer): array
    {
        return [
            'title' => $offer->title,
            'url' => $offer->affiliateUrl,
            'image' => $offer->imageUrl,
            'price' => $offer->price,
            'merchant' => $offer->merchantName === null
                    ? $offer->source->label()
                    : Merchant::withoutCountrySuffix($offer->merchantName),

            /*
             * What it takes to keep one of these.
             *
             * `WishlistItemController::store()` accepts `source` + `external_id`,
             * and `ItemSaver::saveExternal()` decides per source what may be
             * stored. Passing the snapshot fields is safe because the server
             * discards them for a source that may not be mirrored (invariant
             * #6); they are hints, not instructions.
             */
            'source' => $offer->source->value,
            'externalId' => $offer->externalId,
            'inStock' => $offer->availability === Availability::InStock,
            'needsPriceTimestamp' => $offer->source->requiresPriceTimestamp(),
            'directLink' => $offer->source->requiresDirectLink(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Product;
use App\Models\ProductGroup;
use Illuminate\Support\Facades\DB;

/**
 * The offer a product card's "Koop bij …" opens: the cheapest one with a link
 * we may send somebody to (owner, 2026-11-07: the whole card goes to the shop,
 * "Bekijk alle aanbiedingen" under it to our product page).
 *
 * The cheapest, because that is the price the card shows (`min_price`), so
 * the shop the card names is the one that price belongs to.
 *
 * One query for a whole page of cards, not one per card: `attach()` sets the
 * offer on each group as the `cheapestOffer` relation, and the card reads it
 * when it is there. A group without one (every offer out, or every link
 * unsafe) keeps the old card, which opens our product page.
 */
final class CheapestOffer
{
    /**
     * @param  iterable<ProductGroup>  $groups
     */
    public static function attach(iterable $groups): void
    {
        $byId = collect($groups)->keyBy('id');

        if ($byId->isEmpty()) {
            return;
        }

        /*
         * DISTINCT ON keeps the first row per group in the ORDER BY: the lowest
         * price, then the lowest id so a tie always picks the same shop. Only
         * https links here; `hasSafeAffiliateUrl()` checks each one again below,
         * because this is the link a browser will follow (invariant 5).
         */
        $ids = DB::table('products')
            ->selectRaw('DISTINCT ON (group_id) id')
            ->whereIn('group_id', $byId->keys())
            ->where('status', 'active')
            ->whereNotNull('price')
            ->where('affiliate_url', 'like', 'https://%')
            ->orderBy('group_id')
            ->orderBy('price')
            ->orderBy('id')
            ->pluck('id');

        $offers = Product::query()->with('merchant')->whereIn('id', $ids)->get();

        foreach ($offers as $offer) {
            if ($offer->hasSafeAffiliateUrl()) {
                $byId->get($offer->group_id)?->setRelation('cheapestOffer', $offer);
            }
        }
    }

    /**
     * What the card needs to send somebody to the shop, or null without one.
     *
     * A plain anchor to the shop's own affiliate link, never our redirector
     * (owner, 2026-11-07: "don't use clickouts"); the click is reported by the
     * browser through the beacon.
     *
     * @return array{url: string, shop: string, domain: string|null, beacon: string, offerId: int}|null
     */
    public static function present(ProductGroup $group): ?array
    {
        $offer = $group->relationLoaded('cheapestOffer') ? $group->getRelation('cheapestOffer') : null;

        if (! $offer instanceof Product || ! $offer->hasSafeAffiliateUrl()) {
            return null;
        }

        $domain = $offer->merchantDomain();

        return [
            'url' => (string) $offer->affiliate_url,
            'shop' => $offer->merchant?->displayName() ?? $offer->source->label(),
            'domain' => $domain === null ? null : (string) preg_replace('/^www\./', '', $domain),
            'beacon' => route('click.beacon', ['market' => $offer->market->value]),
            'offerId' => $offer->id,
        ];
    }
}

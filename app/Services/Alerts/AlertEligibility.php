<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Enums\Availability;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\Product;
use App\Models\ProductGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Decides whether a product may carry a price or restock alert.
 *
 * A product group can hold offers from several sources with different rules, so
 * "can I alert on this?" is not a property of the group — it is a property of
 * the offers underneath it.
 *
 * COMPLIANCE: Amazon offers can never support an alert. An alert needs retained
 * pricing to detect a change (Amazon requires pricing to be discarded within 24
 * hours) and is delivered by email (Amazon prohibits its content in email).
 * See docs/features/amazon-compliance.md.
 */
class AlertEligibility
{
    /**
     * What an offer row has to carry to answer "may this be watched".
     *
     * Everything except `description` — and, by not being `*`, the stored
     * `search_vector` — which are the two largest columns on the table and
     * were pulled three times per product page (eligible, excluded, and the
     * baseline on create) to read `source`, `price` and `availability`.
     *
     * @var list<string>
     */
    private const COLUMNS = [
        'id', 'source', 'external_id', 'market', 'merchant_id', 'feed_id', 'group_id',
        'title', 'brand', 'price', 'reference_price', 'currency', 'image_url',
        'affiliate_url', 'availability', 'ean', 'status', 'first_seen_at', 'last_seen_at',
        'created_at', 'updated_at',
    ];

    /**
     * Whether an alert can be offered at all.
     *
     * True when at least one offer comes from a source that permits it — the
     * alert then tracks those offers and silently ignores the rest, which is
     * both compliant and the honest thing to show: the shopper is told what is
     * being watched.
     */
    public function isEligible(ProductGroup $group): bool
    {
        return $group->offers()
            ->select(self::COLUMNS)
            ->where('status', ProductStatus::Active->value)
            ->get()
            ->contains(fn ($offer) => $offer->source->allowsPriceAlerts());
    }

    /**
     * The subset of offers an alert may watch.
     *
     * Used both to decide the alert's baseline price and to explain the scope
     * in the UI: promising to watch "the cheapest price" and then quietly not
     * watching one of the shops would be a lie by omission.
     *
     * @return Collection<int, Product>
     */
    public function watchableOffers(ProductGroup $group): Collection
    {
        return $group->offers()
            ->select(self::COLUMNS)
            ->where('status', ProductStatus::Active->value)
            ->get()
            ->filter(fn ($offer) => $offer->source->allowsPriceAlerts())
            ->values();
    }

    /** Sources excluded from an alert on this product, for the UI to disclose. */
    public function excludedSources(ProductGroup $group): array
    {
        return $group->offers()
            ->select(self::COLUMNS)
            ->where('status', ProductStatus::Active->value)
            ->get()
            ->reject(fn ($offer) => $offer->source->allowsPriceAlerts())
            ->map(fn ($offer) => $offer->source->label())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The cheapest offer we are allowed to build an alert on, in cents.
     *
     * Not simply product_groups.min_price: that aggregate includes every
     * source, and a source that disallows price tracking must not be able to
     * trigger a notification. Shared by the per-product alerts
     * (RefreshWishlistedProducts) and the per-list watch (ListPriceWatch), so
     * both answer "what does it cost today" the same way.
     */
    public function trackablePrice(int $groupId): ?int
    {
        $trackable = array_values(array_filter(
            Source::values(),
            fn (string $s) => Source::from($s)->allowsPriceAlerts(),
        ));

        $price = DB::table('products')
            ->where('group_id', $groupId)
            ->where('status', ProductStatus::Active->value)
            ->where('availability', Availability::InStock->value)
            ->whereIn('source', $trackable)
            ->min('price');

        return $price === null ? null : (int) $price;
    }
}

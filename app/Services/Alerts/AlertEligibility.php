<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductGroup;
use Illuminate\Support\Collection;

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
            ->where('status', ProductStatus::Active->value)
            ->get()
            ->filter(fn ($offer) => $offer->source->allowsPriceAlerts())
            ->values();
    }

    /** Sources excluded from an alert on this product, for the UI to disclose. */
    public function excludedSources(ProductGroup $group): array
    {
        return $group->offers()
            ->where('status', ProductStatus::Active->value)
            ->get()
            ->reject(fn ($offer) => $offer->source->allowsPriceAlerts())
            ->map(fn ($offer) => $offer->source->label())
            ->unique()
            ->values()
            ->all();
    }
}

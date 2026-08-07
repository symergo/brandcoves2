<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AlertState;
use App\Enums\Source;
use App\Models\Notification;
use App\Models\PriceAlert;
use App\Models\RestockAlert;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Re-checks the products people actually care about, and fires alerts.
 *
 * Feed ingestion runs twice a day over the whole catalogue; this runs over the
 * tiny subset that is on someone's list or under an alert, so it can run more
 * often and notice a drop sooner. It reads what ingestion already wrote rather
 * than re-fetching, so it costs a query rather than a download.
 */
class RefreshWishlistedProducts implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function handle(): void
    {
        $priceDrops = $this->firePriceAlerts();
        $restocks = $this->fireRestockAlerts();

        Log::info('Wishlist refresh complete', [
            'price_drops' => $priceDrops,
            'restocks' => $restocks,
        ]);
    }

    /**
     * Notify when a watched product is cheaper than when the alert was set.
     *
     * COMPLIANCE: only offers from sources that permit price tracking count
     * toward the current price. An Amazon offer being cheapest cannot trigger
     * an alert. See docs/features/amazon-compliance.md.
     */
    private function firePriceAlerts(): int
    {
        $fired = 0;

        PriceAlert::query()
            ->where('state', AlertState::Active->value)
            ->with('group')
            ->chunkById(200, function ($alerts) use (&$fired): void {
                foreach ($alerts as $alert) {
                    $current = $this->trackablePrice($alert->group_id);

                    if ($current === null) {
                        continue;
                    }

                    // A target beats the baseline when set: someone who asked
                    // for "under €300" does not want to hear about €5 off.
                    $threshold = $alert->target_price ?? $alert->baseline_price;

                    if ($current >= $threshold) {
                        continue;
                    }

                    $this->notify($alert, $current, 'price_drop');
                    $alert->update([
                        'state' => AlertState::Triggered->value,
                        'notified_at' => now(),
                    ]);
                    $fired++;
                }
            });

        return $fired;
    }

    private function fireRestockAlerts(): int
    {
        $fired = 0;

        RestockAlert::query()
            ->where('state', AlertState::Active->value)
            ->with('group')
            ->chunkById(200, function ($alerts) use (&$fired): void {
                foreach ($alerts as $alert) {
                    if ($alert->group?->in_stock !== true) {
                        continue;
                    }

                    $this->notify($alert, $alert->group->min_price, 'restock');
                    $alert->update([
                        'state' => AlertState::Triggered->value,
                        'notified_at' => now(),
                    ]);
                    $fired++;
                }
            });

        return $fired;
    }

    /**
     * The cheapest offer we are allowed to build an alert on.
     *
     * Not simply product_groups.min_price: that aggregate includes every
     * source, and a source that disallows price tracking must not be able to
     * trigger a notification.
     */
    private function trackablePrice(int $groupId): ?int
    {
        $trackable = array_values(array_filter(
            Source::values(),
            fn (string $s) => Source::from($s)->allowsPriceAlerts(),
        ));

        $price = DB::table('products')
            ->where('group_id', $groupId)
            ->where('status', 'active')
            ->where('availability', 'in_stock')
            ->whereIn('source', $trackable)
            ->min('price');

        return $price === null ? null : (int) $price;
    }

    private function notify(PriceAlert|RestockAlert $alert, ?int $price, string $kind): void
    {
        // In-app only for now. Email delivery is a separate concern and has to
        // filter its contents by source before it can be switched on.
        if ($alert->user_id === null) {
            return;
        }

        $group = $alert->group;

        Notification::create([
            'user_id' => $alert->user_id,
            'kind' => $kind,
            'title' => $group?->title ?? '',
            'body' => null,
            'url' => $group === null ? null : "/{$group->market->value}/p/{$group->id}/{$group->slug}",
            'payload' => [
                'group_id' => $alert->group_id,
                'price' => $price,
                'baseline' => $alert instanceof PriceAlert ? $alert->baseline_price : null,
            ],
        ]);
    }
}

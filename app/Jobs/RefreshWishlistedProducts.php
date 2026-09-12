<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AlertState;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Mail\AlertMail;
use App\Models\Notification;
use App\Models\PriceAlert;
use App\Models\Product;
use App\Models\RestockAlert;
use App\Models\WishlistItem;
use App\Services\Alerts\AlertEligibility;
use App\Services\Connectors\ConnectorRegistry;
use App\Services\Ingestion\OfferUpserter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Number;
use Throwable;

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

    /**
     * How many live offers one run may re-fetch.
     *
     * Every fetch is a request against a rate-limited API. Watched products
     * are a small set — somebody had to press a button for each — so this is
     * a ceiling against a runaway rather than a budget the job expects to
     * spend. The rest wait for the next run, twice a day.
     */
    private const REFRESH_CAP = 500;

    public function handle(?ConnectorRegistry $registry = null, ?OfferUpserter $upserter = null): void
    {
        // Injected by the queue; resolved here for the tests that run the job
        // by hand, as they did before it had dependencies.
        $registry ??= app(ConnectorRegistry::class);
        $upserter ??= app(OfferUpserter::class);

        $refreshed = $this->refreshLiveOffers($registry, $upserter);
        $rearmed = $this->rearmAlerts();
        $priceDrops = $this->firePriceAlerts();
        $restocks = $this->fireRestockAlerts();

        Log::info('Wishlist refresh complete', [
            'refreshed' => $refreshed,
            'rearmed' => $rearmed,
            'price_drops' => $priceDrops,
            'restocks' => $restocks,
        ]);
    }

    /**
     * Ask the live sources for today's price on every watched product.
     *
     * Until 2026-09-06 nothing did. `LiveConnector::fetchById()` was declared
     * "for wishlist and daily-pick re-checks", implemented by every live
     * connector, and called by nothing — so a product whose only offers came
     * from bol had a price that changed only when somebody happened to search
     * for it, and a watched item could sit at an unchanging number forever.
     *
     * Feed-sourced offers are refreshed by ingestion and are left alone here.
     * A source that is cooling down is skipped this run rather than waited
     * for; a fetch that answers nothing leaves the row as it was, because
     * "the API did not answer" and "the product is gone" are different facts
     * and only ingestion's stale sweep is entitled to the second.
     */
    private function refreshLiveOffers(ConnectorRegistry $registry, OfferUpserter $upserter): int
    {
        $groupIds = PriceAlert::query()
            ->whereIn('state', [AlertState::Active->value, AlertState::Triggered->value])
            ->pluck('group_id')
            ->merge(RestockAlert::query()
                ->whereIn('state', [AlertState::Active->value, AlertState::Triggered->value])
                ->pluck('group_id'))
            /*
             * And everything on a list whose owner watches its prices: the
             * digest that runs after this job compares against today's price,
             * and for a bol-only product today's price is whatever this fetch
             * says. See App\Services\Alerts\ListPriceWatch.
             */
            ->merge(WishlistItem::query()
                ->whereNotNull('group_id')
                ->whereNotNull('accepted_at')
                ->whereHas('wishlist', fn ($q) => $q->whereNotNull('price_watch_percent'))
                ->pluck('group_id'))
            ->unique()
            ->values();

        if ($groupIds->isEmpty()) {
            return 0;
        }

        $liveSources = array_map(fn (Source $s) => $s->value, $registry->liveSources());

        if ($liveSources === []) {
            return 0;
        }

        $fetched = [];
        $written = 0;

        Product::query()
            ->whereIn('group_id', $groupIds)
            ->whereIn('source', $liveSources)
            ->where('status', ProductStatus::Active->value)
            ->select(['id', 'source', 'external_id', 'market', 'group_id'])
            ->orderBy('id')
            ->chunkById(100, function ($products) use ($registry, $upserter, &$fetched, &$written): bool {
                foreach ($products as $product) {
                    if (count($fetched) >= self::REFRESH_CAP) {
                        return false;
                    }

                    $connector = $registry->live($product->source);

                    if ($connector === null || $connector->isCoolingDown() || ! $connector->supports($product->market)) {
                        continue;
                    }

                    try {
                        $offer = $connector->fetchById($product->external_id, $product->market);
                    } catch (Throwable $e) {
                        report($e);

                        continue;
                    }

                    if ($offer !== null) {
                        $fetched[] = $offer;
                    }
                }

                if ($fetched !== []) {
                    $written += (int) ($upserter->upsert($fetched)['written'] ?? 0);
                    $fetched = [];
                }

                return true;
            });

        if ($fetched !== []) {
            $written += (int) ($upserter->upsert($fetched)['written'] ?? 0);
        }

        return $written;
    }

    /**
     * Put a fired alert back on watch once the thing it fired for has passed.
     *
     * A fired alert used to stay `triggered` forever: one notification, ever,
     * unless the person pressed "watch" again by hand. A price that dropped,
     * recovered and dropped again is exactly what somebody watching a price
     * wants to hear about twice, so a price alert re-arms when the price is
     * back at or above what it was watching — with today's price as the new
     * baseline, so the next drop is measured from here — and a restock alert
     * re-arms when the product is out of stock again.
     */
    private function rearmAlerts(): int
    {
        $rearmed = 0;

        PriceAlert::query()
            ->where('state', AlertState::Triggered->value)
            ->chunkById(200, function ($alerts) use (&$rearmed): void {
                foreach ($alerts as $alert) {
                    $current = $this->trackablePrice($alert->group_id);

                    if ($current === null) {
                        continue;
                    }

                    if ($current < ($alert->target_price ?? $alert->baseline_price)) {
                        continue;
                    }

                    $alert->update([
                        'state' => AlertState::Active->value,
                        'baseline_price' => $current,
                        'notified_at' => null,
                    ]);
                    $rearmed++;
                }
            });

        RestockAlert::query()
            ->where('state', AlertState::Triggered->value)
            ->with('group')
            ->chunkById(200, function ($alerts) use (&$rearmed): void {
                foreach ($alerts as $alert) {
                    if ($alert->group?->in_stock !== false) {
                        continue;
                    }

                    $alert->update(['state' => AlertState::Active->value, 'notified_at' => null]);
                    $rearmed++;
                }
            });

        return $rearmed;
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
     * Lives on AlertEligibility since the per-list watch needed the same
     * number; kept as a one-liner here so the call sites above read as they
     * always did.
     */
    private function trackablePrice(int $groupId): ?int
    {
        return app(AlertEligibility::class)->trackablePrice($groupId);
    }

    private function notify(PriceAlert|RestockAlert $alert, ?int $price, string $kind): void
    {
        if ($alert->user_id === null) {
            return;
        }

        $group = $alert->group;
        $url = $group === null ? null : "/{$group->market->value}/p/{$group->id}/{$group->slug}";

        Notification::create([
            'user_id' => $alert->user_id,
            'kind' => $kind,
            'title' => $group?->title ?? '',
            'body' => null,
            'url' => $url,
            'payload' => [
                'group_id' => $alert->group_id,
                'price' => $price,
                'baseline' => $alert instanceof PriceAlert ? $alert->baseline_price : null,
            ],
        ]);

        /*
         * And the inbox. The price here came from `trackablePrice()`, which
         * reads trackable sources only, so a source whose programme forbids
         * product data in email cannot reach the template — that is the
         * filtering-by-source the old in-app-only note was waiting for, done
         * once at the point the number is chosen. See App\Mail\AlertMail.
         */
        $user = $alert->user;

        if ($user === null || $group === null || $url === null || blank($user->email)) {
            return;
        }

        $language = $group->market->language();

        Mail::to($user)->send(new AlertMail(
            kind: $kind,
            title: $group->title,
            url: url($url),
            language: $language,
            price: $price === null ? null : Number::currency($price / 100, 'EUR', $language),
            was: $alert instanceof PriceAlert ? Number::currency($alert->baseline_price / 100, 'EUR', $language) : null,
        ));
    }
}

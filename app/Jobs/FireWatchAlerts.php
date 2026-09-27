<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AlertState;
use App\Jobs\Concerns\RunsOneAtATime;
use App\Mail\AlertMail;
use App\Models\Notification;
use App\Models\PriceAlert;
use App\Models\RestockAlert;
use App\Services\Alerts\AlertEligibility;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Number;

/**
 * Fire the price and restock alerts, and re-arm the ones that have passed.
 *
 * Split out of RefreshWishlistedProducts on 2026-09-28. That job refreshes the
 * watched products' live prices, a batch job with a time budget; this one
 * compares and mails, on the `mail` queue with the other mailings. Apart, a
 * refresh that runs out of time still leads to alerts on what it did fetch,
 * and a slow SMTP server no longer holds a catalogue worker.
 *
 * Safe to run twice: an alert that fired is `triggered` and is not fired
 * again until it re-arms.
 */
#[Queue('mail')]
class FireWatchAlerts implements ShouldQueue
{
    use Queueable, RunsOneAtATime;

    public int $timeout = 900;

    protected function overlapKey(): string
    {
        return 'all';
    }

    public function handle(): void
    {
        $rearmed = $this->rearmAlerts();
        $priceDrops = $this->firePriceAlerts();
        $restocks = $this->fireRestockAlerts();

        Log::info('Watch alerts fired', [
            'rearmed' => $rearmed,
            'price_drops' => $priceDrops,
            'restocks' => $restocks,
        ]);
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

                    // Marked before the mail goes, so a run cut off after the
                    // send cannot send it again on the retry.
                    $alert->update([
                        'state' => AlertState::Triggered->value,
                        'notified_at' => now(),
                    ]);
                    $this->notify($alert, $current, 'price_drop');
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

                    $alert->update([
                        'state' => AlertState::Triggered->value,
                        'notified_at' => now(),
                    ]);
                    $this->notify($alert, $alert->group->min_price, 'restock');
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
            title: $group->displayTitle(),
            url: url($url),
            language: $language,
            price: $price === null ? null : Number::currency($price / 100, 'EUR', $language),
            was: $alert instanceof PriceAlert ? Number::currency($alert->baseline_price / 100, 'EUR', $language) : null,
        ));
    }
}

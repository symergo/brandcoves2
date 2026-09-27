<?php

declare(strict_types=1);

namespace App\Listeners\Octane;

use App\Services\Settings\AffiliateSettingsStore;
use App\Services\Settings\AiSettingsStore;
use App\Services\Settings\ReminderSettingsStore;

/**
 * Lay the admin-edited settings over the config again, for each request a
 * worker handles.
 *
 * `AppServiceProvider::boot()` applies the three settings screens (AI,
 * reminders, shop and affiliate keys) on top of the config. In classic mode
 * boot runs for every request, so a setting saved in the admin applies to the
 * next request. In worker mode (Octane) boot runs once per worker, and each
 * request starts from a copy of the config as it was at that boot: a key
 * rotated in the admin would reach no page until the workers restarted. That
 * is the one piece of this app's state that went stale under workers
 * (docs/features/speed.md, "Worker mode").
 *
 * Registered in config/octane.php after Octane's own preparation, which is what
 * gives this request its own copy of the config to write into. The cost is
 * what classic mode already paid per request: each store reads one cache key.
 */
class ReapplySettingsOverlays
{
    public function handle(object $event): void
    {
        $app = $event->sandbox;

        $app->make(AiSettingsStore::class)->apply();
        $app->make(ReminderSettingsStore::class)->apply();
        $app->make(AffiliateSettingsStore::class)->apply();
    }
}

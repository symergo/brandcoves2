<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/**
 * A configured bol connector (both partner sites) with an empty cache and a
 * full search and product rate-limit bucket. Call it from setUp().
 */
trait ConfiguresBol
{
    protected function configureBol(): void
    {
        config([
            'giftcoves.connectors.bol.enabled' => true,
            'giftcoves.connectors.bol.client_id' => 'test-id',
            'giftcoves.connectors.bol.client_secret' => 'test-secret',
            'giftcoves.connectors.bol.partner_site_id' => ['BE' => '25421', 'NL' => '1005548'],
        ]);

        Cache::flush();

        // The cache store is `array` under test, so Cache::flush() does not
        // touch the rate limiter: it talks to Redis directly, on purpose,
        // because its whole job is sharing state across processes. Without
        // this reset the bucket drains part-way through the class and later
        // tests make no requests at all, which looks like a broken connector
        // rather than a drained token.
        foreach (['search', 'product'] as $bucket) {
            Redis::del("bc:ratelimit:bol:{$bucket}", "bc:ratelimit:bol:{$bucket}:cooldown");
        }
    }
}

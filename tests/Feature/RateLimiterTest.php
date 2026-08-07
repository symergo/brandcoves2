<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Connectors\RateLimiter;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Runs against real Redis. The whole point of this class is atomicity across
 * processes, which a fake cannot demonstrate.
 */
class RateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (Redis::keys('bc:ratelimit:test*') as $key) {
            // Laravel's keys() returns unprefixed names on some clients.
            Redis::del(str_replace(config('database.redis.options.prefix', ''), '', $key));
        }
    }

    #[Test]
    public function a_documented_limit_can_never_be_exceeded_in_a_rolling_second(): void
    {
        // THE bug this class exists to prevent. A token bucket emits
        // capacity + rate inside one second: the full bucket, plus everything
        // that refills while it drains. rate=10/capacity=10 for a "10 rps"
        // limit therefore allows 20 in the first second and earns a 429.
        $limiter = RateLimiter::forDocumentedLimit('test-burst', 10);

        $granted = 0;
        $start = microtime(true);

        // Hammer it as hard as PHP allows for one second.
        while (microtime(true) - $start < 1.0) {
            if ($limiter->attempt()) {
                $granted++;
            }
        }

        $this->assertLessThanOrEqual(
            10,
            $granted,
            "granted {$granted} in the first second; the documented limit is 10",
        );
    }

    #[Test]
    public function it_refills_over_time(): void
    {
        $limiter = RateLimiter::forDocumentedLimit('test-refill', 10);

        // Drain the bucket.
        while ($limiter->attempt()) {
            // no-op
        }
        $this->assertFalse($limiter->attempt());

        // Rate is 8/s, so ~125ms buys one token. Allow slack for CI timing.
        usleep(300_000);

        $this->assertTrue($limiter->attempt(), 'the bucket must refill');
    }

    #[Test]
    public function it_reports_how_long_to_wait(): void
    {
        $limiter = RateLimiter::forDocumentedLimit('test-wait', 10);

        while ($limiter->attempt()) {
            // drain
        }

        $wait = $limiter->retryAfter();

        $this->assertGreaterThan(0.0, $wait);
        // One token at 8/s is 0.125s. It must never suggest waiting absurdly long.
        $this->assertLessThan(1.0, $wait);
    }

    #[Test]
    public function a_429_drains_the_bucket_and_starts_a_cooldown(): void
    {
        $limiter = RateLimiter::forDocumentedLimit('test-penalty', 10);

        $this->assertTrue($limiter->attempt());
        $this->assertFalse($limiter->isCoolingDown());

        // The upstream has told us our accounting is wrong; back off wholesale
        // rather than retrying into the wall.
        $limiter->penalise(seconds: 2);

        $this->assertTrue($limiter->isCoolingDown());
        $this->assertFalse($limiter->attempt(), 'nothing is granted while cooling down');
    }

    #[Test]
    public function blocking_gives_up_rather_than_hanging(): void
    {
        $limiter = RateLimiter::forDocumentedLimit('test-block', 10);
        $limiter->penalise(seconds: 30);

        $start = microtime(true);
        $acquired = $limiter->block(maxSeconds: 0.5);
        $elapsed = microtime(true) - $start;

        $this->assertFalse($acquired);
        // A job must not wedge on a limiter that is refusing everything.
        $this->assertLessThan(2.0, $elapsed);
    }

    #[Test]
    public function separate_buckets_do_not_share_budget(): void
    {
        // bol's limits are per endpoint, and they do not share a budget — so
        // draining search must not stop us fetching a product by id.
        $search = RateLimiter::forDocumentedLimit('test-a', 10);
        $byId = RateLimiter::forDocumentedLimit('test-b', 10);

        while ($search->attempt()) {
            // drain a only
        }

        $this->assertTrue($byId->attempt());
    }
}

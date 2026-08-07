<?php

declare(strict_types=1);

namespace App\Services\Connectors;

use Illuminate\Support\Facades\Redis;
use Predis\Client as PredisClient;

/**
 * Cross-process token bucket, in Redis.
 *
 * The state has to be shared: search requests hit bol from the web containers
 * while ingestion and daily-picks jobs hit it from the queue container, and the
 * upstream limit applies to all of them together. A per-process limiter would
 * be exceeded by exactly the concurrency that matters.
 *
 * Refill and spend happen inside one Lua script so the check and the decrement
 * are atomic. A read-then-write in PHP would race between workers and overshoot
 * under load.
 */
class RateLimiter
{
    /**
     * Refill and spend in a single atomic step.
     *
     * Redis' own TIME is used rather than a timestamp from PHP so every worker
     * shares one clock — container clocks drift, and a fast clock would let a
     * worker refill its own bucket early.
     */
    private const SCRIPT = <<<'LUA'
        local key      = KEYS[1]
        local rate     = tonumber(ARGV[1])
        local capacity = tonumber(ARGV[2])
        local cost     = tonumber(ARGV[3])

        local t   = redis.call('TIME')
        local now = tonumber(t[1]) + (tonumber(t[2]) / 1000000)

        local state  = redis.call('HMGET', key, 'tokens', 'ts')
        local tokens = tonumber(state[1])
        local ts     = tonumber(state[2])

        if tokens == nil or ts == nil then
            tokens = capacity
            ts = now
        end

        local elapsed = now - ts
        if elapsed < 0 then elapsed = 0 end
        tokens = math.min(capacity, tokens + (elapsed * rate))

        local allowed = 0
        if tokens >= cost then
            tokens = tokens - cost
            allowed = 1
        end

        redis.call('HSET', key, 'tokens', tokens, 'ts', now)
        redis.call('EXPIRE', key, math.ceil(capacity / rate) + 60)

        local wait = 0
        if allowed == 0 then
            wait = (cost - tokens) / rate
        end

        return { allowed, tostring(wait) }
    LUA;

    public function __construct(
        private readonly string $bucket,
        private readonly float $rate,
        private readonly int $capacity,
    ) {}

    /**
     * Build a bucket that provably never exceeds a documented per-second limit.
     *
     * The trap: a token bucket can emit `capacity + rate` requests inside a
     * single second — the full bucket, plus everything that refills while it
     * drains. Setting rate = 10 and capacity = 10 for a "10 requests per
     * second" limit therefore permits up to 20 in the first second, and the
     * upstream answers with a 429.
     *
     * Sizing so that `capacity + rate <= limit` makes "never more than `limit`
     * in any rolling second" true by construction. The cost is roughly 20%
     * lower sustained throughput, which is the right trade against being
     * blocked.
     */
    public static function forDocumentedLimit(string $bucket, int $perSecond): self
    {
        $rate = $perSecond * 0.8;
        $capacity = max(1, (int) floor($perSecond * 0.2));

        return new self($bucket, $rate, $capacity);
    }

    /** Take a token if one is available. Never blocks. */
    public function attempt(int $cost = 1): bool
    {
        return $this->run($cost)['allowed'];
    }

    /** Seconds until a token would be available. 0.0 when one is available now. */
    public function retryAfter(int $cost = 1): float
    {
        return $this->run($cost)['wait'];
    }

    /**
     * Wait for a token, up to a limit.
     *
     * Used by batch jobs, which can afford to wait; the request path uses
     * attempt() and degrades instead.
     */
    public function block(float $maxSeconds = 5.0, int $cost = 1): bool
    {
        $deadline = microtime(true) + $maxSeconds;

        while (microtime(true) < $deadline) {
            $result = $this->run($cost);
            if ($result['allowed']) {
                return true;
            }

            // Sleep for the shorter of "until a token exists" and the remaining
            // budget, with a floor so a tight loop cannot spin on Redis.
            $sleep = min($result['wait'], $deadline - microtime(true));
            usleep((int) (max($sleep, 0.01) * 1_000_000));
        }

        return false;
    }

    /**
     * Drain the bucket and refuse everything for a cooldown.
     *
     * Called on a 429: the upstream has told us our own accounting is wrong, so
     * backing off wholesale beats retrying into the wall.
     */
    public function penalise(int $seconds): void
    {
        Redis::setex($this->cooldownKey(), $seconds, '1');
        Redis::del($this->key());
    }

    public function isCoolingDown(): bool
    {
        return (bool) Redis::exists($this->cooldownKey());
    }

    /** @return array{allowed: bool, wait: float} */
    private function run(int $cost): array
    {
        if ($this->isCoolingDown()) {
            return ['allowed' => false, 'wait' => (float) Redis::ttl($this->cooldownKey())];
        }

        $result = $this->eval($this->key(), [$this->rate, $this->capacity, $cost]);

        return [
            'allowed' => (int) ($result[0] ?? 0) === 1,
            'wait' => (float) ($result[1] ?? 0),
        ];
    }

    /**
     * phpredis and predis take eval() arguments in different orders, and
     * production uses phpredis while Windows dev uses predis (phpredis has no
     * Windows build). Normalising here keeps that difference out of the caller.
     *
     * @param  list<mixed>  $args
     * @return array<int, mixed>
     */
    private function eval(string $key, array $args): array
    {
        $client = Redis::connection()->client();

        $result = $client instanceof PredisClient
            ? $client->eval(self::SCRIPT, 1, $key, ...$args)
            : $client->eval(self::SCRIPT, [$key, ...$args], 1);

        return is_array($result) ? $result : [0, '0'];
    }

    private function key(): string
    {
        return "bc:ratelimit:{$this->bucket}";
    }

    private function cooldownKey(): string
    {
        return "bc:ratelimit:{$this->bucket}:cooldown";
    }
}

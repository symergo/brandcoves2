<?php

declare(strict_types=1);

namespace App\Support;

use App\Jobs\RefreshCachedValue;
use Illuminate\Support\Facades\Cache;

/**
 * A cache for values too slow to build while somebody waits (owner,
 * 2026-10-25: "apply caching with background refresh for slow pages such as
 * the popular search terms").
 *
 * - Cached and fresh: returned.
 * - Cached and older than `$freshFor`: returned all the same, and
 *   RefreshCachedValue (unique per key) rebuilds it on the queue.
 * - Not cached at all: `$missing` is returned (an empty page rather than a
 *   wait) and the same job builds it. Never built in the request.
 *
 * The value is built by calling `$builder` (a [class, method] pair, resolved
 * from the container) with `$args`, so the job can carry it: a closure
 * cannot be queued. Kept for KEEP seconds, far past `$freshFor`, so an old
 * value can always be shown while the new one is built. The search result
 * lists have their own variant of this (SearchService::staleWhileRevalidate),
 * with generations; this one is for whole pages.
 *
 * Why not `Cache::flexible()`: it builds the first value in the request, and
 * refreshes after the response in the same PHP worker. On the popular-searches
 * page the first build ran past PHP's 30 s limit on production (2026-10-25),
 * so it was never stored and every visitor started it again.
 */
final class BackgroundCache
{
    /** How long a value is kept to be shown while its replacement is built. */
    public const KEEP = 30 * 86400;

    /**
     * @param  array{class-string, string}  $builder
     * @param  list<mixed>  $args  serializable: they travel with the queued job
     */
    public static function remember(string $key, int $freshFor, array $builder, array $args = [], mixed $missing = null): mixed
    {
        $hit = Cache::get($key);

        if (is_array($hit) && array_key_exists('value', $hit)) {
            if ((int) ($hit['at'] ?? 0) < time() - $freshFor) {
                RefreshCachedValue::dispatch($key, $builder, $args);
            }

            return $hit['value'];
        }

        RefreshCachedValue::dispatch($key, $builder, $args);

        // Read once more: on a synchronous queue (tests, a local `sync`
        // setup) the job has already stored the value. On the real queue it
        // has not, and the visitor gets `$missing`.
        $built = Cache::get($key);

        return is_array($built) && array_key_exists('value', $built) ? $built['value'] : $missing;
    }

    /** Build now and store as current (the job's work, and a warm-up). */
    public static function rebuild(string $key, array $builder, array $args = []): mixed
    {
        $value = app($builder[0])->{$builder[1]}(...$args);
        Cache::put($key, ['value' => $value, 'at' => time()], self::KEEP);

        return $value;
    }
}

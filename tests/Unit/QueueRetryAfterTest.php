<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `retry_after` must outlast the slowest job on the queue.
 *
 * Redis holds a reserved job for `retry_after` seconds and then assumes the
 * worker is dead. Set below a job's own `$timeout` it aborts nothing — it
 * releases a job that is still running, a second worker takes the same payload,
 * and the attempt counter climbs underneath the one still working. Two releases
 * spends `$tries = 2`, and the job dies with MaxAttemptsExceeded having never
 * been allowed to finish once.
 *
 * That ran on production every morning at the stock 90: the be-nl Daily Cove
 * build was released at 06:01:32 and again at 06:03:04 and failed there, so
 * `/be-nl/daily` 404'd while the markets small enough to finish inside ninety
 * seconds published as normal. Nothing looked broken — a job that is retried to
 * death leaves the same silence as one that was never scheduled.
 *
 * A unit test rather than a note in a config comment, because the failure
 * arrives via a *different* file: somebody raises a `$timeout` in `app/Jobs/`
 * to give a growing catalogue room, and breaks a queue setting they never
 * opened.
 */
class QueueRetryAfterTest extends TestCase
{
    #[Test]
    public function it_holds_a_job_longer_than_the_longest_job_may_run(): void
    {
        $retryAfter = (int) config('queue.connections.redis.retry_after');

        foreach ($this->jobTimeouts() as $job => $timeout) {
            $this->assertGreaterThan(
                $timeout,
                $retryAfter,
                "{$job} may run for {$timeout}s but Redis releases a reserved job after "
                ."{$retryAfter}s. Raise queue.connections.redis.retry_after above it, or "
                .'that job will be retried to death instead of finishing.'
            );
        }
    }

    /**
     * Every `public int $timeout = N;` declared by a queued job.
     *
     * Read out of the source rather than by instantiating each job: several
     * take constructor arguments, and none of them need to exist for the number
     * to be readable.
     *
     * @return array<string, int>
     */
    private function jobTimeouts(): array
    {
        $timeouts = [];

        foreach (glob(base_path('app/Jobs/*.php')) ?: [] as $path) {
            $source = (string) file_get_contents($path);

            if (preg_match('/public\s+int\s+\$timeout\s*=\s*(\d+)\s*;/', $source, $match) === 1) {
                $timeouts[basename($path, '.php')] = (int) $match[1];
            }
        }

        // A glob that silently matches nothing would make this test pass
        // forever, which is the one outcome it must not have.
        $this->assertNotEmpty($timeouts, 'No queued job declared a $timeout — did app/Jobs move?');

        return $timeouts;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Jobs\BuildCove;
use App\Jobs\GroupProducts;
use App\Jobs\IngestFeed;
use App\Jobs\ReadItemLink;
use App\Jobs\SendCoveDigest;
use Illuminate\Queue\Attributes\Queue as QueueAttribute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * Every job names its queue, and every queue has a worker that can wait for it.
 *
 * Since 2026-09-28 Horizon runs four supervisors (config/horizon.php), and the
 * `default` queue is read through a connection whose `retry_after` is three
 * minutes. A long job that forgot its #[Queue] attribute would land there and
 * be handed to a second worker while the first was still running it, which is
 * exactly the failure that broke the Daily every morning until 2026-09-01.
 */
class QueueRoutingTest extends TestCase
{
    private const QUEUES = ['default', 'mail', 'editorial', 'batch'];

    #[Test]
    public function every_job_names_one_of_the_four_queues(): void
    {
        foreach ($this->jobClasses() as $class) {
            $queue = $this->queueOf($class);

            $this->assertNotNull($queue, "{$class} has no #[Queue] attribute, so it would land on `default`.");
            $this->assertContains($queue, self::QUEUES, "{$class} names a queue no Horizon supervisor reads.");
        }
    }

    #[Test]
    public function no_long_job_waits_on_the_short_connection(): void
    {
        $short = (int) config('queue.connections.redis.retry_after');

        foreach ($this->jobClasses() as $class) {
            if ($this->queueOf($class) !== 'default') {
                continue;
            }

            $timeout = (new ReflectionClass($class))->getDefaultProperties()['timeout'] ?? null;
            $timeout ??= (int) config('horizon.defaults.visitors.timeout');

            $this->assertLessThan($short, $timeout, "{$class} runs on `default` for longer than its retry_after ({$short}s).");
        }
    }

    #[Test]
    public function every_supervisor_stops_before_its_connection_retries(): void
    {
        $readers = [];

        foreach (config('horizon.defaults') as $name => $supervisor) {
            $retryAfter = (int) config("queue.connections.{$supervisor['connection']}.retry_after");

            $this->assertLessThan($retryAfter, (int) $supervisor['timeout'], "Supervisor {$name} outlives its connection's retry_after.");

            foreach ($supervisor['queue'] as $queue) {
                $readers[$queue] = $supervisor['connection'];
            }
        }

        $this->assertEqualsCanonicalizing(self::QUEUES, array_keys($readers));
        $this->assertSame('redis', $readers['default']);

        // The longest job on the slow queues fits the long connection.
        $this->assertGreaterThan((new IngestFeed(1))->timeout, (int) config('queue.connections.redis-long.retry_after'));

        // Both connections are one Redis: a job pushed onto `redis` is read by
        // a `redis-long` worker only because the keys are the same.
        $this->assertSame(config('queue.connections.redis.connection'), config('queue.connections.redis-long.connection'));
    }

    #[Test]
    public function a_dispatched_job_lands_on_its_queue(): void
    {
        Queue::fake();
        Cache::flush();

        try {
            GroupProducts::dispatch(Market::BeNl);
            IngestFeed::dispatch(42);
            BuildCove::dispatch(7);
            SendCoveDigest::dispatch(Market::BeNl);
            ReadItemLink::dispatch(9);

            Queue::assertPushedOn('batch', GroupProducts::class);
            Queue::assertPushedOn('batch', IngestFeed::class);
            Queue::assertPushedOn('editorial', BuildCove::class);
            Queue::assertPushedOn('mail', SendCoveDigest::class);
            Queue::assertPushedOn('default', ReadItemLink::class);
        } finally {
            Cache::flush();
        }
    }

    /** @return list<class-string> */
    private function jobClasses(): array
    {
        $classes = [];

        foreach (glob(app_path('Jobs/*.php')) ?: [] as $file) {
            $classes[] = 'App\\Jobs\\'.basename($file, '.php');
        }

        $this->assertNotEmpty($classes);

        return $classes;
    }

    private function queueOf(string $class): ?string
    {
        $attributes = (new ReflectionClass($class))->getAttributes(QueueAttribute::class);

        return $attributes === [] ? null : $attributes[0]->newInstance()->queue;
    }
}

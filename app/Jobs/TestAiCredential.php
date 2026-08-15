<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Ai\AiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Prove the AI credential by making one real call, from a queue worker.
 *
 * ## Why this is a job and not a method
 *
 * The settings page originally called `AiClient` directly and always failed with
 * *"AI may only be called from a queued job"* — the invariant doing exactly what
 * it exists for. A test button that reaches the model from a request handler is
 * the precise thing the guard forbids, and the guard is right: the moment there
 * is one admin-only path around it, there is a path.
 *
 * So the test runs where production runs. That also makes it a better test: it
 * exercises the queue, the worker's environment, the credential, the model name
 * and the cap in one go, rather than proving only that a string is well-formed.
 *
 * ## The result goes in the cache
 *
 * A job cannot return anything to the request that dispatched it. The outcome is
 * written to a cache key the page reads, with a timestamp — so the screen can say
 * "worked, two minutes ago" rather than making someone dispatch a test to find
 * out what happened last time.
 *
 * A test that stays `pending` means no worker picked it up, which is itself the
 * most useful thing this can tell you: generation is scheduled from the queue, so
 * a dead worker means nothing is being generated regardless of the credential.
 */
class TestAiCredential implements ShouldQueue
{
    use Queueable;

    public const CACHE_KEY = 'bc:ai:credential-test';

    /**
     * Long enough that yesterday's result is still on screen this morning, short
     * enough that it cannot be mistaken for a live status.
     */
    private const TTL = 86400;

    /**
     * One attempt.
     *
     * A retry would spend a second call to learn the same thing, and a failed
     * call counts against the cap either way.
     */
    public int $tries = 1;

    public function handle(AiClient $ai): void
    {
        try {
            $response = $ai->json(
                featureKey: 'gift_angles',
                system: 'You reply with JSON and nothing else.',
                prompt: 'Reply with {"ok":true}.',
                schemaHint: ['ok' => 'boolean'],
                // Sixteen tokens. Enough to prove the round trip, small enough
                // that pressing the button repeatedly is not a cost.
                maxTokens: 16,
            );

            self::write('ok', 'The key works. Model replied: '.json_encode($response));
        } catch (Throwable $e) {
            /*
             * The message, with the key stripped. An exception from an HTTP
             * client is exactly the kind of thing that echoes back what it was
             * sent, and this string is rendered in an admin page.
             */
            $key = (string) config('giftcoves.ai.api_key');

            self::write('failed', class_basename($e).': '.($key === ''
                ? $e->getMessage()
                : str_replace($key, '[redacted]', $e->getMessage())));
        }
    }

    /**
     * Record that a test is in flight.
     *
     * Written by the dispatcher rather than the job, so the page can distinguish
     * "queued and waiting" from "never run" — which is the difference between a
     * dead worker and an untested key.
     */
    public static function markPending(): void
    {
        self::write('pending', 'Queued. Waiting for a worker to pick it up.');
    }

    /** @return array{status: string, message: string, at: string}|null */
    public static function lastResult(): ?array
    {
        $result = Cache::get(self::CACHE_KEY);

        return is_array($result) ? $result : null;
    }

    private static function write(string $status, string $message): void
    {
        Cache::put(self::CACHE_KEY, [
            'status' => $status,
            'message' => $message,
            'at' => now()->toIso8601String(),
        ], self::TTL);
    }
}

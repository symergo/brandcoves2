<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Deployment health, and the answer to "what is actually running right now".
 *
 * Coolify's healthcheck hits this, and so do you after a deploy: it reports the
 * commit that built the image and the last migration applied, which is what
 * turns "it looks fine" into a fact.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('SELECT 1')),
            'redis' => $this->check(fn () => Redis::ping()),
        ];

        $healthy = ! in_array(false, array_map(fn (array $c) => $c['ok'], $checks), true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'commit' => config('brandcoves.commit_sha'),
            'migration' => $this->lastMigration(),
            'environment' => app()->environment(),
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    /** @param callable():mixed $probe */
    private function check(callable $probe): array
    {
        $start = hrtime(true);

        try {
            $probe();

            return ['ok' => true, 'ms' => $this->elapsedMs($start)];
        } catch (Throwable $e) {
            // The message can carry a connection string with credentials, so it
            // is logged rather than returned — this endpoint is unauthenticated.
            report($e);

            return ['ok' => false, 'ms' => $this->elapsedMs($start)];
        }
    }

    private function elapsedMs(int $start): float
    {
        return round((hrtime(true) - $start) / 1_000_000, 2);
    }

    private function lastMigration(): ?string
    {
        try {
            return DB::table('migrations')->orderByDesc('id')->value('migration');
        } catch (Throwable) {
            return null;
        }
    }
}

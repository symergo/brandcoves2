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
            // When the image was built, and from which branch. Coolify exposes
            // no commit SHA to the container, so this is what answers "is my
            // build actually serving, or is the previous one still up?".
            'built' => $this->buildStamp(),
            'branch' => env('COOLIFY_BRANCH', 'local'),
            'migration' => $this->lastMigration(),
            'environment' => app()->environment(),
            'config' => $this->config(),
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    /**
     * Whether the settings that must travel actually arrived.
     *
     * **Booleans only, and never a value or a length.** This endpoint is
     * unauthenticated, so it may say that a key is present and nothing more —
     * `bc:check-config` gives the fuller picture to whoever already has a shell.
     *
     * The point is that "did the config carry over?" becomes a `curl` rather
     * than an SSH session, so it can be answered in the same breath as `built`
     * and `migration` after every deploy. It is deliberately not part of the
     * `status` calculation: a missing Amazon key must not take the site down,
     * and Coolify restarts a container that reports unhealthy.
     *
     * `awinAccounts` is a count rather than a flag because the failure it
     * exists to catch was *fewer accounts than expected*, not zero — the
     * catalogue still built, from one publisher instead of two, and nothing
     * anywhere said so.
     *
     * @return array<string, bool|int>
     */
    private function config(): array
    {
        return [
            'appKey' => filled(config('app.key')),
            'credentialsKey' => filled(config('brandcoves.credentials_key')),
            'claimHashSecret' => filled(config('brandcoves.wishlist.claim_hash_secret')),
            'mail' => filled(config('services.resend.key')),
            'awin' => filled(config('brandcoves.connectors.awin.api_token')),
            'awinAccounts' => count((array) config('brandcoves.connectors.awin.accounts', [])),
            // `connectors.sources` does not exist — the key is `connectors.bol`.
            // The wrong path resolved to null, so this reported "bol: false" on
            // every environment including ones where bol works, which is worse
            // than not reporting it: a config check that always says "missing"
            // sends somebody chasing a credential that was never absent.
            'bol' => filled(config('brandcoves.connectors.bol.client_id')),
            'ai' => filled(config('brandcoves.ai.api_key')),
            'robotsAllow' => (bool) config('brandcoves.robots_allow'),
        ];
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

    /** Written into the image at build time; absent when running locally. */
    private function buildStamp(): string
    {
        $path = base_path('BUILD_STAMP');

        return is_readable($path) ? trim((string) file_get_contents($path)) : 'dev';
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

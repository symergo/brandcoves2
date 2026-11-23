<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A web request's database statements have a ceiling; the console's do not.
 *
 * On 2026-11-23 three search counts ran over five minutes each, after their
 * visitors already had a page: PHP's time limit does not count time spent
 * waiting on Postgres. See giftcoves.search.web_statement_timeout_ms.
 *
 * The suite runs in the console, so the web case is made by telling the
 * application it is not, then opening a fresh connection for the provider's
 * on-connect settings to reach.
 */
class WebStatementTimeoutTest extends TestCase
{
    private function settingsOnAFreshConnection(bool $console): array
    {
        $property = new \ReflectionProperty($this->app, 'isRunningInConsole');
        $property->setValue($this->app, $console);

        // Registers the on-connect listener again with the console flag as set above
        $boot = new \ReflectionMethod(AppServiceProvider::class, 'trigramThreshold');
        $boot->invoke(new AppServiceProvider($this->app));

        $name = 'pgsql_timeout_probe';
        config(["database.connections.{$name}" => config('database.connections.'.config('database.default'))]);
        $connection = DB::connection($name);

        try {
            return [
                $connection->selectOne('SHOW statement_timeout')->statement_timeout,
                $connection->selectOne('SHOW pg_trgm.word_similarity_threshold')->{'pg_trgm.word_similarity_threshold'},
            ];
        } finally {
            DB::purge($name);
            $property->setValue($this->app, true);
        }
    }

    #[Test]
    public function a_web_request_gets_the_ceiling_and_keeps_the_trigram_threshold(): void
    {
        config(['giftcoves.search.web_statement_timeout_ms' => 20000]);

        [$timeout, $threshold] = $this->settingsOnAFreshConnection(console: false);

        $this->assertSame('20s', $timeout);
        $this->assertEqualsWithDelta(0.45, (float) $threshold, 0.0001);
    }

    #[Test]
    public function the_console_is_not_limited(): void
    {
        config(['giftcoves.search.web_statement_timeout_ms' => 20000]);

        [$timeout] = $this->settingsOnAFreshConnection(console: true);

        $this->assertSame('0', $timeout);
    }
}

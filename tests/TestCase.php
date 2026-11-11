<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * A Redis key prefix per parallel process.
     *
     * Most of the suite never touches Redis (the cache store is `array`), but
     * the connectors' rate limiters do, on purpose: sharing state between
     * processes is their job. Under `--parallel` that meant eight processes
     * sharing one bol bucket and one cooldown, so a test that provoked a 429
     * in one process made a bol test in another make no request at all
     * (BolPageImportTest, flaky until 2026-11-11). ParaTest gives each process
     * a TEST_TOKEN; a serial run has none and keeps the configured prefix.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $token = $_SERVER['TEST_TOKEN'] ?? getenv('TEST_TOKEN');

        if (is_string($token) && $token !== '') {
            config(['database.redis.options.prefix' => config('database.redis.options.prefix')."p{$token}:"]);
        }
    }
}

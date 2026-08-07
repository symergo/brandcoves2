<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reports_what_is_actually_running(): void
    {
        $response = $this->getJson('/health');

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database.ok', true)
            // The commit and the applied migration are what turn "it looks
            // fine after deploy" into a verifiable fact.
            ->assertJsonStructure(['status', 'commit', 'migration', 'environment', 'checks']);
    }

    #[Test]
    public function it_does_not_leak_connection_details(): void
    {
        // This endpoint is unauthenticated. An exception message can carry a
        // connection string with credentials, so failures are logged, not returned.
        $body = $this->getJson('/health')->getContent();

        $this->assertStringNotContainsString(config('database.connections.pgsql.password'), (string) $body);
    }
}

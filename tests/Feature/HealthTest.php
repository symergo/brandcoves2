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
            // The build stamp and the applied migration are what turn "it looks
            // fine after deploy" into a verifiable fact. Coolify exposes no
            // commit SHA to the container, so the build time stands in for it.
            ->assertJsonStructure(['status', 'built', 'branch', 'migration', 'environment', 'config', 'checks']);
    }

    #[Test]
    public function it_reports_whether_the_config_arrived(): void
    {
        // "Did the config carry over?" has to be answerable by curl after a
        // deploy, alongside built and migration. The count rather than a flag on
        // awinAccounts is deliberate: the failure this caught was two accounts
        // locally and one in production, which no boolean would have shown.
        $this->getJson('/health')
            ->assertOk()
            ->assertJsonPath('config.appKey', true)
            ->assertJsonStructure(['config' => ['appKey', 'credentialsKey', 'claimHashSecret', 'awinAccounts', 'robotsAllow']]);
    }

    #[Test]
    public function the_config_section_reports_presence_and_never_content(): void
    {
        /*
         * This endpoint is unauthenticated, so the config block may say a key is
         * present and nothing else. Anything richer — a length, a prefix, the
         * first four characters — narrows a brute force and belongs behind the
         * shell that `bc:check-config` already requires.
         */
        $config = $this->getJson('/health')->json('config');

        foreach ($config as $key => $value) {
            $this->assertTrue(
                is_bool($value) || is_int($value),
                "config.{$key} must be a boolean or a count, never anything derived from the value itself.",
            );
        }

        $this->assertStringNotContainsString(
            (string) config('app.key'),
            (string) $this->getJson('/health')->getContent(),
        );
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

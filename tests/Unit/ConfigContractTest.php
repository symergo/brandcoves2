<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The config contract: what the app reads must be reachable where it runs.
 *
 * A setting has to survive four separate places to reach production — an
 * `env()` call in `config/`, a line in `.env.example`, a passthrough in
 * `docker-compose.coolify.yml`, and a value in Coolify. Only the first is in
 * code, so the other three drift, and **every way this fails is silent**:
 * `env('X')` with no default returns null, and null usually means "feature
 * off" rather than "misconfigured".
 *
 * It has already cost twice. A login 500'd on a missing variable. And the
 * second Awin publisher account was declared in config but never passed through
 * compose, so `array_filter(… filled($token))` dropped it without a word — a
 * laptop ingested from two publishers while production quietly used one.
 *
 * This test is deliberately a **static scan, not a runtime check**. It has to
 * fail on the laptop of whoever adds the key, before anything is deployed and
 * long before someone wonders why production has fewer merchants.
 */
class ConfigContractTest extends TestCase
{
    /** Config files the app owns. Laravel's stock files are not our contract. */
    private const APP_CONFIG = [
        'config/brandcoves.php',
        'config/services.php',
    ];

    private const COMPOSE = 'docker-compose.coolify.yml';

    private const ENV_EXAMPLE = '.env.example';

    /**
     * Stock `services.php` entries for services this app does not use.
     *
     * Listed one by one on purpose. Skipping a key should be a line somebody
     * has to write and a reviewer can see, not a pattern that quietly swallows
     * the next real credential that happens to end with `_KEY`.
     */
    private const NOT_OURS = [
        'AWS_ACCESS_KEY_ID',
        'AWS_SECRET_ACCESS_KEY',
        'POSTMARK_API_KEY',
        'SLACK_BOT_USER_DEFAULT_CHANNEL',
        'SLACK_BOT_USER_OAUTH_TOKEN',
    ];

    #[Test]
    public function every_setting_without_a_default_reaches_a_container(): void
    {
        $missing = array_values(array_diff(
            $this->settingsWithoutDefault(),
            $this->keysComposeProvides(),
            self::NOT_OURS,
        ));

        $this->assertSame([], $missing, implode("\n", [
            'These are read with no default, so they are null when unset — and null reads as',
            '"feature off" rather than "someone forgot". They are not passed through',
            self::COMPOSE.', so no container can ever see them.',
            '',
            'Add each to the app environment block, or to NOT_OURS if it genuinely is not ours:',
            '  '.implode(', ', $missing),
        ]));
    }

    #[Test]
    public function every_setting_without_a_default_is_documented(): void
    {
        $missing = array_values(array_diff(
            $this->settingsWithoutDefault(),
            $this->keysEnvExampleDocuments(),
            self::NOT_OURS,
        ));

        $this->assertSame([], $missing, implode("\n", [
            'Undocumented in '.self::ENV_EXAMPLE.', which is the only place anyone setting up an',
            'environment finds out a value is wanted at all:',
            '  '.implode(', ', $missing),
        ]));
    }

    #[Test]
    public function everything_compose_interpolates_is_documented(): void
    {
        // The other direction. A `${VAR}` compose expects but nothing documents
        // becomes an empty string in the container, and Docker warns to a build
        // log nobody reads.
        $missing = array_values(array_diff(
            $this->keysComposeInterpolates(),
            $this->keysEnvExampleDocuments(),
        ));

        $this->assertSame([], $missing, implode("\n", [
            self::COMPOSE.' interpolates these, but '.self::ENV_EXAMPLE.' never mentions them.',
            'Unset, they arrive as an empty string:',
            '  '.implode(', ', $missing),
        ]));
    }

    #[Test]
    public function the_second_awin_account_is_reachable(): void
    {
        /*
         * The regression this whole test exists for, pinned by name.
         *
         * The generic assertions above would catch it, but only while these
         * keys stay defaultless. Giving them a default would silence those and
         * restore the original bug — an account that exists locally and
         * silently does not in production.
         */
        foreach (['AWIN_VDB_API_TOKEN', 'AWIN_VDB_PUBLISHER_ID'] as $key) {
            $this->assertContains(
                $key,
                $this->keysComposeProvides(),
                "{$key} must be passed through ".self::COMPOSE.', or the second Awin publisher '
                .'account is dropped in every deployed environment and nothing says so.',
            );
        }
    }

    /**
     * Keys read as `env('KEY')` with no fallback, so unset means null.
     *
     * @return list<string>
     */
    private function settingsWithoutDefault(): array
    {
        $keys = [];

        foreach (self::APP_CONFIG as $file) {
            preg_match_all(
                '/env\(\s*[\'"]([A-Z][A-Z0-9_]*)[\'"]\s*\)/',
                $this->read($file),
                $matches,
            );

            $keys = [...$keys, ...$matches[1]];
        }

        return $this->unique($keys);
    }

    /**
     * Keys a container can actually see: named in the environment block, or
     * interpolated somewhere in the file.
     *
     * @return list<string>
     */
    private function keysComposeProvides(): array
    {
        $compose = $this->read(self::COMPOSE);

        // `    KEY: value` under an environment block.
        preg_match_all('/^\s{4,}([A-Z][A-Z0-9_]*):\s/m', $compose, $declared);

        return $this->unique([...$declared[1], ...$this->keysComposeInterpolates()]);
    }

    /** @return list<string> */
    private function keysComposeInterpolates(): array
    {
        preg_match_all('/\$\{([A-Z][A-Z0-9_]*)/', $this->read(self::COMPOSE), $matches);

        return $this->unique($matches[1]);
    }

    /** @return list<string> */
    private function keysEnvExampleDocuments(): array
    {
        $keys = [];

        foreach (explode("\n", $this->read(self::ENV_EXAMPLE)) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            $keys[] = trim(explode('=', $line, 2)[0]);
        }

        return $this->unique($keys);
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 2).'/'.$relative;

        $this->assertFileExists($path, "{$relative} is part of the config contract and is missing.");

        return (string) file_get_contents($path);
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function unique(array $keys): array
    {
        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }
}

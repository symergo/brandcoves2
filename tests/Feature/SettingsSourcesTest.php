<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ConnectorSetting;
use App\Services\Ops\DeployTrigger;
use App\Services\Settings\AffiliateSettingsStore;
use App\Services\Settings\AiSettingsStore;
use App\Services\Settings\AutomationSettingsStore;
use App\Services\Settings\ReminderSettingsStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every admin settings store can actually save.
 *
 * `connector_settings.source` has a CHECK listing the subsystems allowed to
 * keep settings there. The reminder settings page shipped writing under
 * `reminders`, which the check never listed, so saving it on production failed
 * with a check violation, and nothing noticed: its own tests never saved. Found
 * on 2026-09-14 while adding `affiliate`.
 *
 * So the test is about the pair, not either half: each store's SOURCE must be a
 * value the database accepts. A new store without its migration fails here.
 */
class SettingsSourcesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function sources(): array
    {
        return [
            'ai' => [AiSettingsStore::SOURCE],
            'reminders' => [ReminderSettingsStore::SOURCE],
            'automation' => [AutomationSettingsStore::SOURCE],
            'affiliate' => [AffiliateSettingsStore::SOURCE],
            'deploy trigger' => [DeployTrigger::SOURCE],
        ];
    }

    #[Test]
    #[DataProvider('sources')]
    public function a_settings_store_can_write_under_its_own_source(string $source): void
    {
        ConnectorSetting::create([
            'source' => $source,
            'key' => 'probe',
            'encrypted_value' => 'probe',
        ]);

        $this->assertSame(1, ConnectorSetting::query()->where('source', $source)->where('key', 'probe')->count());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\AiSettings;
use App\Models\ConnectorSetting;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiUnavailable;
use App\Services\Settings\AiSettingsStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AI settings, editable without a deploy.
 *
 * The properties that matter are all about the credential and the spend switch:
 * the key must never leave the server, a save that does not mention it must not
 * clear it, and turning generation on must not weaken the invariant that only a
 * queued job can spend money.
 */
class AiSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $email = 'ai@example.test'): User
    {
        $user = User::create(['email' => $email, 'password' => 'password-for-testing']);
        $user->forceFill(['is_admin' => true])->save();

        return $user;
    }

    private function store(): AiSettingsStore
    {
        $store = app(AiSettingsStore::class);
        $store->flush();

        return $store;
    }

    #[Test]
    public function a_stored_setting_wins_over_the_environment(): void
    {
        config(['brandcoves.ai.enabled' => false]);

        $this->store()->put(['enabled' => true]);
        $this->store()->apply();

        // The only order that makes the screen mean anything.
        $this->assertTrue((bool) config('brandcoves.ai.enabled'));
    }

    #[Test]
    public function clearing_a_setting_falls_back_to_the_environment(): void
    {
        // The undo, and the only one available on a machine where you cannot
        // edit the env.
        $this->store()->put(['model' => 'claude-opus-5']);
        $this->store()->apply();
        $this->assertSame('claude-opus-5', config('brandcoves.ai.model'));

        config(['brandcoves.ai.model' => 'claude-sonnet-5']);
        $this->store()->put(['model' => null]);

        $this->assertSame([], ConnectorSetting::query()->where('key', 'model')->get()->all());
        $this->store()->apply();
        $this->assertSame('claude-sonnet-5', config('brandcoves.ai.model'));
    }

    #[Test]
    public function only_allowlisted_keys_reach_the_config(): void
    {
        /*
         * Without the allowlist a row in this table could overwrite any config
         * value in the application — a privilege escalation dressed as a
         * settings screen.
         */
        ConnectorSetting::create([
            'source' => AiSettingsStore::SOURCE,
            'key' => 'app.key',
            'encrypted_value' => 'stolen',
        ]);

        $before = config('app.key');
        $this->store()->apply();

        $this->assertSame($before, config('app.key'));
    }

    #[Test]
    public function the_page_never_renders_the_stored_key(): void
    {
        $this->store()->put(['api_key' => 'sk-ant-secret-value-1234']);
        $this->store()->apply();

        $response = $this->actingAs($this->admin())->get('/admin/ai-settings')->assertOk();

        // Not in the HTML, not in a value attribute, not in the Livewire payload.
        $response->assertDontSee('sk-ant-secret-value-1234', escape: false);
        // The fingerprint is what answers "is the right key in there".
        $response->assertSee('1234', escape: false);
    }

    #[Test]
    public function saving_without_touching_the_key_leaves_it_alone(): void
    {
        /*
         * The field is empty on every load, so a save that only changes a cap
         * submits an empty key. Treating that as "clear it" would silently
         * disable generation the first time anyone edited a number.
         */
        $this->store()->put(['api_key' => 'sk-ant-original']);
        $this->store()->apply();

        Livewire::actingAs($this->admin())
            ->test(AiSettings::class)
            ->set('data.cap_guide_copy', 42)
            ->call('save')
            ->assertHasNoErrors();

        $this->store()->apply();

        $this->assertSame('sk-ant-original', config('brandcoves.ai.api_key'));
        $this->assertSame(42, (int) config('brandcoves.ai.caps.guide_copy'));
    }

    #[Test]
    public function a_new_key_replaces_the_old_one(): void
    {
        $this->store()->put(['api_key' => 'sk-ant-original']);

        Livewire::actingAs($this->admin())
            ->test(AiSettings::class)
            ->set('data.api_key', 'sk-ant-replacement')
            ->call('save')
            ->assertHasNoErrors();

        $this->store()->apply();

        $this->assertSame('sk-ant-replacement', config('brandcoves.ai.api_key'));
    }

    #[Test]
    public function the_key_is_encrypted_at_rest(): void
    {
        // Encrypted with APP_KEY, so a production dump on a laptop is noise —
        // which is why bc:scrub deletes this table rather than anonymising it.
        $this->store()->put(['api_key' => 'sk-ant-plaintext-check']);

        $raw = DB::table('connector_settings')
            ->where('key', 'api_key')
            ->value('encrypted_value');

        $this->assertStringNotContainsString('sk-ant-plaintext-check', (string) $raw);
    }

    #[Test]
    public function caps_saved_here_are_what_the_usage_guard_reads(): void
    {
        // The whole point of editable caps: the number on this screen has to be
        // the number that stops a runaway job.
        $this->store()->put(['cap_daily_picks' => 7]);
        $this->store()->apply();

        $this->assertSame(7, (int) config('brandcoves.ai.caps.daily_picks'));
    }

    #[Test]
    public function enabling_ai_does_not_let_a_request_spend_money(): void
    {
        /*
         * The invariant, restated as a test on this feature. Turning generation
         * on makes the nightly jobs able to call a model; it must not make a web
         * request able to. AiClient enforces that and nothing here touches it.
         */
        $this->store()->put(['enabled' => true, 'api_key' => 'sk-ant-test']);
        $this->store()->apply();

        $this->expectException(AiUnavailable::class);

        // Simulating the request path: outside a queued job, the client refuses.
        app(AiClient::class)->json(
            featureKey: 'gift_angles',
            system: 'x',
            prompt: 'y',
            schemaHint: [],
        );
    }

    #[Test]
    public function a_missing_table_does_not_break_the_boot(): void
    {
        /*
         * The provider runs on every request including `migrate` on a fresh
         * database, where this table does not exist yet. A provider that throws
         * there makes the deployment unrecoverable: the one command that would
         * fix it is the one that cannot run.
         */
        Schema::drop('connector_settings');

        $this->store()->flush();

        $this->assertSame([], $this->store()->stored());
        $this->store()->apply();
    }
}

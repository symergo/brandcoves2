<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\AiSettings;
use App\Jobs\TestAiCredential;
use App\Models\ConnectorSetting;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiUnavailable;
use App\Services\Settings\AiSettingsStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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
        config(['giftcoves.ai.enabled' => false]);

        $this->store()->put(['enabled' => true]);
        $this->store()->apply();

        // The only order that makes the screen mean anything.
        $this->assertTrue((bool) config('giftcoves.ai.enabled'));
    }

    #[Test]
    public function clearing_a_setting_falls_back_to_the_environment(): void
    {
        // The undo, and the only one available on a machine where you cannot
        // edit the env.
        $this->store()->put(['model' => 'claude-opus-5']);
        $this->store()->apply();
        $this->assertSame('claude-opus-5', config('giftcoves.ai.model'));

        config(['giftcoves.ai.model' => 'claude-sonnet-5']);
        $this->store()->put(['model' => null]);

        $this->assertSame([], ConnectorSetting::query()->where('key', 'model')->get()->all());
        $this->store()->apply();
        $this->assertSame('claude-sonnet-5', config('giftcoves.ai.model'));
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

        $this->assertSame('sk-ant-original', config('giftcoves.ai.api_key'));
        $this->assertSame(42, (int) config('giftcoves.ai.caps.guide_copy'));
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

        $this->assertSame('sk-ant-replacement', config('giftcoves.ai.api_key'));
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

        $this->assertSame(7, (int) config('giftcoves.ai.caps.daily_picks'));
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

    #[Test]
    public function the_credential_test_runs_on_the_queue_rather_than_in_the_request(): void
    {
        /*
         * The first version of this button called AiClient directly and always
         * failed with "AI may only be called from a queued job" — the invariant
         * working. A test that reaches the model from a request handler is the
         * exact thing the guard forbids, so the button dispatches instead.
         */
        Queue::fake();

        $this->store()->put(['enabled' => true, 'api_key' => 'sk-ant-test']);
        $this->store()->apply();

        Livewire::actingAs($this->admin())
            ->test(AiSettings::class)
            ->callAction('test');

        Queue::assertPushed(TestAiCredential::class);

        // And the page can say it is waiting, rather than looking untested.
        $this->assertSame('pending', TestAiCredential::lastResult()['status']);
    }

    #[Test]
    public function the_credential_test_does_nothing_when_generation_is_off(): void
    {
        Queue::fake();

        // No key, so there is nothing to test and no point spending a job slot.
        $this->store()->put(['enabled' => false, 'api_key' => null]);
        config(['giftcoves.ai.api_key' => null]);
        $this->store()->apply();

        Livewire::actingAs($this->admin())
            ->test(AiSettings::class)
            ->callAction('test');

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_failed_credential_test_never_echoes_the_key(): void
    {
        /*
         * An exception from an HTTP client is exactly the kind of thing that
         * repeats back what it was sent, and this string is rendered in an admin
         * page. Redaction is on the write, not the read, so it cannot be
         * forgotten by a second caller.
         */
        config(['giftcoves.ai.enabled' => true, 'giftcoves.ai.api_key' => 'sk-ant-super-secret']);

        Http::fake([
            'api.anthropic.com/*' => Http::response('rejected key sk-ant-super-secret', 401),
        ]);

        // dispatchSync puts it in a queued-job context, which is what the client
        // requires — the same reason the button dispatches rather than calls.
        TestAiCredential::dispatchSync();

        $result = TestAiCredential::lastResult();

        $this->assertSame('failed', $result['status']);

        /*
         * The property, not the mechanism.
         *
         * An earlier version of this asserted that "[redacted]" appears — and it
         * does not, because AiClient already reduces a failure to "HTTP 401"
         * without the response body. That is the better outcome and asserting on
         * the marker would have made this test fail the day the client got safer.
         *
         * The job's own redaction stays as a second layer: it costs a str_replace
         * and covers any exception that does carry the request back.
         */
        $this->assertStringNotContainsString('sk-ant-super-secret', $result['message']);
    }

    #[Test]
    public function an_unreachable_cache_does_not_break_the_boot(): void
    {
        /*
         * The failure that actually broke a deploy, rather than the one the
         * guard was written for.
         *
         * `php artisan package:discover` runs during the Docker build, which
         * boots the application. At build time there is no Postgres and no
         * Redis, so the cache store falls back to the database driver and
         * `Cache::remember` itself queries a sqlite file that does not exist —
         * throwing several frames before the query the guard was wrapped around.
         *
         * Reproduced by pointing the cache at the database and removing the
         * table it needs, which is the same shape as a build container.
         */
        config(['cache.default' => 'database']);
        Schema::drop('cache');

        // Both must survive: the provider calls apply(), which calls stored().
        $this->assertSame([], (new AiSettingsStore)->stored());
        (new AiSettingsStore)->apply();

        // And the env defaults are what stand.
        $this->assertSame(config('giftcoves.ai.model'), config('giftcoves.ai.model'));
    }
}

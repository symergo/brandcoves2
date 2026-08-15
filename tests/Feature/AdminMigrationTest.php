<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Filament\Pages\Migration;
use App\Models\Guide;
use App\Models\ProductGroup;
use App\Models\User;
use App\Services\Ops\ConfigReport;
use App\Services\Ops\DeployTrigger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The migration screen: moving work between environments without a shell.
 *
 * The tests that matter here are the ones about what the screen refuses to do.
 * Everything it *can* do is already covered by {@see ContentPromotionTest} —
 * this page is a face on `ContentEnvelope`, deliberately, so there is one set of
 * rules rather than two.
 */
class AdminMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    #[Test]
    public function a_non_admin_cannot_reach_it(): void
    {
        // The page can redeploy the application and read every configured
        // setting's shape. It lives behind the same gate as the rest of /admin,
        // and that has to be asserted rather than assumed.
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->get('/admin/migration')
            ->assertForbidden();

        // A guest is 403'd rather than redirected here. Filament sends an
        // unauthenticated visitor to the login only from the panel root; a deep
        // link is refused outright, which is the stricter of the two and the one
        // worth pinning on a page that can redeploy the site.
        $this->get('/admin/migration')->assertForbidden();
    }

    #[Test]
    public function it_exports_an_envelope_as_a_download(): void
    {
        $product = ProductGroup::factory()->create([
            'market' => Market::BeNl,
            'identity_key' => 'ean:1010101010101',
        ]);

        $guide = Guide::create([
            'market' => Market::BeNl,
            'slug' => 'beste-blenders',
            'title' => 'Beste blenders',
            'status' => 'published',
        ]);
        $guide->items()->create(['group_id' => $product->id, 'rank' => 1]);

        $component = Livewire::actingAs($this->admin())
            ->test(Migration::class)
            ->set('data.surfaces', ['guides'])
            ->call('export');

        // Asserted through the actual download payload rather than the return
        // value: the bytes the browser receives are the thing under test, and a
        // streamed response that never writes its body would still look fine
        // from the outside.
        $download = $component->effects['download'] ?? null;

        $this->assertNotNull($download, 'the export produced no download');

        $decoded = json_decode(base64_decode((string) $download['content']), true);

        $this->assertSame(1, $decoded['version']);
        $this->assertSame('beste-blenders', $decoded['surfaces']['guides'][0]['slug']);

        // The identity, never the local id — that is the entire point of the
        // envelope, and a download is the last place it could regress unnoticed.
        $this->assertSame(
            'ean:1010101010101',
            $decoded['surfaces']['guides'][0]['items'][0]['product']['identity_key'],
        );
    }

    #[Test]
    public function the_config_report_shows_presence_and_never_a_value(): void
    {
        /*
         * The screen renders straight into HTML, so a value that reached it
         * would also reach a screenshot, a browser cache and anyone standing
         * behind the person reading it. Lengths answer the only question
         * anybody actually has.
         */
        $secrets = [
            (string) config('app.key'),
            (string) config('giftcoves.wishlist.claim_hash_secret'),
            (string) config('database.connections.pgsql.password'),
        ];

        $component = Livewire::actingAs($this->admin())
            ->test(Migration::class)
            ->assertOk()
            // Presence is what it may say, and it must say it — a page that
            // showed nothing would also pass a "no secrets" assertion.
            ->assertSee('APP_KEY');

        foreach (array_filter($secrets) as $secret) {
            $component->assertDontSee($secret);
        }
    }

    #[Test]
    public function the_report_reads_the_config_paths_the_app_actually_uses(): void
    {
        /*
         * These pointed at `connectors.sources.bol` and `connectors.sources.amazon`,
         * and there is no `sources` level. The wrong paths resolved to null, so
         * bol reported MISSING on every environment including ones where it
         * demonstrably works, and Amazon read as "off" everywhere — which
         * quietly downgraded its credentials from required to optional.
         *
         * Both directions are asserted, because a wrong path passes any test
         * that only checks the unset case: null is indistinguishable from
         * "genuinely not configured" until you set it and it still says null.
         */
        $report = app(ConfigReport::class);

        config([
            'giftcoves.connectors.bol.client_id' => null,
            'giftcoves.connectors.bol.client_secret' => null,
            'giftcoves.connectors.amazon.access_key' => null,
        ]);

        $this->assertContains('BOL_CLIENT_ID', $report->failures());

        config([
            'giftcoves.connectors.bol.client_id' => 'a-client-id',
            'giftcoves.connectors.bol.client_secret' => 'a-secret',
        ]);

        $this->assertNotContains(
            'BOL_CLIENT_ID',
            $report->failures(),
            'bol is configured and the report still calls it missing',
        );

        // Amazon's credentials are only required while Amazon is on, so the
        // enabled flag has to resolve too — a null there makes them optional
        // forever and hides a genuinely missing key.
        config(['giftcoves.connectors.amazon.enabled' => true]);

        $this->assertContains('AMAZON_ACCESS_KEY', $report->failures());
    }

    #[Test]
    public function the_deploy_button_is_absent_until_a_webhook_exists(): void
    {
        // A button that cannot work is worse than no button: it invites a click
        // and then explains itself in a toast.
        Livewire::actingAs($this->admin())
            ->test(Migration::class)
            ->assertActionHidden('deploy');

        app(DeployTrigger::class)->setWebhook('https://coolify.example.test/api/v1/deploy?uuid=abc');

        Livewire::actingAs($this->admin())
            ->test(Migration::class)
            ->assertActionVisible('deploy');
    }

    #[Test]
    public function the_webhook_is_stored_encrypted_and_never_rendered(): void
    {
        $url = 'https://coolify.example.test/api/v1/deploy?uuid=secret-uuid-9999';

        Livewire::actingAs($this->admin())
            ->test(Migration::class)
            ->set('data.webhook', $url)
            ->call('saveWebhook');

        $this->assertSame($url, app(DeployTrigger::class)->webhook());

        // Encrypted at rest, like every other admin-editable setting: a
        // production dump restored on a laptop must not hand over a URL that
        // redeploys production.
        // Read through the query builder, not Eloquent: `Model::query()->value()`
        // hydrates a model and applies the cast, so it would hand back the
        // decrypted URL and the assertion would be testing nothing.
        $raw = DB::table('connector_settings')
            ->where('source', DeployTrigger::SOURCE)
            ->where('key', DeployTrigger::KEY)
            ->value('encrypted_value');

        $this->assertNotNull($raw);
        $this->assertStringNotContainsString('secret-uuid-9999', (string) $raw);

        // And it must not come back out into the page once stored.
        Livewire::actingAs($this->admin())
            ->test(Migration::class)
            ->assertDontSee('secret-uuid-9999');
    }

    #[Test]
    public function deploying_posts_to_the_webhook(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        app(DeployTrigger::class)->setWebhook('https://coolify.example.test/api/v1/deploy?uuid=abc');

        Livewire::actingAs($this->admin())
            ->test(Migration::class)
            ->call('deploy');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'deploy?uuid=abc'));

        $this->assertTrue(app(DeployTrigger::class)->last()['ok']);
    }

    #[Test]
    public function a_refused_webhook_is_reported_rather_than_thrown(): void
    {
        // This runs from a button. An unhandled exception on an admin screen is
        // a stack trace where a sentence belongs.
        Http::fake(['*' => Http::response('nope', 401)]);

        app(DeployTrigger::class)->setWebhook('https://coolify.example.test/api/v1/deploy?uuid=abc');

        Livewire::actingAs($this->admin())
            ->test(Migration::class)
            ->call('deploy')
            ->assertOk();

        $this->assertFalse(app(DeployTrigger::class)->last()['ok']);
    }

    #[Test]
    public function apply_is_hidden_until_something_has_been_checked(): void
    {
        /*
         * The drop list is the reason to run an import at all — production's
         * catalogue is smaller than staging's, so some picks have no
         * counterpart. Allowing a write before that list has been produced
         * would hide it behind a fait accompli.
         */
        Livewire::actingAs($this->admin())
            ->test(Migration::class)
            ->assertActionHidden('apply');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Enums\Source;
use App\Filament\Resources\ApiTokens\Pages\ListApiTokens;
use App\Filament\Resources\IngestionJobs\IngestionJobResource;
use App\Filament\Resources\Products\ProductResource;
use App\Models\ApiToken;
use App\Models\Feed;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The admin panel exposes connector credentials, AI spend and the whole
 * catalogue, so who can reach it is a security boundary, not a convenience.
 */
class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private function user(bool $admin): User
    {
        $user = User::create([
            'name' => 'Test',
            'email' => ($admin ? 'admin' : 'user').'@example.test',
            'password' => 'password-for-testing',
        ]);

        // is_admin is deliberately NOT mass-assignable, so it has to be forced.
        // That is the point: no request payload can ever grant admin, and there
        // is no self-service path to the panel.
        if ($admin) {
            $user->forceFill(['is_admin' => true])->save();
        }

        return $user;
    }

    #[Test]
    public function admin_cannot_be_granted_by_mass_assignment(): void
    {
        $user = User::create([
            'name' => 'Attacker',
            'email' => 'attacker@example.test',
            'password' => 'password-for-testing',
            'is_admin' => true,
        ]);

        $this->assertFalse($user->fresh()->is_admin, 'is_admin must never be mass-assignable');
    }

    #[Test]
    public function a_guest_is_sent_to_the_login(): void
    {
        $this->get('/admin')->assertRedirect();
    }

    #[Test]
    public function a_signed_in_non_admin_cannot_reach_it(): void
    {
        // 403 from Filament's own gate. The is_admin flag has no self-service
        // path — granting it is a deliberate manual act.
        $this->actingAs($this->user(admin: false))
            ->get('/admin')
            ->assertForbidden();
    }

    #[Test]
    public function an_admin_can_reach_the_dashboard(): void
    {
        $this->actingAs($this->user(admin: true))
            ->get('/admin')
            ->assertOk();
    }

    #[Test]
    public function the_catalogue_pages_render(): void
    {
        Feed::create([
            'source' => Source::Awin,
            'external_feed_id' => '18755',
            'market' => Market::BeNl,
            'label' => 'Test advertiser',
            'enabled' => true,
        ]);

        $admin = $this->user(admin: true);

        foreach (['/admin/feeds', '/admin/merchants', '/admin/products', '/admin/ingestion-jobs'] as $path) {
            $this->actingAs($admin)->get($path)->assertOk();
        }
    }

    #[Test]
    public function the_content_and_operations_pages_render(): void
    {
        /*
         * A smoke test, and a load-bearing one.
         *
         * Filament resources are configured entirely in static methods that no
         * other test touches, so a wrong column name or a renamed enum case is
         * invisible until someone opens the page — usually while trying to fix
         * something else. Rendering each one is the cheapest way to keep that
         * from being a surprise.
         */
        $admin = $this->user(admin: true);

        foreach ([
            '/admin/guides',
            '/admin/daily-editions',
            '/admin/mode-profiles',
            '/admin/cove-plans',
            '/admin/guide-topics',
            '/admin/ai-usage',
            '/admin/edit-page-copy',
            '/admin/copy-templates',
            '/admin/api-tokens',
            '/admin/migration',
        ] as $path) {
            // Named, so a failure says which page rather than which loop
            // iteration — the whole value of a smoke test is being able to act
            // on it without re-running it.
            $this->actingAs($admin)->get($path)->assertOk("{$path} did not render");
        }
    }

    #[Test]
    public function an_admin_without_a_name_can_use_the_panel(): void
    {
        /*
         * `users.name` is nullable — this site signs people in by magic link
         * and a shopper never gives a name. Filament declares
         * `getUserName(): string` and threw a TypeError on every panel page for
         * an admin who had none, which surfaced as a 500 immediately after a
         * successful login and read as "login is broken".
         *
         * Every other test in this file creates users WITH a name, which is
         * exactly why none of them caught it.
         */
        $user = User::create(['email' => 'nameless@example.test', 'password' => 'password-for-testing']);
        $user->forceFill(['is_admin' => true, 'name' => null])->save();

        $this->actingAs($user->fresh())->get('/admin')->assertOk();

        // Recognisably them, rather than every nameless admin sharing one
        // placeholder.
        $this->assertSame('nameless', $user->fresh()->getFilamentName());
    }

    #[Test]
    public function minting_a_key_in_the_panel_produces_a_working_credential(): void
    {
        $admin = $this->user(admin: true);

        $component = Livewire::actingAs($admin)
            ->test(ListApiTokens::class)
            ->callAction('mint', [
                'name' => 'Claude, daily Coves',
                'abilities' => [ApiToken::READ, ApiToken::WRITE],
                'expires_at' => null,
            ])
            // The mint does not close on success — it swaps itself for the
            // reveal, because the plaintext exists exactly once and a toast
            // that a stray click dismisses is the wrong container for it.
            ->assertActionMounted('revealToken');

        $token = ApiToken::query()->firstOrFail();

        $this->assertSame('Claude, daily Coves', $token->name);
        $this->assertSame([ApiToken::READ, ApiToken::WRITE], $token->abilities);
        $this->assertSame($admin->id, $token->created_by);

        // The whole point of the reveal: the plaintext has to reach the admin,
        // because nothing can recover it afterwards.
        $plaintext = $this->mountedRevealToken($component);
        $this->assertNotNull($plaintext, 'the reveal modal was not handed the plaintext');
        $this->assertSame(hash('sha256', $plaintext), $token->token_hash);

        /*
         * And the modal body renders.
         *
         * Rendered directly rather than asserted against the page HTML: Filament
         * fills action modals into a `wire:partial` on demand, so the mounted
         * modal is genuinely absent from the initial response. Which leaves the
         * view itself untested by anything else — and a mistyped Blade component
         * in it would blow up at the exact moment the key is shown, the one
         * moment there is no second chance.
         */
        // `canPublish: true` because that branch carries the extra components;
        // the other one is a strict subset of it.
        $modal = view('filament.api-token-reveal', [
            'token' => $plaintext,
            'name' => $token->name,
            'canPublish' => true,
        ])->render();

        $this->assertStringContainsString($plaintext, $modal);
        $this->assertStringContainsString('This key can publish', $modal);

        // And it has to actually authenticate. A key minted through the panel
        // and a key minted through the command are the same thing or the panel
        // is decorative.
        $this->withToken($plaintext)
            ->getJson('/api/editorial')
            ->assertOk()
            ->assertJsonPath('token.name', 'Claude, daily Coves');
    }

    #[Test]
    public function revoking_a_key_in_the_panel_kills_it_immediately(): void
    {
        ['token' => $plaintext, 'model' => $token] = ApiToken::issue('doomed', [ApiToken::READ]);

        $this->withToken($plaintext)->getJson('/api/editorial')->assertOk();

        Livewire::actingAs($this->user(admin: true))
            ->test(ListApiTokens::class)
            ->callAction(TestAction::make('revoke')->table($token));

        $this->withToken($plaintext)->getJson('/api/editorial')->assertStatus(401);
        $this->assertNotNull($token->refresh()->revoked_at);
    }

    /** The plaintext the reveal modal was handed, if one is mounted. */
    private function mountedRevealToken(Testable $component): ?string
    {
        foreach ($component->instance()->mountedActions as $action) {
            if (($action['name'] ?? null) === 'revealToken') {
                return $action['arguments']['token'] ?? null;
            }
        }

        return null;
    }

    #[Test]
    public function offers_and_ingestion_state_cannot_be_edited_by_hand(): void
    {
        // Offers are owned by the feeds and would be overwritten on the next
        // run; a cursor is a resume point and editing one corrupts it.
        $this->assertFalse(ProductResource::canCreate());
        $this->assertFalse(IngestionJobResource::canCreate());
    }
}

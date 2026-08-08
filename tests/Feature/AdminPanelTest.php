<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Enums\Source;
use App\Filament\Resources\IngestionJobs\IngestionJobResource;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Feed;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            '/admin/ai-usage',
        ] as $path) {
            // Named, so a failure says which page rather than which loop
            // iteration — the whole value of a smoke test is being able to act
            // on it without re-running it.
            $this->actingAs($admin)->get($path)->assertOk("{$path} did not render");
        }
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

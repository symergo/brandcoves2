<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The list wizard on the front page.
 *
 * It replaced the Organise band on 2026-09-13 at the owner's request. The
 * wizard is the same component My Lists and the Gift Cove mount, fed by the
 * same service, and this pins that the front page feeds it the same way — a
 * wizard with an empty people picker for somebody who has people would be
 * the bug ListWizardTest already guards against, one page over.
 */
class HomeListWizardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_front_page_offers_the_list_wizard_what_my_lists_offers_it(): void
    {
        $owner = User::factory()->create();
        Recipient::factory()->create(['owner_user_id' => $owner->id, 'name' => 'Mum']);

        $props = $this->actingAs($owner)->get('/be-nl')->assertOk()->viewData('page')['props'];

        $this->assertTrue($props['signedIn']);
        $this->assertSame(['Mum'], array_column($props['recipients'], 'name'));
        $this->assertIsArray($props['friends']);
        $this->assertNotEmpty($props['occasions']);
        $this->assertIsArray($props['myLists']);
        $this->assertArrayNotHasKey('gifting', $props);
    }

    #[Test]
    public function a_visitor_gets_the_wizard_as_an_explanation(): void
    {
        // Signed out, the wizard explains what a list is and ends in the
        // sign-in; the offer is empty rather than absent.
        $props = $this->get('/be-nl')->assertOk()->viewData('page')['props'];

        $this->assertFalse($props['signedIn']);
        $this->assertSame([], $props['recipients']);
        $this->assertSame([], $props['friends']);
    }
}

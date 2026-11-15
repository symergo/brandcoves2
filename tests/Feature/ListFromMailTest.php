<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\Market;
use App\Http\Controllers\ListFromMailController;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The signed link in a mail about a list opens that one list, read-only,
 * without signing in (owner, 2026-11-15: "open only the list").
 */
class ListFromMailTest extends TestCase
{
    use RefreshDatabase;

    private function list(): Wishlist
    {
        $owner = User::factory()->create();
        $list = Wishlist::factory()->create([
            'owner_user_id' => $owner->id,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
            'title' => 'Mijn wensen',
        ]);
        $list->items()->create(['snapshot_title' => 'Koptelefoon', 'source' => 'manual']);

        return $list;
    }

    #[Test]
    public function the_signed_link_opens_the_list_read_only_without_signing_in(): void
    {
        $list = $this->list();

        $this->get(ListFromMailController::url($list))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Lists/FromMail')
                ->where('list.title', 'Mijn wensen')
                ->where('list.items.0.title', 'Koptelefoon')
                // Nothing about claims, contributions or the people it is shared with.
                ->missing('list.items.0.claim')
                ->missing('collaborators')
                ->missing('deliveryAddress')
                ->where('loginUrl', '/be-nl/login'));

        // Signing in from there comes back to the full list.
        $this->assertSame(url("/be-nl/lists/{$list->id}"), session('url.intended'));
    }

    #[Test]
    public function an_unsigned_tampered_or_expired_link_goes_to_the_lists_own_address(): void
    {
        $list = $this->list();
        $plain = "/be-nl/lists/{$list->id}/from-mail";

        $this->get($plain)->assertRedirect("/be-nl/lists/{$list->id}");
        $this->get(ListFromMailController::url($list).'x')->assertRedirect("/be-nl/lists/{$list->id}");

        $url = ListFromMailController::url($list);
        $this->travel(ListFromMailController::DAYS + 1)->days();
        $this->get($url)->assertRedirect("/be-nl/lists/{$list->id}");

        // Which, signed out, asks to sign in rather than answering 404.
        $this->get("/be-nl/lists/{$list->id}")->assertRedirect('/be-nl/login');
    }

    #[Test]
    public function the_owner_signed_in_gets_the_full_list(): void
    {
        $list = $this->list();

        $this->actingAs($list->owner)
            ->get(ListFromMailController::url($list))
            ->assertRedirect("/be-nl/lists/{$list->id}");
    }
}

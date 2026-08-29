<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CollaboratorRole;
use App\Enums\ListKind;
use App\Enums\Market;
use App\Models\ProductGroup;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistCollaborator;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Filling one list, rather than saving one product.
 *
 * "Find things to add" used to point at a bare search page that knew nothing
 * about the list it had been reached from, so every product then cost a trip
 * through the picker to choose the destination that pressing the link had
 * already implied. Ten items, ten identical decisions.
 */
class AddingModeTest extends TestCase
{
    use RefreshDatabase;

    private function listFor(User $user): Wishlist
    {
        return Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'kind' => ListKind::Mine,
            'title' => 'Camping',
            'market' => Market::BeNl,
        ]);
    }

    #[Test]
    public function starting_lands_on_search_and_names_the_list(): void
    {
        $user = User::factory()->create();
        $list = $this->listFor($user);

        $this->actingAs($user)
            ->get("/be-nl/lists/{$list->id}/add")
            ->assertRedirect('/be-nl/search');

        $this->actingAs($user)
            ->get('/be-nl/search?q=tent')
            ->assertInertia(fn ($page) => $page
                ->where('savingTo.id', $list->id)
                ->where('savingTo.title', 'Camping'));
    }

    /** It has to be visible everywhere, not only where it was switched on. */
    #[Test]
    public function the_mode_is_carried_to_every_other_surface(): void
    {
        $user = User::factory()->create();
        $list = $this->listFor($user);

        $this->actingAs($user)->get("/be-nl/lists/{$list->id}/add");

        $this->actingAs($user)
            ->get('/be-nl/lists')
            ->assertInertia(fn ($page) => $page->where('savingTo.id', $list->id));
    }

    #[Test]
    public function finishing_clears_it_and_returns_to_the_list(): void
    {
        $user = User::factory()->create();
        $list = $this->listFor($user);

        $this->actingAs($user)->get("/be-nl/lists/{$list->id}/add");

        $this->actingAs($user)
            ->get('/be-nl/done-adding')
            ->assertRedirect("/be-nl/lists/{$list->id}");

        $this->actingAs($user)
            ->get('/be-nl/search')
            ->assertInertia(fn ($page) => $page->where('savingTo', null));
    }

    /** Nobody is in this mode unless they asked to be. */
    #[Test]
    public function it_is_off_by_default(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/be-nl/search')
            ->assertInertia(fn ($page) => $page->where('savingTo', null));
    }

    /** A viewer was brought in to coordinate, not to curate. */
    #[Test]
    public function a_viewer_cannot_start_filling_someone_elses_list(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $list = $this->listFor($owner);

        WishlistCollaborator::query()->create([
            'wishlist_id' => $list->id,
            'user_id' => $viewer->id,
            'role' => CollaboratorRole::Viewer,
        ]);

        $this->actingAs($viewer)
            ->get("/be-nl/lists/{$list->id}/add")
            ->assertForbidden();
    }

    #[Test]
    public function an_editor_can(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();
        $list = $this->listFor($owner);

        WishlistCollaborator::query()->create([
            'wishlist_id' => $list->id,
            'user_id' => $editor->id,
            'role' => CollaboratorRole::Editor,
        ]);

        $this->actingAs($editor)
            ->get("/be-nl/lists/{$list->id}/add")
            ->assertRedirect('/be-nl/search');
    }

    #[Test]
    public function a_stranger_gets_a_404_rather_than_a_403(): void
    {
        $list = $this->listFor(User::factory()->create());

        $this->actingAs(User::factory()->create())
            ->get("/be-nl/lists/{$list->id}/add")
            ->assertNotFound();
    }

    /**
     * The mode ends on its own when the list does.
     *
     * Re-checked per request rather than trusted from the session: access can
     * be taken away, and a mode that outlived permission would send every
     * subsequent save into a 403 with nothing on screen explaining why.
     */
    #[Test]
    public function deleting_the_list_ends_the_mode(): void
    {
        $user = User::factory()->create();
        $list = $this->listFor($user);

        $this->actingAs($user)->get("/be-nl/lists/{$list->id}/add");

        $list->delete();

        $this->actingAs($user)
            ->get('/be-nl/search')
            ->assertInertia(fn ($page) => $page->where('savingTo', null));
    }

    /**
     * The mode is a default, not a lock: the picker still reaches every list,
     * and a save that names one goes there.
     */
    #[Test]
    public function a_save_naming_another_list_still_goes_to_that_list(): void
    {
        $user = User::factory()->create();
        $camping = $this->listFor($user);

        $books = Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'kind' => ListKind::Mine,
            'title' => 'Books',
            'market' => Market::BeNl,
        ]);

        $this->actingAs($user)->get("/be-nl/lists/{$camping->id}/add");

        $group = ProductGroup::factory()->create(['market' => Market::BeNl]);

        $this->actingAs($user)->postJson('/be-nl/list-items', [
            'group_id' => $group->id,
            'wishlist_id' => $books->id,
        ])->assertOk();

        $this->assertSame(
            $books->id,
            WishlistItem::query()->where('group_id', $group->id)->firstOrFail()->wishlist_id,
        );
    }
}

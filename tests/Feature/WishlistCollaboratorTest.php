<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CollaboratorRole;
use App\Enums\ListKind;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistCollaborator;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Co-givers.
 *
 * The table, the model and the enum shipped with Phase 3 and nothing read them,
 * so `ListVisibility::Private`'s docblock ("owner and collaborators only") was
 * false for a year. These are the assertions that make it true.
 */
class WishlistCollaboratorTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Wishlist} */
    private function ownedList(): array
    {
        $owner = User::factory()->create();

        $list = Wishlist::factory()->create([
            'owner_user_id' => $owner->id,
            'kind' => ListKind::ForSomeone,
            'recipient_id' => Recipient::factory()->create(['owner_user_id' => $owner->id])->id,
        ]);

        return [$owner, $list];
    }

    private function collaborator(Wishlist $list, CollaboratorRole $role): User
    {
        $user = User::factory()->create();

        WishlistCollaborator::create([
            'wishlist_id' => $list->id,
            'user_id' => $user->id,
            'role' => $role->value,
        ]);

        return $user;
    }

    #[Test]
    public function a_collaborator_can_open_a_private_list(): void
    {
        [, $list] = $this->ownedList();
        $helper = $this->collaborator($list, CollaboratorRole::Viewer);

        // Private has always meant "owner and collaborators only" in the
        // docblock and "owner only" in the code.
        $this->actingAs($helper)
            ->get("/be-nl/lists/{$list->id}")
            ->assertOk();
    }

    #[Test]
    public function a_stranger_still_cannot(): void
    {
        [, $list] = $this->ownedList();

        $this->actingAs(User::factory()->create())
            ->get("/be-nl/lists/{$list->id}")
            ->assertNotFound();
    }

    #[Test]
    public function a_viewer_cannot_add_items(): void
    {
        [, $list] = $this->ownedList();
        $helper = $this->collaborator($list, CollaboratorRole::Viewer);

        // Brought in to coordinate, not to curate.
        $this->actingAs($helper)
            ->post('/be-nl/list-items', [
                'wishlist_id' => $list->id,
                'source' => 'bol',
                'external_id' => '9200000123',
                'title' => 'A thing',
            ])
            ->assertForbidden();
    }

    #[Test]
    public function an_editor_can_add_items(): void
    {
        [, $list] = $this->ownedList();
        $helper = $this->collaborator($list, CollaboratorRole::Editor);

        $this->actingAs($helper)
            ->post('/be-nl/list-items', [
                'wishlist_id' => $list->id,
                'source' => 'bol',
                'external_id' => '9200000123',
                'title' => 'A thing',
            ])
            ->assertRedirect();

        $this->assertSame(1, $list->items()->count());
    }

    #[Test]
    public function a_viewer_cannot_delete_someone_elses_item(): void
    {
        [, $list] = $this->ownedList();
        $helper = $this->collaborator($list, CollaboratorRole::Viewer);

        $item = WishlistItem::factory()->create(['wishlist_id' => $list->id]);

        $this->actingAs($helper)
            ->delete("/be-nl/list-items/{$item->id}")
            ->assertForbidden();

        $this->assertSame(1, $list->items()->count());
    }

    #[Test]
    public function only_the_owner_manages_the_roster(): void
    {
        [, $list] = $this->ownedList();
        $helper = $this->collaborator($list, CollaboratorRole::Editor);

        /*
         * An editor may curate the list and may not grow its audience. A
         * collaborator who could invite more collaborators turns a private
         * research list into one with a readership, and the subject of a
         * `for_someone` list must never be in it.
         */
        $this->actingAs($helper)
            ->post("/be-nl/lists/{$list->id}/collaborators", ['email' => 'someone@example.test'])
            ->assertNotFound();
    }

    #[Test]
    public function inviting_does_not_reveal_whether_an_address_has_an_account(): void
    {
        [$owner, $list] = $this->ownedList();

        $known = User::factory()->create(['email' => 'known@example.test']);

        $withAccount = $this->actingAs($owner)
            ->post("/be-nl/lists/{$list->id}/collaborators", ['email' => $known->email]);

        $withoutAccount = $this->actingAs($owner)
            ->post("/be-nl/lists/{$list->id}/collaborators", ['email' => 'stranger@example.test']);

        // Otherwise the form is an oracle: type addresses in, read the response,
        // learn which of your friends use the site.
        $this->assertSame($withAccount->status(), $withoutAccount->status());
        $this->assertSame(
            $withAccount->getSession()->get('success'),
            $withoutAccount->getSession()->get('success'),
        );

        $this->assertSame(1, $list->collaborators()->count());
    }

    #[Test]
    public function the_owner_is_never_added_as_their_own_collaborator(): void
    {
        [$owner, $list] = $this->ownedList();

        $this->actingAs($owner)
            ->post("/be-nl/lists/{$list->id}/collaborators", ['email' => $owner->email]);

        $this->assertSame(0, $list->collaborators()->count());
    }

    #[Test]
    public function a_collaborator_is_not_shown_the_roster(): void
    {
        [, $list] = $this->ownedList();
        $helper = $this->collaborator($list, CollaboratorRole::Editor);

        $this->actingAs($helper)
            ->get("/be-nl/lists/{$list->id}")
            ->assertInertia(fn ($page) => $page
                ->where('access.isOwner', false)
                ->where('collaborators', []));
    }
}

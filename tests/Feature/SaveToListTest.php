<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\Market;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Saving a product somewhere you chose.
 *
 * Every save used to land in a default `mine` list with no way to pick, so
 * "keep this for my sister" had no path through the interface at all — the
 * schema could express it and nothing could reach it.
 */
class SaveToListTest extends TestCase
{
    use RefreshDatabase;

    private function group(): ProductGroup
    {
        return ProductGroup::factory()->create(['market' => Market::BeNl]);
    }

    #[Test]
    public function the_picker_offers_both_kinds_of_list(): void
    {
        $user = User::factory()->create();

        Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'kind' => ListKind::Mine,
            'title' => 'Things I want',
            'market' => Market::BeNl,
        ]);

        $recipient = Recipient::factory()->create(['owner_user_id' => $user->id, 'name' => 'Mum']);

        Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'recipient_id' => $recipient->id,
            'kind' => ListKind::ForSomeone,
            'market' => Market::BeNl,
        ]);

        $lists = $this->actingAs($user)
            ->getJson('/be-nl/list-options')
            ->assertOk()
            ->assertJsonCount(2, 'lists')
            ->json('lists');

        // By content, not position: both rows are written in the same second, so
        // ordering on `updated_at` is a coin toss and asserting an index would
        // be a test that fails for a reason unrelated to what it checks.
        $byKind = array_column($lists, null, 'kind');

        $this->assertArrayHasKey('mine', $byKind);
        $this->assertArrayHasKey('for_someone', $byKind);
        $this->assertSame('Things I want', $byKind['mine']['title']);
        $this->assertSame('Mum', $byKind['for_someone']['recipient']);
    }

    #[Test]
    public function the_picker_is_empty_rather_than_forbidden_for_a_stranger(): void
    {
        // Anonymous-first: no identity yet is a normal state, not an error.
        $this->getJson('/be-nl/list-options')
            ->assertOk()
            ->assertJsonPath('lists', [])
            ->assertJsonPath('recipients', []);
    }

    #[Test]
    public function a_product_can_be_saved_into_a_chosen_list(): void
    {
        $user = User::factory()->create();

        $mine = Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
        ]);

        $forMum = Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'recipient_id' => Recipient::factory()->create(['owner_user_id' => $user->id])->id,
            'kind' => ListKind::ForSomeone,
            'market' => Market::BeNl,
        ]);

        $this->actingAs($user)
            ->post('/be-nl/list-items', [
                'group_id' => $this->group()->id,
                'wishlist_id' => $forMum->id,
            ])
            ->assertRedirect();

        $this->assertSame(1, $forMum->items()->count());
        $this->assertSame(0, $mine->items()->count());
    }

    #[Test]
    public function a_new_list_can_be_created_in_the_same_step(): void
    {
        $user = User::factory()->create();

        /*
         * "Save this to a new list for my sister" is one intention. Making
         * somebody leave the product, create a list, come back and find the
         * product again is how the second list never gets made.
         */
        $this->actingAs($user)
            ->post('/be-nl/list-items', [
                'group_id' => $this->group()->id,
                'new_list' => 'For Kim',
                'new_recipient' => 'Kim',
            ])
            ->assertRedirect();

        $list = Wishlist::query()->where('title', 'For Kim')->firstOrFail();

        // The recipient decides the kind; the two cannot disagree.
        $this->assertSame(ListKind::ForSomeone, $list->kind);
        $this->assertSame('Kim', $list->recipient->name);
        $this->assertSame(1, $list->items()->count());
    }

    #[Test]
    public function a_new_list_without_a_person_is_my_own(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/be-nl/list-items', [
                'group_id' => $this->group()->id,
                'new_list' => 'Birthday ideas',
            ])
            ->assertRedirect();

        $list = Wishlist::query()->where('title', 'Birthday ideas')->firstOrFail();

        $this->assertSame(ListKind::Mine, $list->kind);
        $this->assertNull($list->recipient_id);
    }

    #[Test]
    public function you_cannot_attach_someone_elses_person_to_your_list(): void
    {
        $stranger = Recipient::factory()->create([
            'owner_user_id' => User::factory()->create()->id,
        ]);

        $this->actingAs(User::factory()->create())
            ->post('/be-nl/list-items', [
                'group_id' => $this->group()->id,
                'new_list' => 'Sneaky',
                'recipient_id' => $stranger->id,
            ])
            ->assertForbidden();
    }

    #[Test]
    public function you_cannot_save_into_a_list_you_have_no_part_in(): void
    {
        $other = Wishlist::factory()->create([
            'owner_user_id' => User::factory()->create()->id,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
        ]);

        $this->actingAs(User::factory()->create())
            ->post('/be-nl/list-items', [
                'group_id' => $this->group()->id,
                'wishlist_id' => $other->id,
            ])
            ->assertNotFound();
    }
}

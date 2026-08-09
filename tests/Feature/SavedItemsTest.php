<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\Market;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which products a card should show as already saved.
 *
 * The control lied about the one thing it exists to report: something saved
 * last week rendered with an empty bookmark, and the only way to find out was
 * to save it again.
 */
class SavedItemsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reports_what_is_on_your_lists(): void
    {
        $user = User::factory()->create();
        $group = ProductGroup::factory()->create(['market' => Market::BeNl]);

        $list = Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
        ]);

        WishlistItem::factory()->of($group)->create(['wishlist_id' => $list->id]);

        $this->actingAs($user)
            ->getJson('/be-nl/saved-items')
            ->assertOk()
            ->assertJsonPath('groupIds', [$group->id]);
    }

    #[Test]
    public function a_thing_on_a_gift_list_counts_too(): void
    {
        $user = User::factory()->create();
        $group = ProductGroup::factory()->create(['market' => Market::BeNl]);

        $list = Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'recipient_id' => Recipient::factory()->create(['owner_user_id' => $user->id])->id,
            'kind' => ListKind::ForSomeone,
            'market' => Market::BeNl,
        ]);

        WishlistItem::factory()->of($group)->create(['wishlist_id' => $list->id]);

        // A thing on your research list for your mother is still a thing you
        // have already found.
        $this->actingAs($user)
            ->getJson('/be-nl/saved-items')
            ->assertJsonPath('groupIds', [$group->id]);
    }

    #[Test]
    public function a_pending_suggestion_does_not_count(): void
    {
        $user = User::factory()->create();
        $group = ProductGroup::factory()->create(['market' => Market::BeNl]);

        $list = Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
        ]);

        WishlistItem::factory()->of($group)->create([
            'wishlist_id' => $list->id,
            'accepted_at' => null,
        ]);

        // It is not on the list until accepted, so the bookmark must not claim
        // it is.
        $this->actingAs($user)
            ->getJson('/be-nl/saved-items')
            ->assertJsonPath('groupIds', []);
    }

    #[Test]
    public function another_market_is_not_reported(): void
    {
        $user = User::factory()->create();
        $group = ProductGroup::factory()->create(['market' => Market::Es]);

        $list = Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'kind' => ListKind::Mine,
            'market' => Market::Es,
        ]);

        WishlistItem::factory()->of($group)->create(['wishlist_id' => $list->id]);

        $this->actingAs($user)
            ->getJson('/be-nl/saved-items')
            ->assertJsonPath('groupIds', []);
    }

    #[Test]
    public function someone_elses_list_is_not_reported(): void
    {
        $group = ProductGroup::factory()->create(['market' => Market::BeNl]);

        $theirs = Wishlist::factory()->create([
            'owner_user_id' => User::factory()->create()->id,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
        ]);

        WishlistItem::factory()->of($group)->create(['wishlist_id' => $theirs->id]);

        $this->actingAs(User::factory()->create())
            ->getJson('/be-nl/saved-items')
            ->assertJsonPath('groupIds', []);
    }

    #[Test]
    public function a_signed_out_visitor_gets_an_empty_answer(): void
    {
        // Saving requires an account, so there is nothing to report and no
        // reason to query for it.
        $this->getJson('/be-nl/saved-items')
            ->assertOk()
            ->assertJsonPath('groupIds', []);
    }
}

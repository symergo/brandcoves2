<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The page you land on straight after making a list for somebody.
 *
 * Reported as "turns black", which is what a React tree that throws during
 * render looks like — every prop the page destructures has to be there.
 */
class GiftListPageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function creating_a_list_for_someone_lands_on_a_page_with_every_prop_it_reads(): void
    {
        $user = User::factory()->create();
        $recipient = Recipient::factory()->create(['owner_user_id' => $user->id, 'name' => 'Mum']);

        $this->actingAs($user)
            ->post('/be-nl/lists', ['title' => 'Gifts for Mum', 'recipient_id' => $recipient->id])
            ->assertRedirect();

        $list = Wishlist::query()->where('title', 'Gifts for Mum')->firstOrFail();

        $this->actingAs($user)
            ->get("/be-nl/lists/{$list->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Lists/Show')
                ->has('access')
                ->has('collaborators')
                ->has('suggestions')
                ->has('canHandOver')
                ->has('registryOptions')
                ->has('santaMemberships')
                ->has('asked')
                ->has('target')
                ->has('items')
                ->has('list'));
    }

    #[Test]
    public function the_newest_thing_on_a_list_is_at_the_top_of_it(): void
    {
        $user = User::factory()->create();
        $list = Wishlist::factory()->create(['owner_user_id' => $user->id]);

        // The add panel sits above the list, so a save that appends puts the
        // thing you just added below everything you added before it — off the
        // screen on any list worth having, which reads as a save that failed.
        $old = WishlistItem::factory()->create([
            'wishlist_id' => $list->id,
            'created_at' => now()->subWeek(),
        ]);
        $new = WishlistItem::factory()->create(['wishlist_id' => $list->id]);

        // Priority still outranks age: a starred item stays where it was put.
        $starred = WishlistItem::factory()->create([
            'wishlist_id' => $list->id,
            'priority' => 2,
            'created_at' => now()->subMonth(),
        ]);

        $this->actingAs($user)
            ->get("/be-nl/lists/{$list->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('items.0.id', $starred->id)
                ->where('items.1.id', $new->id)
                ->where('items.2.id', $old->id));
    }

    #[Test]
    public function the_same_page_for_your_own_list_carries_them_too(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/be-nl/lists', ['title' => 'Things I want'])
            ->assertRedirect();

        $list = Wishlist::query()->where('title', 'Things I want')->firstOrFail();

        $this->actingAs($user)
            ->get("/be-nl/lists/{$list->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('target', null)
                ->has('registryOptions')
                ->has('access'));
    }
}

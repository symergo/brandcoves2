<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\Market;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use App\Services\Wishlist\ListBudget;
use App\Support\CurrentMarket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A budget is a list's, not a person's (owner, 2026-10-05): set in the list's
 * settings, shown on the list, and what Find a gift and This or that read and
 * keep for a person goes on that person's list. See docs/features/list-budget.md.
 */
class ListBudgetTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_lists_settings_take_a_budget_in_euros_and_keep_cents(): void
    {
        $me = User::factory()->create();
        $mum = Recipient::factory()->create(['owner_user_id' => $me->id]);
        $list = Wishlist::factory()->forSomeone($mum)->create(['owner_user_id' => $me->id]);

        $this->actingAs($me)->patch("/be-nl/lists/{$list->id}", ['budget_min' => 20, 'budget_max' => '49.50'])
            ->assertRedirect();

        $list->refresh();
        $this->assertSame(2000, $list->budget_min);
        $this->assertSame(4950, $list->budget_max);

        $this->actingAs($me)->get("/be-nl/lists/{$list->id}")
            ->assertInertia(fn ($page) => $page->where('list.budgetMax', 4950));

        // Emptied, it is no budget.
        $this->actingAs($me)->patch("/be-nl/lists/{$list->id}", ['budget_min' => null, 'budget_max' => null]);
        $this->assertNull($list->fresh()->budget_max);
    }

    #[Test]
    public function a_top_under_the_bottom_is_refused(): void
    {
        $me = User::factory()->create();
        $list = Wishlist::factory()->create(['owner_user_id' => $me->id]);

        $this->actingAs($me)->patch("/be-nl/lists/{$list->id}", ['budget_min' => 50, 'budget_max' => 20])
            ->assertSessionHasErrors('budget_max');
    }

    #[Test]
    public function a_budget_for_a_person_without_a_list_makes_them_one(): void
    {
        $me = User::factory()->create();
        $mum = Recipient::factory()->create(['owner_user_id' => $me->id, 'name' => 'Mum']);
        $this->app->instance(CurrentMarket::class, new CurrentMarket(Market::BeNl));

        app(ListBudget::class)->rememberFor($mum, null, 3000);

        $list = Wishlist::query()->where('recipient_id', $mum->id)->sole();
        $this->assertSame(ListKind::ForSomeone, $list->kind);
        $this->assertSame($me->id, $list->owner_user_id);
        $this->assertSame(3000, $list->budget_max);
        $this->assertSame(['min' => null, 'max' => 3000], app(ListBudget::class)->forRecipient($mum));
    }
}

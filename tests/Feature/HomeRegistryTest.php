<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\EventType;
use App\Enums\ListKind;
use App\Enums\ListVisibility;
use App\Enums\Market;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The registry card on the front page.
 *
 * A registry is not a fourth kind of list — it is a `mine` list with an
 * occasion and a date attached — so the card looks for `event_type` rather than
 * for a kind, and links to the list itself rather than to a surface that does
 * not exist.
 *
 * The card is on the owner's own front page, so invariant #4 binds it
 * completely: it may say what the list is *for* and never how much of it has
 * been bought.
 */
class HomeRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function registry(User $owner, ?string $date, EventType $type = EventType::Wedding): Wishlist
    {
        return Wishlist::factory()->create([
            'owner_user_id' => $owner->id,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
            'visibility' => ListVisibility::Link,
            'event_type' => $type,
            'event_date' => $date,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function card(TestResponse $response): ?array
    {
        return $response->viewData('page')['props']['gifting']['registry'];
    }

    #[Test]
    public function a_visitor_with_no_registry_is_offered_an_explanation(): void
    {
        // Null, and the card then says what a registry is rather than naming
        // one. The band renders either way — this is the ordinary case.
        $response = $this->actingAs(User::factory()->create())->get('/be-nl')->assertOk();

        $this->assertNull($this->card($response));
    }

    #[Test]
    public function a_signed_out_visitor_gets_no_registry(): void
    {
        $this->assertNull($this->card($this->get('/be-nl')->assertOk()));
    }

    #[Test]
    public function a_registry_is_named_with_its_occasion_and_date(): void
    {
        $user = User::factory()->create();
        $list = $this->registry($user, now()->addMonths(3)->toDateString());

        $card = $this->card($this->actingAs($user)->get('/be-nl')->assertOk());

        $this->assertSame($list->title, $card['title']);
        $this->assertSame(__('site.registry.types.wedding'), $card['occasion']);
        $this->assertStringContainsString("lists/{$list->id}", $card['url']);
    }

    #[Test]
    public function an_ordinary_wish_list_is_not_a_registry(): void
    {
        // `event_type` is what makes one, not the kind and not the sharing.
        $user = User::factory()->create();

        Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
            'event_type' => null,
        ]);

        $this->assertNull($this->card($this->actingAs($user)->get('/be-nl')->assertOk()));
    }

    #[Test]
    public function a_registry_whose_date_has_passed_is_not_shown(): void
    {
        // The front page is for what is coming. Last summer's wedding is not
        // the list anybody is still adding to.
        $user = User::factory()->create();
        $this->registry($user, now()->subWeek()->toDateString());

        $this->assertNull($this->card($this->actingAs($user)->get('/be-nl')->assertOk()));
    }

    #[Test]
    public function the_soonest_registry_wins_rather_than_the_newest(): void
    {
        $user = User::factory()->create();

        $far = $this->registry($user, now()->addYear()->toDateString());
        $soon = $this->registry($user, now()->addWeek()->toDateString(), EventType::Baby);

        $card = $this->card($this->actingAs($user)->get('/be-nl')->assertOk());

        $this->assertSame($soon->title, $card['title']);
        $this->assertNotSame($far->title, $card['title']);
    }

    #[Test]
    public function a_dated_registry_outranks_an_undated_one(): void
    {
        // An occasion with no date is still a registry, and still loses to one
        // that is actually happening on a day. `ORDER BY event_date` alone
        // would sort the null first on Postgres defaults.
        $user = User::factory()->create();

        $undated = $this->registry($user, null);
        $dated = $this->registry($user, now()->addMonth()->toDateString(), EventType::Housewarming);

        $card = $this->card($this->actingAs($user)->get('/be-nl')->assertOk());

        $this->assertSame($dated->title, $card['title']);
        $this->assertNotSame($undated->title, $card['title']);
    }

    #[Test]
    public function the_card_carries_no_claim_state(): void
    {
        /*
         * Invariant #4 on the owner's own front page. A registry is claimable
         * by design, so this is exactly the surface where a helpful "2 of 8
         * bought" would be added by somebody who had not read the rule.
         */
        $user = User::factory()->create();
        $list = $this->registry($user, now()->addMonth()->toDateString());
        $item = WishlistItem::factory()->create(['wishlist_id' => $list->id]);

        $this->post("/be-nl/l/{$list->share_token}/claim/{$item->id}");

        $card = $this->card($this->actingAs($user)->get('/be-nl')->assertOk());

        foreach (['claimed', 'claims', 'progress', 'bought', 'taken'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $card);
        }
    }

    #[Test]
    public function another_persons_registry_is_not_yours(): void
    {
        $this->registry(User::factory()->create(), now()->addMonth()->toDateString());

        $this->assertNull($this->card(
            $this->actingAs(User::factory()->create())->get('/be-nl')->assertOk(),
        ));
    }

    #[Test]
    public function a_registry_in_another_market_stays_there(): void
    {
        $user = User::factory()->create();

        Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'kind' => ListKind::Mine,
            'market' => Market::NlNl,
            'event_type' => EventType::Wedding,
            'event_date' => now()->addMonth()->toDateString(),
        ]);

        $this->assertNull($this->card($this->actingAs($user)->get('/be-nl')->assertOk()));
    }
}

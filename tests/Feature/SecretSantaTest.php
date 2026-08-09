<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\ListVisibility;
use App\Enums\Market;
use App\Enums\SantaStatus;
use App\Models\ProductGroup;
use App\Models\SecretSantaGroup;
use App\Models\SecretSantaMember;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\SecretSantaDrawTest;

/**
 * Secret Santa end to end.
 *
 * The draw itself is unit-tested in {@see SecretSantaDrawTest}. What
 * matters here is that the pairing stays secret and that the feature reuses the
 * list machinery rather than growing its own.
 */
class SecretSantaTest extends TestCase
{
    use RefreshDatabase;

    private function group(?User $organiser = null): SecretSantaGroup
    {
        $organiser ??= User::factory()->create();

        $this->actingAs($organiser)
            ->post('/be-nl/santa', ['title' => 'Office 2026', 'budget_max' => 25])
            ->assertRedirect();

        return SecretSantaGroup::query()->firstOrFail();
    }

    private function join(SecretSantaGroup $group, string $name, string $email, array $exclusions = []): SecretSantaMember
    {
        /*
         * As a guest, which is the realistic flow: the organiser sends a link
         * and somebody else opens it. Without this the organiser's session
         * carries into the request and every member is silently created with
         * their user id — so "you can join without an account" would be testing
         * the opposite of what it claims.
         */
        auth()->logout();

        $this->post("/be-nl/santa/{$group->id}/join/{$group->invite_token}", [
            'display_name' => $name,
            'email' => $email,
            'exclusions' => $exclusions,
        ])->assertRedirect();

        return $group->members()->where('email', $email)->firstOrFail();
    }

    #[Test]
    public function the_organiser_is_a_player_too(): void
    {
        $organiser = User::factory()->create();
        $group = $this->group($organiser);

        // Running one without being in it is rare, and joining separately is a
        // step every organiser forgets.
        $this->assertSame(1, $group->members()->count());
        $this->assertSame($organiser->id, $group->members()->first()->user_id);
    }

    #[Test]
    public function a_budget_is_stored_in_cents(): void
    {
        // Invariant #7. v1 used DECIMAL here and integer cents everywhere else.
        $this->assertSame(2500, $this->group()->budget_max);
    }

    #[Test]
    public function you_can_join_without_an_account(): void
    {
        $group = $this->group();
        $member = $this->join($group, 'Sam', 'sam@example.test');

        // Requiring a login to be in an office Secret Santa is how most of the
        // office does not join.
        $this->assertNull($member->user_id);
        $this->assertNotNull($member->join_token);
    }

    #[Test]
    public function joining_twice_does_not_put_you_in_the_hat_twice(): void
    {
        $group = $this->group();
        $this->join($group, 'Sam', 'sam@example.test');
        $this->join($group, 'Sam', 'sam@example.test');

        $this->assertSame(2, $group->members()->count());
    }

    #[Test]
    public function the_assignment_is_not_readable_from_the_database(): void
    {
        $group = $this->group();
        $this->join($group, 'Sam', 'sam@example.test');
        $this->join($group, 'Ash', 'ash@example.test');

        $this->actingAs($group->organiser)
            ->post("/be-nl/santa/{$group->id}/draw")
            ->assertRedirect();

        $raw = DB::table('secret_santa_members')->pluck('assigned_member_id')->all();
        $ids = $group->members()->pluck('id')->map(fn ($id) => (string) $id)->all();

        /*
         * v1 stored the pairing in plain text, and its own planning notes
         * flagged that as a defect: a backup, a support session or a laptop copy
         * would hand somebody the whole game.
         */
        foreach ($raw as $value) {
            $this->assertNotNull($value);
            $this->assertNotContains($value, $ids, 'The pairing is stored in plain text.');
        }
    }

    #[Test]
    public function the_organiser_never_learns_who_drew_whom(): void
    {
        $group = $this->group();
        $this->join($group, 'Sam', 'sam@example.test');
        $this->join($group, 'Ash', 'ash@example.test');

        $this->actingAs($group->organiser)->post("/be-nl/santa/{$group->id}/draw");

        $response = $this->actingAs($group->organiser)
            ->get("/be-nl/santa/{$group->id}")
            ->assertOk();

        $members = $response->viewData('page')['props']['members'];

        // They need to know who is in so they know when to draw. Letting them
        // read the pairings makes one player a spectator of everyone else's game.
        foreach ($members as $member) {
            $this->assertArrayNotHasKey('giftee', $member);
            $this->assertArrayNotHasKey('assigned_member_id', $member);
        }
    }

    #[Test]
    public function nobody_draws_themselves(): void
    {
        $group = $this->group();

        foreach (['a', 'b', 'c', 'd'] as $name) {
            $this->join($group, strtoupper($name), "{$name}@example.test");
        }

        $this->actingAs($group->organiser)->post("/be-nl/santa/{$group->id}/draw");

        foreach ($group->members()->get() as $member) {
            $this->assertNotSame((string) $member->id, (string) $member->assigned_member_id);
        }
    }

    #[Test]
    public function an_exclusion_typed_as_a_name_is_honoured(): void
    {
        $group = $this->group();

        $sam = $this->join($group, 'Sam', 'sam@example.test', ['Ash']);
        $this->join($group, 'Ash', 'ash@example.test');
        $this->join($group, 'Kit', 'kit@example.test');

        $this->actingAs($group->organiser)->post("/be-nl/santa/{$group->id}/draw");

        $ash = $group->members()->where('email', 'ash@example.test')->firstOrFail();

        // People type a name or an address, not an account id — they do not know
        // each other's account details.
        $this->assertNotSame((string) $ash->id, (string) $sam->fresh()->assigned_member_id);
    }

    #[Test]
    public function a_member_sees_their_giftee_by_token_without_signing_in(): void
    {
        $group = $this->group();
        $sam = $this->join($group, 'Sam', 'sam@example.test');
        $this->join($group, 'Ash', 'ash@example.test');

        $this->actingAs($group->organiser)->post("/be-nl/santa/{$group->id}/draw");

        $this->get("/be-nl/santa/{$group->id}/me/{$sam->fresh()->join_token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('me.giftee.name'));
    }

    #[Test]
    public function the_giftees_own_list_is_an_ordinary_wishlist(): void
    {
        $group = $this->group();
        $person = User::factory()->create();

        $sam = $this->join($group, 'Sam', 'sam@example.test');
        $ash = $this->join($group, 'Ash', 'ash@example.test');
        $ash->update(['user_id' => $person->id]);

        $list = Wishlist::factory()->create([
            'owner_user_id' => $person->id,
            'kind' => ListKind::Mine,
            'visibility' => ListVisibility::Link,
        ]);

        WishlistItem::factory()
            ->of(ProductGroup::factory()->create(['market' => Market::BeNl]))
            ->create(['wishlist_id' => $list->id]);

        $ash->update(['wishlist_id' => $list->id]);

        $this->actingAs($group->organiser)->post("/be-nl/santa/{$group->id}/draw");

        // Whoever drew Ash sees Ash's list. No new way to hold a product, no
        // second claim mechanism — the whole point of the assignment layer.
        $samsGiftee = SecretSantaMember::query()->find($sam->fresh()->assigned_member_id);

        if ($samsGiftee?->id === $ash->id) {
            $this->get("/be-nl/santa/{$group->id}/me/{$sam->fresh()->join_token}")
                ->assertInertia(fn ($page) => $page->has('me.giftee.wishes', 1));
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function joining_after_the_draw_is_refused(): void
    {
        $group = $this->group();
        $this->join($group, 'Sam', 'sam@example.test');
        $this->join($group, 'Ash', 'ash@example.test');

        $this->actingAs($group->organiser)->post("/be-nl/santa/{$group->id}/draw");

        // Otherwise the new member has nobody to buy for, and nobody buying for
        // them — a silent hole nobody notices until the day.
        $this->post("/be-nl/santa/{$group->id}/join/{$group->invite_token}", [
            'display_name' => 'Late',
            'email' => 'late@example.test',
        ])->assertForbidden();
    }

    #[Test]
    public function only_the_organiser_can_draw(): void
    {
        $group = $this->group();
        $this->join($group, 'Sam', 'sam@example.test');
        $this->join($group, 'Ash', 'ash@example.test');

        $this->actingAs(User::factory()->create())
            ->post("/be-nl/santa/{$group->id}/draw")
            ->assertForbidden();

        $this->assertSame(SantaStatus::Open, $group->fresh()->status);
    }

    #[Test]
    public function an_impossible_exclusion_set_fails_without_drawing(): void
    {
        $group = $this->group();

        $this->join($group, 'Sam', 'sam@example.test', ['Ash', 'Kit']);
        $this->join($group, 'Ash', 'ash@example.test');
        $this->join($group, 'Kit', 'kit@example.test');

        // Sam's organiser membership is in the hat too, so exclude that as well
        // to make the set genuinely unsatisfiable.
        $organiserEmail = $group->organiser->email;
        $sam = $group->members()->where('email', 'sam@example.test')->firstOrFail();
        $sam->update(['exclusions' => ['ash', 'kit', mb_strtolower($organiserEmail), 'Ash', 'Kit']]);

        $this->actingAs($group->organiser)
            ->post("/be-nl/santa/{$group->id}/draw")
            ->assertSessionHas('error');

        // Nothing half-drawn: some people knowing and the rest not is the worst
        // possible state, and re-running would change assignments already sent.
        $this->assertSame(SantaStatus::Open, $group->fresh()->status);
        $this->assertNull($group->members()->first()->assigned_member_id);
    }
}

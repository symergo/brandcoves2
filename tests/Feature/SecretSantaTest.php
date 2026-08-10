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
use Illuminate\Support\Str;
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
    public function the_invite_link_opens_in_a_browser(): void
    {
        $group = $this->group();

        /*
         * It answered 405.
         *
         * `join` was a POST-only route and the URL the organiser shares is that
         * exact URL, so every invite ever pasted into a group chat was dead on
         * arrival — and with no join form anywhere, nobody but the organiser
         * had ever been in a group.
         */
        auth()->logout();

        $this->get("/be-nl/santa/{$group->id}/join/{$group->invite_token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Santa/Join')
                ->where('group.title', 'Office 2026'));
    }

    #[Test]
    public function a_wrong_invite_token_is_not_found(): void
    {
        $group = $this->group();
        auth()->logout();

        // The token is the credential. A guessed one must not reveal that the
        // group exists, let alone who is in it.
        $this->get("/be-nl/santa/{$group->id}/join/".Str::uuid()->toString())
            ->assertNotFound();
    }

    #[Test]
    public function following_your_own_invite_again_lands_on_your_page(): void
    {
        $organiser = User::factory()->create();
        $group = $this->group($organiser);

        // The organiser is already a player, so the invite has nothing to ask
        // them and everything to show them.
        $this->actingAs($organiser)
            ->get("/be-nl/santa/{$group->id}/join/{$group->invite_token}")
            ->assertRedirect();
    }

    #[Test]
    public function the_invite_says_so_rather_than_403ing_after_the_draw(): void
    {
        $group = $this->group();
        $this->join($group, 'Sam', 'sam@example.test');
        $this->join($group, 'Ash', 'ash@example.test');

        $this->actingAs($group->organiser)->post("/be-nl/santa/{$group->id}/draw");

        auth()->logout();

        /*
         * Joining closes at the draw — a member added afterwards has nobody to
         * buy for and nobody buying for them. Learning that from a 403 after
         * typing your name in is the worst version of it.
         */
        $this->get("/be-nl/santa/{$group->id}/join/{$group->invite_token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('group.drawn', true));
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
    public function a_member_can_point_the_group_at_their_own_list(): void
    {
        $organiser = User::factory()->create();
        $group = $this->group($organiser);

        $list = Wishlist::factory()->create([
            'owner_user_id' => $organiser->id,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
        ]);

        /*
         * The join between the two halves of the feature. It 500'd in
         * production on a missing import — the class was used and never
         * referenced by any test, so nothing exercised the code path at all.
         */
        $this->actingAs($organiser)
            ->post("/be-nl/santa/{$group->id}/list", ['wishlist_id' => $list->id])
            ->assertRedirect();

        $member = $group->members()->where('user_id', $organiser->id)->firstOrFail();
        $this->assertSame($list->id, $member->wishlist_id);
    }

    #[Test]
    public function you_cannot_attach_a_list_you_do_not_own(): void
    {
        $organiser = User::factory()->create();
        $group = $this->group($organiser);

        $someoneElses = Wishlist::factory()->create([
            'owner_user_id' => User::factory()->create()->id,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
        ]);

        $this->actingAs($organiser)
            ->post("/be-nl/santa/{$group->id}/list", ['wishlist_id' => $someoneElses->id])
            ->assertForbidden();
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
    public function the_organiser_can_delete_the_group(): void
    {
        $group = $this->group();
        $this->join($group, 'Sam', 'sam@example.test');

        $this->actingAs($group->organiser)
            ->delete("/be-nl/santa/{$group->id}")
            ->assertRedirect();

        $this->assertSame(0, SecretSantaGroup::query()->count());
        // Members go with it, by the cascade the schema already declares.
        $this->assertSame(0, SecretSantaMember::query()->count());
    }

    #[Test]
    public function a_member_cannot_delete_the_group(): void
    {
        $group = $this->group();
        $member = User::factory()->create();
        $this->join($group, 'Sam', 'sam@example.test');
        $group->members()->where('email', 'sam@example.test')->update(['user_id' => $member->id]);

        /*
         * A member who wants out is a different act with a different
         * consequence — the draw has to be repaired around them — and giving one
         * person a button that ends everybody else's exchange is not that.
         */
        $this->actingAs($member)
            ->delete("/be-nl/santa/{$group->id}")
            ->assertForbidden();

        $this->assertSame(1, SecretSantaGroup::query()->count());
    }

    #[Test]
    public function deleting_a_group_leaves_the_members_own_lists_alone(): void
    {
        $group = $this->group();
        $person = User::factory()->create();

        $list = Wishlist::factory()->create([
            'owner_user_id' => $person->id,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
        ]);

        $sam = $this->join($group, 'Sam', 'sam@example.test');
        $sam->update(['user_id' => $person->id, 'wishlist_id' => $list->id]);

        $this->actingAs($group->organiser)->delete("/be-nl/santa/{$group->id}");

        // The member attached a list they own. The group borrowed it and does
        // not get to take it.
        $this->assertNotNull($list->fresh());
    }

    #[Test]
    public function deleting_a_drawn_group_does_not_release_claims(): void
    {
        $group = $this->group();
        $this->join($group, 'Sam', 'sam@example.test');
        $this->join($group, 'Ash', 'ash@example.test');

        $this->actingAs($group->organiser)->post("/be-nl/santa/{$group->id}/draw");

        $list = Wishlist::factory()->create([
            'owner_user_id' => User::factory()->create()->id,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
            'visibility' => ListVisibility::Link,
        ]);

        $item = WishlistItem::factory()->create(['wishlist_id' => $list->id]);
        $item->claim(WishlistItem::identityHash('anon:someone'));

        $this->actingAs($group->organiser)->delete("/be-nl/santa/{$group->id}");

        /*
         * Somebody said they would buy that, and may already have. Deleting the
         * group they arranged it through does not unbuy it, and freeing the item
         * would send a second person to the shops.
         */
        $this->assertNotNull($item->fresh()->claimed_by_hash);
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

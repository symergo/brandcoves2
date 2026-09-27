<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\Market;
use App\Enums\RecipientStatus;
use App\Models\GiftPledge;
use App\Models\Recipient;
use App\Models\SecretSantaGroup;
use App\Models\SecretSantaMember;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistCollaborator;
use App\Services\Social\Friends;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Samen met {naam}" (2026-09-27): what you and a friend do together, on the
 * person's page and as a count and a Secret Santa mark on My people.
 *
 * Most of this class is the privacy rules (App\Services\Social\InCommon):
 * never a list about you, a friend's part in a group gift only where the list
 * would name them to you, and Secret Santa membership without the draw.
 */
class PeopleTogetherTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private User $sam;

    private Recipient $samSaved;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-01 10:00:00');
        $this->me = User::factory()->create(['name' => 'Me']);
        $this->sam = User::factory()->create(['name' => 'Sam']);
        app(Friends::class)->link($this->me, $this->sam);
        $this->samSaved = Recipient::create([
            'owner_user_id' => $this->me->id, 'name' => 'Sam', 'user_id' => $this->sam->id, 'status' => RecipientStatus::Linked,
        ]);
    }

    #[Test]
    public function lists_a_friend_makes_for_others_move_out_of_their_lists_into_together(): void
    {
        Wishlist::create([
            'owner_user_id' => $this->sam->id, 'title' => 'Sam wishes', 'market' => Market::BeNl,
            'kind' => ListKind::Mine, 'visible_to_friends' => true,
        ]);
        $grandpa = Recipient::create(['owner_user_id' => $this->sam->id, 'name' => 'Grandpa']);
        $forGrandpa = $this->samsList('For grandpa', ListKind::ForSomeone, $grandpa, ['event_date' => '2026-10-05']);
        $this->actingAs($this->me)->get("/be-nl/l/{$forGrandpa->share_token}")->assertOk();

        $this->actingAs($this->me)->get('/be-nl/people')
            ->assertInertia(fn ($page) => $page
                ->has('people.0.friend.lists', 1)
                ->where('people.0.friend.lists.0.title', 'Sam wishes')
                ->where('people.0.friend.inCommon', 1)
                // Grandpa's party is not Sam's occasion.
                ->where('people.0.next', null));

        $this->actingAs($this->me)->get("/be-nl/people/{$this->samSaved->id}")
            ->assertInertia(fn ($page) => $page
                ->has('profile.theirLists', 1)
                ->has('profile.together.lists', 1)
                ->where('profile.together.lists.0.title', 'For grandpa')
                ->where('profile.together.lists.0.forName', 'Grandpa'));
    }

    #[Test]
    public function a_list_a_friend_is_making_about_you_is_never_shown(): void
    {
        // Sam saved me as a friend, and is making lists about me.
        $me = Recipient::create([
            'owner_user_id' => $this->sam->id, 'name' => 'My surprise person', 'user_id' => $this->me->id, 'status' => RecipientStatus::Linked,
        ]);
        $surprise = $this->samsList('Surprise for Me', ListKind::ForSomeone, $me, ['event_date' => '2026-10-03']);
        $pot = $this->samsList('Pot for Me', ListKind::Group, $me);
        // Even when the link reached me, and even when I got pulled into the pot.
        $this->actingAs($this->me)->get("/be-nl/l/{$surprise->share_token}");
        $this->actingAs($this->me)->get("/be-nl/l/{$pot->share_token}");
        WishlistCollaborator::create(['wishlist_id' => $pot->id, 'user_id' => $this->me->id, 'role' => 'viewer']);

        $overview = $this->actingAs($this->me)->get('/be-nl/people');
        $overview->assertInertia(fn ($page) => $page
            ->where('people.0.friend.lists', [])
            ->where('people.0.friend.inCommon', 0)
            ->where('people.0.next', null));

        $profile = $this->actingAs($this->me)->get("/be-nl/people/{$this->samSaved->id}");
        $profile->assertInertia(fn ($page) => $page
            ->where('profile.theirLists', [])
            ->where('profile.together.lists', [])
            ->where('profile.together.groups', []));

        foreach ([$overview, $profile] as $response) {
            $props = $response->viewData('page')['props'];
            $encoded = json_encode([$props['people'] ?? null, $props['profile'] ?? null]);
            $this->assertStringNotContainsString('Surprise for Me', $encoded);
            $this->assertStringNotContainsString('Pot for Me', $encoded);
            $this->assertStringNotContainsString('My surprise person', $encoded);
        }
    }

    #[Test]
    public function a_friend_s_part_in_a_group_gift_shows_only_where_the_list_would_name_them(): void
    {
        $olga = User::factory()->create(['name' => 'Olga']);
        $leaver = Recipient::create(['owner_user_id' => $olga->id, 'name' => 'Leaver']);
        $quiet = Wishlist::create([
            'owner_user_id' => $olga->id, 'title' => 'Quiet pot', 'market' => Market::BeNl,
            'kind' => ListKind::Group, 'recipient_id' => $leaver->id, 'visibility' => 'link',
        ]);
        $named = Wishlist::create([
            'owner_user_id' => $olga->id, 'title' => 'Named pot', 'market' => Market::BeNl,
            'kind' => ListKind::Group, 'recipient_id' => $leaver->id, 'visibility' => 'link', 'pledgers_visible' => true,
        ]);
        foreach ([$quiet, $named] as $pot) {
            $this->pledge($pot, $this->me);
            $this->pledge($pot, $this->sam);
        }

        // Sam organises one I put into: everybody on it sees the organiser.
        $boss = Recipient::create(['owner_user_id' => $this->sam->id, 'name' => 'Boss']);
        $samsPot = $this->samsList('Sams pot', ListKind::Group, $boss);
        $this->pledge($samsPot, $this->me);

        // I organise one Sam put into: my own page names every contributor to me.
        $aunt = Recipient::create(['owner_user_id' => $this->me->id, 'name' => 'Aunt']);
        $myPot = Wishlist::create([
            'owner_user_id' => $this->me->id, 'title' => 'My pot', 'market' => Market::BeNl,
            'kind' => ListKind::Group, 'recipient_id' => $aunt->id, 'visibility' => 'link',
        ]);
        $this->pledge($myPot, $this->sam);

        $response = $this->actingAs($this->me)->get("/be-nl/people/{$this->samSaved->id}");
        $groups = collect($response->viewData('page')['props']['profile']['together']['groups'])->keyBy('title');

        $this->assertFalse($groups->has('Quiet pot'));
        $this->assertSame('contributes', $groups['Named pot']['role']);
        $this->assertSame('organiser', $groups['Sams pot']['role']);
        $this->assertSame('contributes', $groups['My pot']['role']);
        $this->assertSame('Leaver', $groups['Named pot']['forName']);

        // Never an amount.
        $encoded = json_encode($groups);
        $this->assertStringNotContainsString('2500', $encoded);
        $this->assertStringNotContainsString('amount', $encoded);
    }

    #[Test]
    public function a_secret_santa_you_share_shows_membership_and_never_the_draw(): void
    {
        $group = $this->santa('Office Santa', '2026-12-18');
        $mine = $this->member($group, $this->me);
        $sams = $this->member($group, $this->sam);
        // Somebody without an account is in it too, and cannot be matched.
        $guest = SecretSantaMember::create(['group_id' => $group->id, 'email' => 'guest@example.test', 'display_name' => 'Guest']);
        // I drew Sam. That must not show, not even to me, here.
        $mine->update(['assigned_member_id' => (string) $sams->id]);
        $sams->update(['assigned_member_id' => (string) $guest->id]);

        // Last year's group: on their page, not as the mark on My people.
        $this->member($old = $this->santa('Santa 2025', '2025-12-19'), $this->me);
        $this->member($old, $this->sam);
        // A group Sam left does not count.
        $left = $this->santa('Left group', '2026-12-01');
        $this->member($left, $this->me);
        SecretSantaMember::create([
            'group_id' => $left->id, 'user_id' => $this->sam->id, 'email' => 's@example.test', 'display_name' => 'Sam', 'removed_at' => now(),
        ]);

        $overview = $this->actingAs($this->me)->get('/be-nl/people');
        $overview->assertInertia(fn ($page) => $page
            ->where('people.0.friend.santa', ['title' => 'Office Santa', 'date' => '2026-12-18'])
            ->where('people.0.friend.inCommon', 2));

        $profile = $this->actingAs($this->me)->get("/be-nl/people/{$this->samSaved->id}");
        $profile->assertInertia(fn ($page) => $page
            ->has('profile.together.santa', 2)
            ->where('profile.together.santa.0.title', 'Office Santa')
            ->where('profile.together.santa.0.url', "/be-nl/santa/{$group->id}")
            ->where('profile.together.santa.1.title', 'Santa 2025'));

        foreach ([$overview, $profile] as $response) {
            $props = $response->viewData('page')['props'];
            $encoded = json_encode([$props['people'] ?? null, $props['profile'] ?? null]);
            foreach (['assigned', 'drew', 'giftee', 'join_token'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $encoded);
            }
        }
    }

    #[Test]
    public function a_friend_nobody_saved_gets_the_secret_santa_mark_too(): void
    {
        $this->samSaved->delete();
        $group = $this->santa('Family draw', '2026-12-24');
        $this->member($group, $this->me);
        $this->member($group, $this->sam);

        $this->actingAs($this->me)->get('/be-nl/people')
            ->assertInertia(fn ($page) => $page
                ->where('people.0.personId', null)
                ->where('people.0.friend.santa.title', 'Family draw'));
    }

    #[Test]
    public function somebody_without_an_account_has_no_together_section(): void
    {
        $mum = Recipient::create(['owner_user_id' => $this->me->id, 'name' => 'Mum']);

        $this->actingAs($this->me)->get("/be-nl/people/{$mum->id}")
            ->assertInertia(fn ($page) => $page->where('profile.together', null));
    }

    /** @param array<string, mixed> $attributes */
    private function samsList(string $title, ListKind $kind, Recipient $for, array $attributes = []): Wishlist
    {
        return Wishlist::create([
            'owner_user_id' => $this->sam->id, 'title' => $title, 'market' => Market::BeNl,
            'kind' => $kind, 'recipient_id' => $for->id, 'visibility' => 'link', ...$attributes,
        ]);
    }

    private function pledge(Wishlist $list, User $user): void
    {
        GiftPledge::create(['wishlist_id' => $list->id, 'user_id' => $user->id, 'display_name' => $user->name, 'amount' => 2500]);
    }

    private function santa(string $title, string $date): SecretSantaGroup
    {
        return SecretSantaGroup::create([
            'owner_user_id' => User::factory()->create()->id, 'market' => Market::BeNl, 'title' => $title, 'exchange_date' => $date,
        ]);
    }

    private function member(SecretSantaGroup $group, User $user): SecretSantaMember
    {
        return SecretSantaMember::create([
            'group_id' => $group->id, 'user_id' => $user->id, 'email' => $user->email, 'display_name' => $user->name,
        ]);
    }
}

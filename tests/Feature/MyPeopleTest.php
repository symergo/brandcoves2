<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\Market;
use App\Enums\RecipientStatus;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Social\Friends;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "My people" at /people: saved people and friends on one list.
 *
 * Holds the merge (a friend linked to a saved person is one row), the order
 * (nearest date, then name), the two ways to add somebody from the page, the
 * old /friends address, and what the page must never show: somebody else's
 * saved people, or anything about claims. See docs/features/my-people.md.
 */
class MyPeopleTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-01 10:00:00');
        $this->me = User::factory()->create(['name' => 'Me']);
    }

    #[Test]
    public function saved_people_are_listed_with_their_next_birthday(): void
    {
        $mum = $this->saved('Mum', ['relationship' => 'mother', 'birthday' => '2000-06-10']);

        $this->actingAs($this->me)->get('/be-nl/people')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('People/Index')
                ->where('isSignedIn', true)
                ->has('people', 1)
                ->where('people.0.name', 'Mum')
                // The closed vocabulary in the reader's language.
                ->where('people.0.relationship', 'Mama')
                ->where('people.0.personId', $mum->id)
                ->where('people.0.friend', null)
                ->where('people.0.next.kind', 'birthday')
                ->where('people.0.next.date', '2026-06-10')
                ->where('people.0.next.days', 9)
                ->where('people.0.urls.person', fn ($url) => str_ends_with($url, "/be-nl/people/{$mum->id}"))
                // The wizard with her chosen, every way open, swiping included (2026-10-05).
                ->where('people.0.urls.finder', fn ($url) => str_ends_with($url, "/be-nl/gift?person={$mum->id}"))
                // "Vraag": Ask others, filled in about them (2026-09-27).
                ->where('people.0.urls.ask', fn ($url) => str_ends_with($url, "/be-nl/ask?person={$mum->id}")));
    }

    #[Test]
    public function a_row_says_what_you_know_and_how_many_lists_you_are_making(): void
    {
        $mum = $this->saved('Mum', [
            'interests' => ['cooking', 'gardening', 'wielrennen'],
        ]);
        foreach (['Kerst', 'Verjaardag'] as $title) {
            Wishlist::create([
                'owner_user_id' => $this->me->id, 'title' => $title, 'market' => Market::BeNl,
                'kind' => ListKind::ForSomeone, 'recipient_id' => $mum->id,
            ]);
        }
        // A list of yours that is not about her does not count.
        Wishlist::create(['owner_user_id' => $this->me->id, 'title' => 'Mine', 'market' => Market::BeNl, 'kind' => ListKind::Mine]);

        $this->actingAs($this->me)->get('/be-nl/people')
            ->assertInertia(fn ($page) => $page
                // The closed vocabulary in the reader's language; typed words as typed.
                ->where('people.0.known.interests', ['Koken', 'Tuinieren', 'wielrennen'])
                // No budget: it is a list's, not a person's (2026-10-05).
                ->missing('people.0.known.budgetMax')
                ->where('people.0.listsForThem', 2));
    }

    #[Test]
    public function a_friend_nobody_saved_is_listed_on_their_own(): void
    {
        $sam = User::factory()->create(['name' => 'Sam', 'birthday' => '1990-07-04', 'friends_see_birthday' => true]);
        app(Friends::class)->link($this->me, $sam);

        $this->actingAs($this->me)->get('/be-nl/people')
            ->assertInertia(fn ($page) => $page
                ->has('people', 1)
                ->where('people.0.name', 'Sam')
                ->where('people.0.personId', null)
                ->where('people.0.friend.id', $sam->id)
                // Day and month only: their year is their age.
                ->where('people.0.birthday', '07-04')
                ->where('people.0.next.date', '2026-07-04')
                // No person to find a gift for until you save one.
                ->where('people.0.urls.finder', null)
                ->where('people.0.urls.ask', null));
    }

    #[Test]
    public function a_friend_linked_to_a_saved_person_appears_once(): void
    {
        $sam = User::factory()->create(['name' => 'Sam Account']);
        app(Friends::class)->link($this->me, $sam);
        $saved = $this->saved('Sammy', ['user_id' => $sam->id, 'status' => RecipientStatus::Linked]);

        $this->actingAs($this->me)->get('/be-nl/people')
            ->assertInertia(fn ($page) => $page
                ->has('people', 1)
                // Your own name for them, and both halves on one row.
                ->where('people.0.name', 'Sammy')
                ->where('people.0.personId', $saved->id)
                ->where('people.0.friend.id', $sam->id));
    }

    #[Test]
    public function the_nearest_date_comes_first_then_the_name(): void
    {
        $this->saved('Zoë', ['birthday' => '2000-06-03']);
        $this->saved('bert');
        $this->saved('Anna');
        $grandma = $this->saved('Grandma', ['birthday' => '2000-12-24']);
        // A dated list about her is sooner than her birthday.
        Wishlist::create([
            'owner_user_id' => $this->me->id,
            'title' => 'Garden party',
            'market' => Market::BeNl,
            'kind' => ListKind::ForSomeone,
            'recipient_id' => $grandma->id,
            'event_date' => '2026-06-02',
        ]);
        // Yesterday's birthday is next year's, not a date in the past.
        $this->saved('Carl', ['birthday' => '2000-05-31']);

        $this->actingAs($this->me)->get('/be-nl/people')
            ->assertInertia(fn ($page) => $page
                ->where('people', fn ($people) => collect($people)->pluck('name')->all() === ['Grandma', 'Zoë', 'Carl', 'Anna', 'bert'])
                ->where('people.0.next.kind', 'occasion')
                ->where('people.0.next.title', 'Garden party')
                ->where('people.0.next.days', 1)
                ->where('people.2.next.date', '2027-05-31'));
    }

    #[Test]
    public function you_do_not_appear_among_your_own_people(): void
    {
        $this->saved('Me', ['status' => RecipientStatus::Self]);

        $this->actingAs($this->me)->get('/be-nl/people')
            ->assertInertia(fn ($page) => $page->has('people', 0));
    }

    #[Test]
    public function somebody_is_added_from_the_page(): void
    {
        $this->actingAs($this->me)
            ->from('/be-nl/people')
            ->post('/be-nl/recipients', ['name' => 'Oma', 'relationship' => 'grandparent', 'birthday' => '2000-02-29'])
            ->assertRedirect('/be-nl/people');

        $this->actingAs($this->me)->get('/be-nl/people')
            ->assertInertia(fn ($page) => $page
                ->has('people', 1)
                ->where('people.0.name', 'Oma')
                ->where('people.0.relationship', 'Oma of opa')
                // A leap-day birthday in a year without one: the 28th.
                ->where('people.0.next.date', '2027-02-28'));
    }

    #[Test]
    public function saving_what_you_know_about_a_friend_links_the_two(): void
    {
        $sam = User::factory()->create(['name' => 'Sam']);
        app(Friends::class)->link($this->me, $sam);

        $this->actingAs($this->me)
            ->from('/be-nl/people')
            ->post('/be-nl/recipients', ['name' => 'Sam', 'friend_id' => $sam->id])
            ->assertRedirect('/be-nl/people');

        $saved = Recipient::query()->where('owner_user_id', $this->me->id)->sole();
        $this->assertSame($sam->id, $saved->user_id);

        $this->actingAs($this->me)->get('/be-nl/people')
            ->assertInertia(fn ($page) => $page
                ->has('people', 1)
                ->where('people.0.personId', $saved->id)
                ->where('people.0.friend.id', $sam->id));
    }

    #[Test]
    public function inviting_somebody_with_an_account_puts_them_on_the_page(): void
    {
        /*
         * There is no request to accept: an address with an account is
         * connected at once, one without is connected when that person signs
         * in (App\Services\Social\FriendInvites). The page answers the same
         * either way; the row is what appears.
         */
        User::factory()->create(['name' => 'Kim', 'email' => 'kim@example.com']);

        $this->actingAs($this->me)
            ->from('/be-nl/people')
            ->post('/be-nl/friends', ['email' => 'kim@example.com', 'birthday' => '06-05'])
            ->assertRedirect('/be-nl/people');

        $this->actingAs($this->me)->get('/be-nl/people')
            ->assertInertia(fn ($page) => $page
                ->where('people.0.name', 'Kim')
                ->where('people.0.friend.birthdayIsMine', true)
                ->where('people.0.next.days', 4));
    }

    #[Test]
    public function the_old_friends_page_redirects_here(): void
    {
        $this->actingAs($this->me)->get('/be-nl/friends')->assertRedirect('/be-nl/people');
        $this->actingAs($this->me)->get('/be-fr/friends')->assertRedirect('/be-fr/people');
    }

    #[Test]
    public function a_guest_gets_the_explanation_and_nothing_else(): void
    {
        $this->saved('Mum');

        $this->get('/be-nl/people')
            ->assertOk()
            ->assertSee('noindex', false)
            ->assertInertia(fn ($page) => $page
                ->where('isSignedIn', false)
                ->where('people', [])
                ->where('settings', null));
    }

    #[Test]
    public function nobody_elses_saved_people_appear(): void
    {
        $other = User::factory()->create();
        Recipient::create(['owner_user_id' => $other->id, 'name' => 'Their secret person']);

        // Not even a friend's: what somebody saved about a person is theirs.
        app(Friends::class)->link($this->me, $other);
        Recipient::create(['owner_user_id' => $other->id, 'name' => 'Friend saved this', 'user_id' => $this->me->id]);

        $response = $this->actingAs($this->me)->get('/be-nl/people');
        $encoded = json_encode($response->viewData('page')['props']['people']);

        $this->assertStringNotContainsString('Their secret person', $encoded);
        $this->assertStringNotContainsString('Friend saved this', $encoded);
    }

    #[Test]
    public function the_page_carries_no_claim_state_and_only_lists_that_were_shared(): void
    {
        $friend = User::factory()->create(['name' => 'Anna']);
        app(Friends::class)->link($this->me, $friend);

        $shared = Wishlist::create([
            'owner_user_id' => $friend->id, 'title' => 'Anna wishes', 'market' => Market::BeNl,
            'kind' => ListKind::Mine, 'visibility' => 'link',
        ]);
        $this->actingAs($this->me)->get("/be-nl/l/{$shared->share_token}")->assertOk();
        WishlistItem::factory()->create([
            'wishlist_id' => $shared->id, 'claimed_by_hash' => 'someone', 'claimed_at' => now(),
        ]);

        // Never opened, never shared with me.
        Wishlist::create([
            'owner_user_id' => $friend->id, 'title' => 'Anna secret', 'market' => Market::BeNl,
            'kind' => ListKind::Mine, 'visibility' => 'link',
        ]);

        $props = $this->actingAs($this->me)->get('/be-nl/people')->viewData('page')['props'];
        $encoded = json_encode($props['people']);

        $this->assertStringContainsString('Anna wishes', $encoded);
        $this->assertStringNotContainsString('Anna secret', $encoded);

        foreach (['claim', 'progress', 'bought', 'taken'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function saved(string $name, array $attributes = []): Recipient
    {
        return Recipient::create(['owner_user_id' => $this->me->id, 'name' => $name, ...$attributes]);
    }
}

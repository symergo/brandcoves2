<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\Market;
use App\Enums\RecipientStatus;
use App\Enums\TasteSource;
use App\Models\Recipient;
use App\Models\RecipientGift;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Social\Friends;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A person's page as a profile (/people/{id}, 2026-09-27): what you know,
 * their wish lists when they are a friend, the lists you make for them,
 * editing, and deleting. See docs/features/my-people.md.
 */
class PersonProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = User::factory()->create(['name' => 'Me']);
    }

    #[Test]
    public function the_page_is_about_the_person_and_says_what_you_know(): void
    {
        $mum = $this->saved('Mum', [
            'relationship' => 'mother',
            'birthday' => '2000-06-10',
            'interests' => ['cooking', 'wielrennen'],
            'vibe' => 'practical',
            'values' => ['local'],
            'age_band' => '50-64',
            'avoid' => ['parfum'],
            'budget_max' => 5000,
            'taste_source' => TasteSource::Suggested,
        ]);
        $list = $this->listFor($mum, 'Kerst', ListKind::ForSomeone);

        $this->actingAs($this->me)->get("/be-nl/people/{$mum->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Recipients/Show')
                ->where('person.name', 'Mum')
                ->where('profile.relationshipLabel', 'Mama')
                ->where('profile.birthday', '06-10')
                ->where('profile.isFriend', false)
                ->where('profile.about.interests', [
                    ['value' => 'cooking', 'label' => 'Koken'],
                    ['value' => 'wielrennen', 'label' => 'wielrennen'],
                ])
                ->where('profile.about.vibe', 'practical')
                ->where('profile.about.values', ['local'])
                ->where('profile.about.ageBand', '50-64')
                ->where('profile.about.avoid', ['parfum'])
                ->where('profile.about.budgetMax', 5000)
                ->where('profile.about.tasteSource', 'suggested')
                ->where('profile.theirLists', [])
                ->has('profile.listsForThem', 1)
                ->where('profile.listsForThem.0.id', $list->id)
                ->where('profile.listsForThem.0.kind', 'for_someone')
                // Their own link, since they have no account behind them.
                ->where('urls.selfDescribe', fn ($url) => str_ends_with($url, "/be-nl/for/{$mum->share_token}"))
                ->where('groupLists', 0)
                // Find a gift's own vocabularies, to edit in place with.
                ->has('options.interests')
                ->has('options.vibes')
                ->has('options.ages')
                // Every list the "Over" form draws: a missing one crashed the
                // page on "Aanpassen" (found 2026-09-27).
                ->where('options.values', ['sustainable', 'local', 'handmade'])
                ->has('options.relationships'));
    }

    #[Test]
    public function a_friend_s_own_wish_lists_show_and_nothing_they_make_for_others(): void
    {
        $sam = User::factory()->create(['name' => 'Sam']);
        app(Friends::class)->link($this->me, $sam);
        $saved = $this->saved('Sammy', ['user_id' => $sam->id, 'status' => RecipientStatus::Linked]);

        Wishlist::create([
            'owner_user_id' => $sam->id, 'title' => 'Sam wishes', 'market' => Market::BeNl,
            'kind' => ListKind::Mine, 'visible_to_friends' => true,
        ]);
        // A list Sam makes for somebody else, even one shown to me by link,
        // is not a wish list of Sam's.
        $theirs = Recipient::create(['owner_user_id' => $sam->id, 'name' => 'Grandpa']);
        $forGrandpa = Wishlist::create([
            'owner_user_id' => $sam->id, 'title' => 'For grandpa', 'market' => Market::BeNl,
            'kind' => ListKind::ForSomeone, 'recipient_id' => $theirs->id, 'visibility' => 'link',
        ]);
        $this->actingAs($this->me)->get("/be-nl/l/{$forGrandpa->share_token}");
        // Private and never shared: never here.
        Wishlist::create([
            'owner_user_id' => $sam->id, 'title' => 'Sam secret', 'market' => Market::BeNl, 'kind' => ListKind::Mine,
        ]);

        $this->actingAs($this->me)->get("/be-nl/people/{$saved->id}")
            ->assertInertia(fn ($page) => $page
                ->where('profile.isFriend', true)
                ->has('profile.theirLists', 1)
                ->where('profile.theirLists.0.title', 'Sam wishes')
                // Linked to an account: no profile link to send.
                ->where('urls.selfDescribe', null));

        // Removing the friend takes their lists off the page at once.
        app(Friends::class)->unlink($this->me, $sam->id);

        $this->actingAs($this->me)->get("/be-nl/people/{$saved->id}")
            ->assertInertia(fn ($page) => $page
                ->where('profile.isFriend', false)
                ->where('profile.theirLists', []));
    }

    #[Test]
    public function what_you_know_is_saved_from_the_page(): void
    {
        $mum = $this->saved('Mum');

        $this->actingAs($this->me)
            ->from("/be-nl/people/{$mum->id}")
            ->patch("/be-nl/recipients/{$mum->id}", [
                'interests' => ['cooking', 'wielrennen'],
                'vibe' => 'practical',
                'values' => ['local'],
                'avoid' => ['parfum'],
                'age_band' => '50-64',
                'budget_min' => 20,
                'budget_max' => 50,
            ])
            ->assertRedirect("/be-nl/people/{$mum->id}");

        $mum->refresh();
        $this->assertSame(['cooking', 'wielrennen'], $mum->interests);
        $this->assertSame('practical', $mum->vibe);
        $this->assertSame(2000, (int) $mum->budget_min);
        $this->assertSame(5000, (int) $mum->budget_max);
        $this->assertSame(TasteSource::Suggested, $mum->taste_source);
    }

    #[Test]
    public function taste_they_gave_themselves_is_not_overwritten_but_budget_is_yours(): void
    {
        $mum = $this->saved('Mum', ['interests' => ['gardening'], 'taste_source' => TasteSource::Self]);

        $this->actingAs($this->me)->patch("/be-nl/recipients/{$mum->id}", [
            'interests' => ['cooking'],
            'budget_max' => 30,
        ]);

        $mum->refresh();
        $this->assertSame(['gardening'], $mum->interests);
        $this->assertSame(3000, (int) $mum->budget_max);
    }

    #[Test]
    public function the_person_is_renamed_and_their_birthday_changed(): void
    {
        $mum = $this->saved('Mum');

        $this->actingAs($this->me)->patch("/be-nl/recipients/{$mum->id}", [
            'name' => 'Mama',
            'relationship' => 'mother',
            'birthday' => '2000-02-29',
        ]);

        $mum->refresh();
        $this->assertSame('Mama', $mum->name);
        $this->assertSame('02-29', $mum->birthday->format('m-d'));
    }

    #[Test]
    public function deleting_from_the_page_lands_on_my_people_and_keeps_the_lists(): void
    {
        $mum = $this->saved('Mum');
        $list = $this->listFor($mum, 'Kerst', ListKind::ForSomeone);
        RecipientGift::query()->create(['recipient_id' => $mum->id, 'title' => 'Moka pot', 'given_year' => 2025]);

        $this->actingAs($this->me)
            ->from("/be-nl/people/{$mum->id}")
            ->delete("/be-nl/recipients/{$mum->id}", ['then' => 'people'])
            ->assertRedirect('/be-nl/people');

        $this->assertModelMissing($mum);
        // The list survives and no longer says who it is for; the history goes.
        $this->assertNull($list->fresh()->recipient_id);
        $this->assertSame(0, RecipientGift::query()->count());
    }

    #[Test]
    public function a_person_with_a_group_gift_is_not_deleted_and_the_page_says_why(): void
    {
        $mum = $this->saved('Mum');
        $this->listFor($mum, 'Samen voor mama', ListKind::Group);

        $this->actingAs($this->me)->get("/be-nl/people/{$mum->id}")
            ->assertInertia(fn ($page) => $page->where('groupLists', 1));

        // Postgres would refuse it (a group list must have a recipient); the
        // visitor gets a sentence, not a server error.
        $this->actingAs($this->me)
            ->from("/be-nl/people/{$mum->id}")
            ->delete("/be-nl/recipients/{$mum->id}", ['then' => 'people'])
            ->assertRedirect("/be-nl/people/{$mum->id}")
            ->assertSessionHasErrors('person');

        $this->assertModelExists($mum);
    }

    #[Test]
    public function somebody_else_s_person_is_a_404(): void
    {
        $other = User::factory()->create();
        $theirs = Recipient::create(['owner_user_id' => $other->id, 'name' => 'Theirs']);

        $this->actingAs($this->me)->get("/be-nl/people/{$theirs->id}")->assertNotFound();
        $this->actingAs($this->me)->patch("/be-nl/recipients/{$theirs->id}", ['name' => 'X'])->assertNotFound();
        $this->actingAs($this->me)->post("/be-nl/people/{$theirs->id}/share-list")->assertNotFound();
        $this->assertSame(0, Wishlist::query()->where('recipient_id', $theirs->id)->count());
    }

    #[Test]
    public function the_search_card_gets_the_list_for_a_person_and_makes_it_on_first_use(): void
    {
        // Find a gift's "Weet je al wat je zoekt?" (2026-09-27).
        $mum = Recipient::create(['owner_user_id' => $this->me->id, 'name' => 'Mum']);

        $first = $this->actingAs($this->me)->postJson("/be-nl/people/{$mum->id}/list")->assertOk();
        $list = Wishlist::query()->where('recipient_id', $mum->id)->sole();
        $first->assertJson(['id' => $list->id]);

        $this->actingAs($this->me)->postJson("/be-nl/people/{$mum->id}/list")->assertJson(['id' => $list->id]);
        $this->assertSame(1, Wishlist::query()->where('recipient_id', $mum->id)->count());

        $theirs = Recipient::create(['owner_user_id' => User::factory()->create()->id, 'name' => 'Theirs']);
        $this->actingAs($this->me)->postJson("/be-nl/people/{$theirs->id}/list")->assertNotFound();
    }

    #[Test]
    public function sharing_the_list_for_a_person_opens_it_on_share_and_makes_one_only_when_needed(): void
    {
        // "Deel de lijst voor … en laat anderen iets voorstellen" (2026-09-27).
        $mum = Recipient::create(['owner_user_id' => $this->me->id, 'name' => 'Mum']);

        $first = $this->actingAs($this->me)->post("/be-nl/people/{$mum->id}/share-list");
        $list = Wishlist::query()->where('recipient_id', $mum->id)->sole();
        $first->assertRedirect("/be-nl/lists/{$list->id}?panel=share");

        // Nothing is made public by it: sharing stays the owner's press.
        $this->assertSame('private', $list->visibility->value);

        // A second time finds the same list rather than making another.
        $this->actingAs($this->me)->post("/be-nl/people/{$mum->id}/share-list")
            ->assertRedirect("/be-nl/lists/{$list->id}?panel=share");
        $this->assertSame(1, Wishlist::query()->where('recipient_id', $mum->id)->count());
    }

    #[Test]
    public function the_profile_carries_no_claim_state(): void
    {
        $mum = $this->saved('Mum');
        $list = $this->listFor($mum, 'Kerst', ListKind::ForSomeone);
        WishlistItem::factory()->create(['wishlist_id' => $list->id, 'claimed_by_hash' => 'someone', 'claimed_at' => now()]);

        $props = $this->actingAs($this->me)->get("/be-nl/people/{$mum->id}")->viewData('page')['props'];
        $encoded = json_encode($props['profile']);

        foreach (['claim', 'progress', 'bought', 'taken'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function saved(string $name, array $attributes = []): Recipient
    {
        return Recipient::create(['owner_user_id' => $this->me->id, 'name' => $name, ...$attributes]);
    }

    private function listFor(Recipient $person, string $title, ListKind $kind): Wishlist
    {
        return Wishlist::create([
            'owner_user_id' => $this->me->id, 'title' => $title, 'market' => Market::BeNl,
            'kind' => $kind, 'recipient_id' => $person->id,
        ]);
    }
}

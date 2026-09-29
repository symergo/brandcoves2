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
            // Old data: vibe and values were removed site-wide (2026-09-29),
            // and what a row still holds of them is not shown.
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
                ->missing('profile.about.vibe')
                ->missing('profile.about.values')
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
                // Every list the "Over" form draws: a missing one crashed the
                // page on "Aanpassen" (found 2026-09-27).
                ->has('options.interests')
                ->has('options.ages')
                ->has('options.relationships')
                ->missing('options.vibes')
                ->missing('options.values'));
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
                // For "Remove as friend" in the page's ⋯.
                ->where('profile.friendId', $sam->id)
                ->has('profile.theirLists', 1)
                ->where('profile.theirLists.0.title', 'Sam wishes')
                // Linked to an account: no profile link to send.
                ->where('urls.selfDescribe', null));

        // Removing the friend takes their lists off the page at once.
        app(Friends::class)->unlink($this->me, $sam->id);

        $this->actingAs($this->me)->get("/be-nl/people/{$saved->id}")
            ->assertInertia(fn ($page) => $page
                ->where('profile.isFriend', false)
                ->where('profile.friendId', null)
                ->where('profile.theirLists', []));
    }

    #[Test]
    public function your_lists_for_them_are_the_rows_of_my_coves_and_delete_back_to_the_page(): void
    {
        /*
         * Since 2026-09-27 the person's page draws your lists for them with
         * Mijn Coves' own row (ListSummaryRow): pictures, count, the share
         * popup's switches and the same ⋯, including Delete, which returns to
         * this page rather than to Mijn Coves (`stay`).
         */
        $mum = $this->saved('Mum');
        $list = $this->listFor($mum, 'Kerst', ListKind::Group);
        WishlistItem::factory()->create(['wishlist_id' => $list->id, 'snapshot_image_url' => 'https://example.com/teapot.jpg']);

        $this->actingAs($this->me)->get("/be-nl/people/{$mum->id}")
            ->assertInertia(fn ($page) => $page
                ->where('profile.listsForThem.0.itemCount', 1)
                ->where('profile.listsForThem.0.covers', ['https://example.com/teapot.jpg'])
                ->where('profile.listsForThem.0.recipient.name', 'Mum')
                ->where('profile.listsForThem.0.sharedWithMe', false)
                ->where('profile.listsForThem.0.shareUrl', null)
                ->where('profile.listsForThem.0.visibleToFriends', null)
                ->where('profile.listsForThem.0.votingEnabled', true)
                ->where('profile.listsForThem.0.suggestions', 0));

        $this->actingAs($this->me)
            ->from("/be-nl/people/{$mum->id}")
            ->delete("/be-nl/lists/{$list->id}", ['stay' => true])
            ->assertRedirect("/be-nl/people/{$mum->id}");

        $this->assertModelMissing($list);

        // From the list page itself, still the overview.
        $other = $this->listFor($mum, 'Verjaardag', ListKind::ForSomeone);

        $this->actingAs($this->me)
            ->from("/be-nl/lists/{$other->id}")
            ->delete("/be-nl/lists/{$other->id}")
            ->assertRedirect('/be-nl/lists');
    }

    #[Test]
    public function what_you_know_is_saved_from_the_page(): void
    {
        $mum = $this->saved('Mum');

        $this->actingAs($this->me)
            ->from("/be-nl/people/{$mum->id}")
            ->patch("/be-nl/recipients/{$mum->id}", [
                'interests' => ['cooking', 'wielrennen'],
                // No longer a question: sent by an old page, it is ignored.
                'vibe' => 'practical',
                'avoid' => ['parfum'],
                'age_band' => '50-64',
                'budget_min' => 20,
                'budget_max' => 50,
            ])
            ->assertRedirect("/be-nl/people/{$mum->id}");

        $mum->refresh();
        $this->assertSame(['cooking', 'wielrennen'], $mum->interests);
        $this->assertNull($mum->vibe);
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
    public function a_relationship_gets_one_saved_person_and_one_list_on_the_search_card(): void
    {
        // "There is a person picked, either a friend or a relationship" (owner, 2026-09-27).
        $first = $this->actingAs($this->me)
            ->postJson('/be-nl/people/for-relationship/list', ['relationship' => 'colleague'])
            ->assertOk();

        $person = Recipient::query()->where('owner_user_id', $this->me->id)->sole();
        $this->assertSame('colleague', $person->relationship);
        $this->assertSame(__('site.gift.relationships.colleague'), $person->name);
        $list = Wishlist::query()->where('recipient_id', $person->id)->sole();
        $first->assertJson(['id' => $list->id, 'personId' => $person->id]);

        // Pressed again: the same person and the same list, not a second "Collega".
        $this->actingAs($this->me)
            ->postJson('/be-nl/people/for-relationship/list', ['relationship' => 'colleague'])
            ->assertJson(['id' => $list->id]);
        $this->assertSame(1, Recipient::query()->where('owner_user_id', $this->me->id)->count());

        $this->actingAs($this->me)
            ->postJson('/be-nl/people/for-relationship/list', ['relationship' => 'not-a-kind'])
            ->assertUnprocessable();
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

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Enums\RecipientStatus;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\User;
use App\Models\UserTaste;
use App\Services\Ai\AiClient;
use App\Services\Gift\TasteBrief;
use App\Services\Social\Friends;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Mijn smaak": your own gift taste, which friends' searches start from
 * (owner, 2026-09-29). See docs/features/my-taste.md.
 */
class MyTasteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Invariant 1: nothing on this path may reach the model.
        $this->mock(AiClient::class, function ($mock): void {
            $mock->shouldNotReceive('json');
            $mock->shouldNotReceive('chat');
        });
    }

    #[Test]
    public function the_page_is_for_a_signed_in_person(): void
    {
        $this->get('/be-nl/my-taste')->assertRedirect();

        $this->actingAs(User::factory()->create())
            ->get('/be-nl/my-taste')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('MyTaste')
                ->where('taste.interests', [])
                ->has('options.interests')
                ->has('options.preferences')
                // Vibe and values were removed site-wide (2026-09-29); the pairs carry the feel.
                ->missing('options.values')
                ->missing('options.vibes'));
    }

    #[Test]
    public function saving_keeps_a_taste_and_never_a_budget(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/be-nl/my-taste', [
            'interests' => ['cooking', 'zuurdesem'],
            'preferences' => ['vintage'],
            // No longer questions: sent by an old page, they are ignored.
            'vibe' => 'beautiful',
            'values' => ['local'],
            'avoid' => ['wol'],
            'age_band' => '30-49',
            // Not a field: what to spend is the giver's (owner, 2026-09-29).
            'budget_max' => 50,
        ])->assertRedirect('/be-nl/my-taste');

        $taste = UserTaste::query()->findOrFail($user->id);
        $this->assertSame(['cooking', 'zuurdesem'], $taste->interests);
        $this->assertSame(['vintage'], $taste->preferences);
        $this->assertNull($taste->vibe);
        $this->assertSame(['wol'], $taste->avoid);
        $this->assertFalse(Schema::hasColumn('user_tastes', 'budget_max'));
    }

    #[Test]
    public function the_page_saving_on_every_click_gets_a_quiet_answer(): void
    {
        // Owner, 2026-09-30: save automatically. The page asks for JSON, so no
        // banner flashes on every click.
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/be-nl/my-taste', ['interests' => ['music'], 'preferences' => ['modern']])
            ->assertOk()
            ->assertExactJson(['saved' => true, 'cleared' => false])
            ->assertSessionMissing('success');

        $this->assertSame(['modern'], UserTaste::query()->findOrFail($user->id)->preferences);

        $this->actingAs($user)->putJson('/be-nl/my-taste', [])->assertExactJson(['saved' => true, 'cleared' => true]);
        $this->assertNull(UserTaste::query()->find($user->id));
    }

    #[Test]
    public function clearing_everything_leaves_no_row(): void
    {
        $user = User::factory()->create();
        UserTaste::query()->create(['user_id' => $user->id, 'interests' => ['music']]);

        $this->actingAs($user)->put('/be-nl/my-taste', ['interests' => []])->assertRedirect();

        $this->assertNull(UserTaste::query()->find($user->id));
    }

    #[Test]
    public function a_value_outside_the_vocabulary_is_refused(): void
    {
        $this->actingAs(User::factory()->create())
            ->put('/be-nl/my-taste', ['preferences' => ['loud']])
            ->assertSessionHasErrors('preferences.0');
    }

    #[Test]
    public function a_friends_search_starts_from_their_own_taste(): void
    {
        [$giver, $friend, $saved] = $this->friendSavedBy(friends: true);
        UserTaste::query()->create(['user_id' => $friend->id, 'interests' => ['music'], 'avoid' => ['wol'], 'preferences' => ['vintage']]);

        $brief = TasteBrief::fromRecipient($saved->fresh(), Market::BeNl);

        // Their word over the giver's; both avoid lists; the budget stays the giver's.
        $this->assertSame(['music'], $brief->interests);
        $this->assertSame(['vintage'], $brief->preferences);
        $this->assertEqualsCanonicalizing(['parfum', 'wol'], $brief->avoid);
        $this->assertSame(5000, $brief->budgetMax);
    }

    #[Test]
    public function a_part_they_left_empty_keeps_the_givers(): void
    {
        [, $friend, $saved] = $this->friendSavedBy(friends: true);
        UserTaste::query()->create(['user_id' => $friend->id, 'preferences' => ['vintage']]);

        $this->assertSame(['cooking'], TasteBrief::fromRecipient($saved->fresh(), Market::BeNl)->interests);
    }

    #[Test]
    public function without_a_friendship_their_taste_is_not_read(): void
    {
        [, $friend, $saved] = $this->friendSavedBy(friends: false);
        UserTaste::query()->create(['user_id' => $friend->id, 'interests' => ['music']]);

        $this->assertSame(['cooking'], TasteBrief::fromRecipient($saved->fresh(), Market::BeNl)->interests);
    }

    #[Test]
    public function find_a_gift_shows_a_friends_own_taste_on_their_card(): void
    {
        [$giver, $friend, $saved] = $this->friendSavedBy(friends: true);
        UserTaste::query()->create(['user_id' => $friend->id, 'interests' => ['music']]);

        $this->actingAs($giver)
            ->get('/be-nl/gift')
            ->assertInertia(fn ($page) => $page
                ->where('recipients.0.id', $saved->id)
                ->where('recipients.0.interests', ['music'])
                ->where('recipients.0.ownTaste', true)
                ->where('recipients.0.budgetMax', 5000));
    }

    #[Test]
    public function voor_mezelf_gets_your_own_taste(): void
    {
        $user = User::factory()->create();
        UserTaste::query()->create(['user_id' => $user->id, 'interests' => ['reading'], 'age_band' => '30-49']);

        $this->actingAs($user)
            ->get('/be-nl/gift')
            ->assertInertia(fn ($page) => $page
                ->where('myTaste.interests', ['reading'])
                ->where('myTaste.ageBand', '30-49'));

        $this->actingAs(User::factory()->create())
            ->get('/be-nl/gift')
            ->assertInertia(fn ($page) => $page->where('myTaste', null));
    }

    #[Test]
    public function this_or_that_for_yourself_can_be_kept_as_your_taste(): void
    {
        $user = User::factory()->create();
        $choices = [];

        foreach (['gaming', 'music', 'reading'] as $other) {
            $cooking = $this->product('cooking');
            $choices[] = ['shown' => [$cooking->id, $this->product($other)->id], 'picked' => $cooking->id];
        }

        $this->actingAs($user)
            ->postJson('/be-nl/my-taste/learn', ['choices' => $choices])
            ->assertOk()
            ->assertJson(['url' => '/be-nl/my-taste']);

        $this->assertContains('cooking', UserTaste::query()->findOrFail($user->id)->interests);
    }

    #[Test]
    public function this_or_that_offers_keeping_only_when_signed_in(): void
    {
        $this->get('/be-nl/gift/taste')->assertInertia(fn ($page) => $page->where('urls.mine', null));

        $this->actingAs(User::factory()->create())
            ->get('/be-nl/gift/taste')
            ->assertInertia(fn ($page) => $page->where('urls.mine', '/be-nl/my-taste/learn'));
    }

    #[Test]
    public function swipes_for_yourself_teach_interests_and_a_taste_pole(): void
    {
        /*
         * "Include results from swiping and vibe" (owner, 2026-09-29): Swipe
         * gifts sends each swipe as a one-card choice. The vibe itself was
         * removed the same day; the feel is now learned as a preference pole.
         */
        $user = User::factory()->create();
        $swipes = [];

        foreach (range(1, 3) as $i) {
            $swipes[] = ['shown' => [$this->product('cooking', ['preference:vintage'])->id], 'verdict' => 'like'];
        }

        foreach (range(1, 2) as $i) {
            $swipes[] = ['shown' => [$this->product('gaming')->id], 'verdict' => 'dislike'];
        }

        $this->actingAs($user)->postJson('/be-nl/my-taste/learn', ['choices' => $swipes])->assertOk();

        $taste = UserTaste::query()->findOrFail($user->id);
        $this->assertContains('cooking', $taste->interests);
        $this->assertSame(['vintage'], $taste->preferences);
        $this->assertContains('interest:gaming', $taste->avoid);
    }

    #[Test]
    public function one_right_swipe_on_a_vibe_is_enough_unless_it_was_also_swiped_left(): void
    {
        // Owner, 2026-09-30: "no vibes are selected after swipe game". A swipe
        // learns a taste pole at a lower bar than This or that.
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/be-nl/my-taste/learn', ['choices' => [
            ['shown' => [$this->product('coffee', ['preference:design'])->id], 'verdict' => 'like'],
            ['shown' => [$this->product('music', ['preference:modern'])->id], 'verdict' => 'like'],
            ['shown' => [$this->product('gaming', ['preference:modern'])->id], 'verdict' => 'dislike'],
            ['shown' => [$this->product('reading')->id], 'verdict' => 'dislike'],
        ]])->assertOk();

        // Design: one like. Modern: one like and one dislike, 0.5, not enough.
        $this->assertSame(['design'], UserTaste::query()->findOrFail($user->id)->preferences);
    }

    #[Test]
    public function the_swipe_page_feeds_my_taste_only_for_yourself_signed_in(): void
    {
        $this->get('/be-nl/gift/swipe?for=me')->assertInertia(fn ($page) => $page->where('urls.mine', null));

        $user = User::factory()->create();

        $this->actingAs($user)->get('/be-nl/gift/swipe')->assertInertia(fn ($page) => $page->where('urls.mine', null));
        $this->actingAs($user)
            ->get('/be-nl/gift/swipe?for=me')
            ->assertInertia(fn ($page) => $page->where('urls.mine', '/be-nl/my-taste/learn'));
    }

    /**
     * A giver with a saved person linked to another account (as "Dit ben ik"
     * leaves it), who noted cooking, parfum to avoid and a budget of 50.
     *
     * @return array{0: User, 1: User, 2: Recipient}
     */
    private function friendSavedBy(bool $friends): array
    {
        $giver = User::factory()->create();
        $friend = User::factory()->create();

        if ($friends) {
            app(Friends::class)->link($giver, $friend);
        }

        $saved = Recipient::factory()->create([
            'owner_user_id' => $giver->id,
            'user_id' => $friend->id,
            'status' => RecipientStatus::Linked,
            'name' => 'Sam',
            'interests' => ['cooking'],
            'avoid' => ['parfum'],
            'budget_max' => 5000,
        ]);

        return [$giver, $friend, $saved];
    }

    /** @param  list<string>  $more */
    private function product(string $interest, array $more = []): ProductGroup
    {
        return ProductGroup::factory()->forMarket(Market::BeNl)->priced(3000)->create([
            'gift_tags' => ["interest:{$interest}", ...$more],
            'title' => 'Product '.Str::random(6),
        ]);
    }
}

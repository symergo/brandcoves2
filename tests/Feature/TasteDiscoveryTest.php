<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Enums\RecipientStatus;
use App\Enums\TasteSource;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Gift\SuggestionEngine;
use App\Services\Gift\TasteBrief;
use App\Services\Social\Friends;
use App\Services\Wishlist\ListBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * This or that, end to end: the rounds, the result, keeping it on a person,
 * the person's own path at /for/{token}/taste, and the engine leaving out an
 * avoided interest. See docs/features/taste-discovery.md.
 */
class TasteDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private const INTERESTS = ['cooking', 'coffee', 'gaming', 'music', 'reading', 'gardening', 'fitness', 'travel'];

    /** @var array<string, list<ProductGroup>> */
    private array $byInterest = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Invariant 1: nothing on this path may reach the model.
        $this->mock(AiClient::class, function ($mock): void {
            $mock->shouldNotReceive('json');
            $mock->shouldNotReceive('chat');
        });

        foreach (self::INTERESTS as $interest) {
            foreach ([1500, 3000, 6000] as $price) {
                $this->byInterest[$interest][] = $this->group(["interest:{$interest}"], $price);
            }
        }
    }

    /** @param list<string> $tags */
    private function group(array $tags, int $price, Market $market = Market::BeNl, string $title = ''): ProductGroup
    {
        return ProductGroup::factory()->forMarket($market)->priced($price)->create([
            'gift_tags' => $tags,
            'title' => $title !== '' ? $title : 'Product '.Str::random(6),
        ]);
    }

    /**
     * Cooking picked three times, each against something else.
     *
     * @return list<array<string, mixed>>
     */
    private function cookingChoices(): array
    {
        $choices = [];

        foreach (['gaming', 'music', 'reading'] as $i => $other) {
            $cooking = $this->byInterest['cooking'][$i];
            $choices[] = ['shown' => [$cooking->id, $this->byInterest[$other][$i]->id], 'picked' => $cooking->id];
        }

        return $choices;
    }

    #[Test]
    public function the_page_opens_with_rounds_of_products_from_this_market(): void
    {
        $foreign = $this->group(['interest:cooking'], 3000, Market::NlNl);

        $this->get('/be-nl/gift/taste')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Gift/Taste')
                ->where('mode', 'giver')
                ->has('rounds', 4)
                ->has('rounds.0', 2)
                // Every round a pair: the single card went to Swipe gifts (2026-09-28).
                ->has('rounds.3', 2)
                ->where('result', null));

        $ids = collect($this->get('/be-nl/gift/taste')->viewData('page')['props']['rounds'])->flatten(1)->pluck('id');
        $this->assertNotContains($foreign->id, $ids);
    }

    #[Test]
    public function the_next_rounds_leave_out_what_was_shown_or_queued(): void
    {
        $choices = $this->cookingChoices();
        $queued = [$this->byInterest['coffee'][0]->id, $this->byInterest['travel'][0]->id];

        $response = $this->postJson('/be-nl/gift/taste/next', [
            'choices' => $choices,
            'exclude' => $queued,
            'from' => 4,
        ])->assertOk();

        $rounds = $response->json('rounds');
        $this->assertCount(4, $rounds);
        // Every round a pair, the eighth too (no single card since 2026-09-28).
        $this->assertCount(2, $rounds[3]);

        $shown = collect($rounds)->flatten(1)->pluck('id')->all();
        $this->assertEmpty(array_intersect($shown, [...$queued, ...collect($choices)->pluck('shown')->flatten()->all()]));
    }

    #[Test]
    public function the_deck_draws_from_a_cached_pool_and_loads_only_what_it_shows(): void
    {
        $this->postJson('/be-nl/gift/taste/next', ['choices' => [], 'from' => 0])->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $rounds = $this->postJson('/be-nl/gift/taste/next', ['choices' => [], 'from' => 4])->assertOk()->json('rounds');

        $sql = array_column(DB::getQueryLog(), 'query');

        // The random sorts ran once, for the pool, and not again.
        $this->assertSame([], array_values(array_filter($sql, fn (string $q) => str_contains(strtolower($q), 'random()'))));

        // One read of product_groups: the cards on screen, by id.
        $reads = array_values(array_filter($sql, fn (string $q) => str_contains($q, 'from "product_groups"')));
        $this->assertCount(1, $reads);
        $this->assertStringNotContainsString('*', $reads[0]);

        $this->assertCount(4, $rounds);
    }

    #[Test]
    public function no_rounds_past_the_twelfth(): void
    {
        $this->postJson('/be-nl/gift/taste/next', ['choices' => [], 'from' => 12])
            ->assertOk()
            ->assertJsonPath('rounds', []);
    }

    #[Test]
    public function the_result_names_what_was_picked_and_suggests_new_things(): void
    {
        $choices = $this->cookingChoices();
        // All three cooking products were shown; a fourth is left to suggest.
        $unseen = $this->group(['interest:cooking'], 3000);

        $response = $this->post('/be-nl/gift/taste', ['choices' => $choices, 'for' => 'someone'])
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Gift/Taste')
                ->where('result.profile.interests', ['cooking'])
                ->where('result.for', 'someone')
                ->where('result.thin', true)
                ->has('result.choices', 3));

        $picks = collect($response->viewData('page')['props']['result']['picks'])->pluck('id')->all();
        $shown = collect($choices)->pluck('shown')->flatten()->all();

        $this->assertNotEmpty($picks);
        $this->assertEmpty(array_intersect($picks, $shown), 'what was already shown is not suggested again');
        $this->assertContains($unseen->id, $picks);
    }

    #[Test]
    public function choices_mean_what_the_catalogue_says_not_what_the_request_says(): void
    {
        $foreign = $this->group(['interest:cooking'], 3000, Market::NlNl);
        $other = $this->group(['interest:gaming'], 3000, Market::NlNl);

        // Another market's products are not choices here, however they are sent.
        $this->post('/be-nl/gift/taste', ['choices' => [
            ['shown' => [$foreign->id, $other->id], 'picked' => $foreign->id],
            ['shown' => [$this->byInterest['music'][0]->id, $this->byInterest['coffee'][0]->id], 'picked' => 999999],
        ]])->assertInertia(fn ($page) => $page
            ->where('result.profile.interests', [])
            ->where('result.profile.answered', 0));
    }

    #[Test]
    public function a_result_is_kept_on_one_of_your_people(): void
    {
        $user = User::factory()->create();
        $mum = Recipient::factory()->create(['owner_user_id' => $user->id, 'interests' => ['reading']]);

        $choices = $this->cookingChoices();
        $choices[] = ['shown' => [$this->byInterest['cooking'][1]->id], 'verdict' => 'like'];

        $this->actingAs($user)
            ->postJson('/be-nl/gift/taste/save', ['choices' => $choices, 'recipient_id' => $mum->id])
            ->assertOk()
            ->assertJson(['name' => $mum->name, 'tasteWritten' => true]);

        $mum->refresh();
        // Added in front of what was there, never replacing it.
        $this->assertSame(['cooking', 'reading'], $mum->interests);
        $this->assertSame(TasteSource::Suggested, $mum->taste_source);
        // The band learned goes on her list (2026-10-05), made for her if need be.
        $budget = app(ListBudget::class)->forRecipient($mum);
        $this->assertNotNull($budget['min']);
        $this->assertGreaterThan($budget['min'], $budget['max']);
    }

    #[Test]
    public function what_they_said_themselves_stays_but_the_budget_is_yours(): void
    {
        $user = User::factory()->create();
        $dad = Recipient::factory()->create([
            'owner_user_id' => $user->id,
            'interests' => ['fishing'],
            'taste_source' => TasteSource::Self,
        ]);

        $choices = $this->cookingChoices();
        $choices[] = ['shown' => [$this->byInterest['cooking'][1]->id], 'verdict' => 'like'];

        $this->actingAs($user)
            ->postJson('/be-nl/gift/taste/save', ['choices' => $choices, 'recipient_id' => $dad->id])
            ->assertOk()
            ->assertJson(['tasteWritten' => false]);

        $dad->refresh();
        $this->assertSame(['fishing'], $dad->interests);
        $this->assertNotNull(app(ListBudget::class)->forRecipient($dad)['min']);
    }

    #[Test]
    public function a_new_person_needs_an_account(): void
    {
        $this->postJson('/be-nl/gift/taste/save', ['choices' => $this->cookingChoices(), 'name' => 'Emma'])
            ->assertForbidden();

        $this->assertDatabaseMissing('recipients', ['name' => 'Emma']);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/be-nl/gift/taste/save', ['choices' => $this->cookingChoices(), 'name' => 'Emma'])
            ->assertOk();

        $emma = Recipient::query()->where('name', 'Emma')->sole();
        $this->assertSame($user->id, $emma->owner_user_id);
        $this->assertSame(['cooking'], $emma->interests);
    }

    /**
     * The people cards offer friends too (consistency review round 3): a
     * friend nobody saved becomes the linked person My people merges with
     * them, the second time reuses that person, and only a real friend may
     * be named.
     */
    #[Test]
    public function a_result_is_kept_on_a_friend_as_their_linked_person(): void
    {
        $user = User::factory()->create();
        $sam = User::factory()->create(['name' => 'Sam']);
        app(Friends::class)->link($user, $sam);

        $this->actingAs($user)
            ->postJson('/be-nl/gift/taste/save', ['choices' => $this->cookingChoices(), 'friend_id' => $sam->id])
            ->assertOk()
            ->assertJson(['name' => 'Sam']);

        $person = Recipient::query()->where('owner_user_id', $user->id)->sole();
        $this->assertSame($sam->id, $person->user_id);
        $this->assertSame(RecipientStatus::Linked, $person->status);
        $this->assertSame(['cooking'], $person->interests);

        // Again: the same person, not a second one.
        $this->actingAs($user)
            ->postJson('/be-nl/gift/taste/save', ['choices' => $this->cookingChoices(), 'friend_id' => $sam->id])
            ->assertOk();
        $this->assertSame(1, Recipient::query()->where('owner_user_id', $user->id)->count());

        // A stranger's account is not a friend.
        $this->actingAs($user)
            ->postJson('/be-nl/gift/taste/save', ['choices' => $this->cookingChoices(), 'friend_id' => User::factory()->create()->id])
            ->assertNotFound();
        $this->assertSame(1, Recipient::query()->where('owner_user_id', $user->id)->count());
    }

    #[Test]
    public function the_result_offers_your_people_as_cards_with_friends(): void
    {
        $user = User::factory()->create();
        $sam = User::factory()->create(['name' => 'Sam']);
        app(Friends::class)->link($user, $sam);
        Recipient::factory()->create(['owner_user_id' => $user->id, 'name' => 'Mama']);

        $this->actingAs($user)
            ->post('/be-nl/gift/taste', ['choices' => $this->cookingChoices()])
            ->assertInertia(fn ($page) => $page
                ->has('people', 2)
                ->where('people', fn ($people) => collect($people)->contains(fn ($p) => $p['friend']['id'] ?? null) === true));
    }

    #[Test]
    public function somebody_elses_person_cannot_be_written(): void
    {
        $theirs = Recipient::factory()->create();

        $this->actingAs(User::factory()->create())
            ->postJson('/be-nl/gift/taste/save', ['choices' => $this->cookingChoices(), 'recipient_id' => $theirs->id])
            ->assertNotFound();

        $this->assertSame([], $theirs->refresh()->interests);
    }

    #[Test]
    public function nothing_learned_is_nothing_saved(): void
    {
        $user = User::factory()->create();
        $mum = Recipient::factory()->create(['owner_user_id' => $user->id]);

        $skipped = [['shown' => [$this->byInterest['music'][0]->id, $this->byInterest['coffee'][0]->id]]];

        $this->actingAs($user)
            ->postJson('/be-nl/gift/taste/save', ['choices' => $skipped, 'recipient_id' => $mum->id])
            ->assertStatus(422);
    }

    #[Test]
    public function the_person_themselves_can_choose_from_their_own_page(): void
    {
        $sam = Recipient::factory()->create(['interests' => ['music'], 'taste_source' => TasteSource::Suggested]);

        $this->get("/be-nl/for/{$sam->share_token}")
            ->assertOk();

        $this->get("/be-nl/for/{$sam->share_token}/taste")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Gift/Taste')
                ->where('mode', 'self')
                ->where('person', ['name' => $sam->name])
                ->has('rounds', 4)
                // Nothing of the giver's: no people to save onto, no notes.
                ->where('recipients', []));

        $choices = $this->cookingChoices();

        $this->post("/be-nl/for/{$sam->share_token}/taste", ['choices' => $choices])
            ->assertInertia(fn ($page) => $page
                ->where('result.for', 'me')
                ->where('result.profile.interests', ['cooking']));

        $this->postJson("/be-nl/for/{$sam->share_token}/taste/save", ['choices' => $choices])
            ->assertOk()
            ->assertJsonPath('redirect', "/be-nl/for/{$sam->share_token}");

        $sam->refresh();
        $this->assertSame(TasteSource::Self, $sam->taste_source);
        // Their choices, not merged with the giver's guess of "music".
        $this->assertSame(['cooking'], $sam->interests);
        // The budget is the giver's to set, never theirs.
        $this->assertNull($sam->budget_min);
    }

    #[Test]
    public function the_person_adds_to_what_they_said_before(): void
    {
        $sam = Recipient::factory()->create(['interests' => ['travel'], 'taste_source' => TasteSource::Self]);

        $this->postJson("/be-nl/for/{$sam->share_token}/taste/save", ['choices' => $this->cookingChoices()])
            ->assertOk();

        $this->assertSame(['cooking', 'travel'], $sam->refresh()->interests);
    }

    #[Test]
    public function an_unknown_token_is_not_found(): void
    {
        $this->get('/be-nl/for/'.Str::uuid().'/taste')->assertNotFound();
    }

    #[Test]
    public function the_engine_leaves_out_an_avoided_interest_by_its_tag_and_never_by_a_title_word(): void
    {
        $gaming = $this->group(['interest:gaming', 'interest:tech'], 3000);
        // "art" is an interest, and a title word inside "Smartwatch".
        $smart = $this->group(['interest:tech'], 3000, title: 'Smartwatch met hartslag');

        $picks = app(SuggestionEngine::class)->suggest(new TasteBrief(
            market: Market::BeNl,
            interests: ['tech'],
            avoid: ['interest:gaming', 'interest:art'],
            limit: 8,
        ));

        $ids = array_map(fn ($pick) => $pick->group->id, $picks);

        $this->assertNotContains($gaming->id, $ids);
        $this->assertContains($smart->id, $ids);
    }
}

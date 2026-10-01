<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\User;
use App\Models\UserTaste;
use App\Models\Wishlist;
use App\Services\Ai\AiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Swipe gifts: one card at a time, right onto the list, left to pass, with
 * no end but Stop (owner, 2026-09-28). See docs/features/swipe-gifts.md.
 */
class SwipeGiftsTest extends TestCase
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
            foreach ([1500, 3000, 6000, 9000] as $price) {
                $this->byInterest[$interest][] = ProductGroup::factory()->forMarket(Market::BeNl)->priced($price)->create([
                    'gift_tags' => ["interest:{$interest}"],
                    'title' => 'Product '.Str::random(6),
                ]);
            }
        }
    }

    #[Test]
    public function the_page_opens_on_a_first_batch_of_cards(): void
    {
        $this->get('/be-nl/gift/swipe')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Gift/Swipe')
                ->has('cards', 8)
                ->where('carried', ['person' => null, 'relationship' => null, 'forMe' => false, 'gender' => null])
                ->where('urls.next', '/be-nl/gift/swipe/next'));
    }

    #[Test]
    public function it_carries_who_find_a_gift_said_it_is_for(): void
    {
        $this->get('/be-nl/gift/swipe?for=me')
            ->assertInertia(fn ($page) => $page->where('carried.forMe', true));

        $this->get('/be-nl/gift/swipe?relationship=mother')
            ->assertInertia(fn ($page) => $page->where('carried.relationship', 'mother'));
    }

    #[Test]
    public function the_next_batch_follows_what_was_liked_and_repeats_nothing(): void
    {
        $liked = $this->byInterest['coffee'][0];

        $cards = $this->postJson('/be-nl/gift/swipe/next', [
            'yes' => [$liked->id],
            'no' => [],
            'exclude' => [$liked->id],
        ])->assertOk()->json('cards');

        $ids = array_column($cards, 'id');

        $this->assertNotEmpty($ids);
        $this->assertNotContains($liked->id, $ids);

        $coffee = array_map(fn (ProductGroup $g) => $g->id, $this->byInterest['coffee']);
        $this->assertNotEmpty(array_intersect($ids, $coffee), 'a liked interest comes back');
    }

    #[Test]
    public function it_runs_out_only_when_the_catalogue_does(): void
    {
        // Everything already seen: an empty batch is how the page learns it is the end.
        $all = ProductGroup::query()->pluck('id')->all();

        $this->postJson('/be-nl/gift/swipe/next', ['exclude' => $all])
            ->assertOk()
            ->assertJsonCount(0, 'cards');
    }

    #[Test]
    public function a_child_never_gets_drinks(): void
    {
        // The relationship's exclusions are a hard rule (gift_landings.excluded_pairs).
        $drinks = [];

        foreach (range(1, 6) as $i) {
            $drinks[] = $this->group('drinks')->id;
        }

        $cards = $this->get('/be-nl/gift/swipe?relationship=child')->viewData('page')['props']['cards'];

        $this->assertNotEmpty($cards);
        $this->assertEmpty(array_intersect(array_column($cards, 'id'), $drinks));
    }

    #[Test]
    public function swiping_for_yourself_starts_from_your_own_taste(): void
    {
        $user = User::factory()->create();
        UserTaste::query()->create(['user_id' => $user->id, 'interests' => ['coffee']]);

        $cards = $this->actingAs($user)->get('/be-nl/gift/swipe?for=me')->viewData('page')['props']['cards'];

        $this->assertStartsWithKnown($cards, 'coffee');
    }

    #[Test]
    public function swiping_for_a_saved_person_starts_from_their_taste(): void
    {
        $user = User::factory()->create();
        $mum = Recipient::factory()->create(['owner_user_id' => $user->id, 'name' => 'Mum', 'interests' => ['reading']]);

        $cards = $this->actingAs($user)->get("/be-nl/gift/swipe?person={$mum->id}")->viewData('page')['props']['cards'];

        $this->assertStartsWithKnown($cards, 'reading');
    }

    #[Test]
    public function this_or_that_opens_on_what_is_known(): void
    {
        $user = User::factory()->create();
        $mum = Recipient::factory()->create(['owner_user_id' => $user->id, 'name' => 'Mum', 'interests' => ['gardening']]);

        $rounds = $this->actingAs($user)->get("/be-nl/gift/taste?person={$mum->id}")->viewData('page')['props']['rounds'];
        $gardening = array_map(fn (ProductGroup $g) => $g->id, $this->byInterest['gardening']);

        $this->assertNotEmpty(array_intersect(array_column($rounds[0], 'id'), $gardening));
    }

    /**
     * The first two cards follow the known interest (it counts as a like
     * before any swipe); the third explores.
     *
     * @param  list<array<string, mixed>>  $cards
     */
    private function assertStartsWithKnown(array $cards, string $interest): void
    {
        $known = array_map(fn (ProductGroup $g) => $g->id, $this->byInterest[$interest]);

        $this->assertContains($cards[0]['id'], $known);
        $this->assertContains($cards[1]['id'], $known);
    }

    private function group(string $interest): ProductGroup
    {
        return ProductGroup::factory()->forMarket(Market::BeNl)->priced(3000)->create([
            'gift_tags' => ["interest:{$interest}"],
            'title' => 'Product '.Str::random(6),
        ]);
    }

    #[Test]
    public function somebody_elses_person_is_ignored(): void
    {
        $theirs = Recipient::factory()->create(['owner_user_id' => User::factory()->create()->id, 'name' => 'Theirs']);

        $this->actingAs(User::factory()->create())
            ->get("/be-nl/gift/swipe?person={$theirs->id}")
            ->assertInertia(fn ($page) => $page->where('carried.person', null));
    }

    #[Test]
    public function opened_for_a_list_the_right_swipes_go_into_it_and_only_into_your_own(): void
    {
        // "Add a product" on Mijn Coves links here with ?list= (owner, 2026-10-01).
        $me = User::factory()->create();
        $mine = Wishlist::factory()->create(['owner_user_id' => $me->id, 'market' => Market::BeNl, 'title' => 'Camping']);
        $theirs = Wishlist::factory()->create(['owner_user_id' => User::factory()->create()->id, 'market' => Market::BeNl]);

        $this->actingAs($me)->get("/be-nl/gift/swipe?list={$mine->id}")
            ->assertInertia(fn ($page) => $page->where('into.id', (string) $mine->id)->where('into.title', 'Camping'));

        $this->actingAs($me)->get("/be-nl/gift/swipe?list={$theirs->id}")
            ->assertInertia(fn ($page) => $page->where('into', null));
    }
}

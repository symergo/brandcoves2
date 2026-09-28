<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Models\GiftVote;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\RecipientFeedback;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Gift\GiftFeedback;
use App\Services\Gift\Suggestion;
use App\Services\Gift\SuggestionEngine;
use App\Services\Gift\TasteBrief;
use App\Support\Owner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Thumbs up and down on Find a gift's ideas, and what the engine learns from
 * them: for one saved person, and from everybody once enough people agree.
 * See docs/features/find-a-gift.md, "Thumbs up, thumbs down".
 */
class GiftFeedbackTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<ProductGroup> */
    private array $cooking = [];

    /** @var list<ProductGroup> */
    private array $gaming = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Invariant 1: nothing on this path may reach the model.
        $this->mock(AiClient::class, function ($mock): void {
            $mock->shouldNotReceive('json');
            $mock->shouldNotReceive('chat');
        });

        // The threshold the privacy page names, whatever the environment says.
        config(['giftcoves.gift.feedback.min_voters' => 5]);

        foreach ([1500, 2500, 3500, 4500, 5500, 6500] as $i => $price) {
            $this->cooking[] = ProductGroup::factory()->forMarket(Market::BeNl)->priced($price)->create([
                'gift_tags' => ['interest:cooking'],
                'title' => 'Kookding '.Str::random(6),
                // Two kinds of cooking present, so a like can favour one.
                'category' => $i % 2 === 0 ? 'pannen' : 'kookboeken',
                'brand' => 'Merk '.$i,
            ]);
            $this->gaming[] = ProductGroup::factory()->forMarket(Market::BeNl)->priced($price)->create([
                'gift_tags' => ['interest:gaming'],
                'title' => 'Spelding '.Str::random(6),
                'category' => 'games',
                'brand' => 'Spel '.$i,
            ]);
        }
    }

    /** @return list<int> the ids on a Find a gift board */
    private function boardIds(TestResponse $response): array
    {
        return array_map(fn (array $pick) => (int) $pick['id'], $response->viewData('page')['props']['picks'] ?? []);
    }

    private function person(User $owner, array $attributes = []): Recipient
    {
        return Recipient::factory()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Mama',
            'relationship' => 'mama',
            'interests' => ['cooking', 'gaming'],
            ...$attributes,
        ]);
    }

    #[Test]
    public function a_thumb_down_replaces_the_idea_and_it_never_returns_for_that_person(): void
    {
        $user = User::factory()->create();
        $mama = $this->person($user);

        $first = $this->boardIds($this->actingAs($user)->post('/be-nl/gift', ['recipient_id' => $mama->id]));
        $this->assertNotEmpty($first);
        $rejected = $first[0];

        // The thumb down is the swap: the card is replaced at once.
        $after = $this->boardIds($this->actingAs($user)->post('/be-nl/gift/swap', [
            'recipient_id' => $mama->id,
            'rejected' => $rejected,
        ]));
        $this->assertNotContains($rejected, $after);

        $this->assertDatabaseHas('recipient_feedback', ['recipient_id' => $mama->id, 'group_id' => $rejected, 'vote' => 'down']);

        // A new sitting (opening Find a gift forgets the session's
        // rejections) and a different brief: still never for her.
        $this->actingAs($user)->get('/be-nl/gift');
        $this->assertNotContains($rejected, $this->boardIds($this->actingAs($user)->get("/be-nl/gift?for={$mama->id}")));
        // Her own interests: since 2026-09-28 what Find a gift is told about a
        // saved person is always kept on them, so a narrower brief here would
        // rewrite them and test that instead of the thumb.
        $this->assertNotContains($rejected, $this->boardIds($this->actingAs($user)->post('/be-nl/gift', [
            'recipient_id' => $mama->id,
            'interests' => ['cooking', 'gaming'],
            'budget_max' => 60,
        ])));

        // It was not written into what the owner said about her.
        $this->assertSame(['cooking', 'gaming'], $mama->fresh()->interests);
        $this->assertSame([], array_values((array) $mama->fresh()->avoid));
    }

    #[Test]
    public function a_thumb_up_stays_pressed_and_lifts_ideas_like_it_for_that_person(): void
    {
        $user = User::factory()->create();
        $mama = $this->person($user);
        $liked = $this->cooking[0]; // pannen

        $this->actingAs($user)->postJson('/be-nl/gift/feedback', [
            'group_id' => $liked->id,
            'vote' => 'up',
            'recipient_id' => $mama->id,
        ])->assertOk()->assertJson(['vote' => 'up']);

        $engine = app(SuggestionEngine::class);
        $brief = new TasteBrief(market: Market::BeNl, interests: ['cooking', 'gaming'], limit: 12, recipientId: $mama->id);
        $picks = collect($engine->suggest($brief))->keyBy(fn (Suggestion $s) => $s->group->id);

        // Same interest and category as the liked one: lifted.
        $sibling = $picks->get($this->cooking[2]->id);
        $this->assertNotNull($sibling);
        $this->assertGreaterThan(0, $sibling->breakdown['feedback']);

        // Another interest altogether: untouched.
        $other = $picks->get($this->gaming[2]->id);
        $this->assertNotNull($other);
        $this->assertSame(0.0, $other->breakdown['feedback']);

        // The same cooking pan in a category the owner did not like scores
        // less than the sibling in the liked one.
        $this->assertGreaterThan($picks->get($this->cooking[1]->id)->breakdown['feedback'], $sibling->breakdown['feedback']);

        // Without the person, nobody else's brief feels it.
        $plain = collect($engine->suggest(new TasteBrief(market: Market::BeNl, interests: ['cooking', 'gaming'], limit: 12)))
            ->keyBy(fn (Suggestion $s) => $s->group->id);
        $this->assertSame(0.0, $plain->get($this->cooking[2]->id)->breakdown['feedback']);

        // The board draws it pressed.
        $board = $this->actingAs($user)->post('/be-nl/gift', ['recipient_id' => $mama->id])->viewData('page')['props']['picks'];
        $card = collect($board)->firstWhere('id', $liked->id);
        $this->assertNotNull($card);
        $this->assertSame('up', $card['vote']);

        // Pressing again takes it back.
        $this->actingAs($user)->postJson('/be-nl/gift/feedback', [
            'group_id' => $liked->id,
            'vote' => '',
            'recipient_id' => $mama->id,
        ])->assertOk()->assertJson(['vote' => null]);
        $this->assertDatabaseMissing('recipient_feedback', ['recipient_id' => $mama->id, 'group_id' => $liked->id]);
        $this->assertDatabaseMissing('gift_votes', ['group_id' => $liked->id]);
    }

    #[Test]
    public function one_voter_cannot_move_the_crowd_signal_twice(): void
    {
        $user = User::factory()->create();
        $product = $this->cooking[3];

        foreach (['up', 'up', 'down', 'up'] as $vote) {
            $this->actingAs($user)->postJson('/be-nl/gift/feedback', ['group_id' => $product->id, 'vote' => $vote, 'relationship' => 'mother'])->assertOk();
        }

        // One row, the latest opinion, and a one-way code instead of the id.
        $this->assertSame(1, GiftVote::query()->where('group_id', $product->id)->count());
        $vote = GiftVote::query()->where('group_id', $product->id)->first();
        $this->assertSame('up', $vote->vote->value);
        $this->assertSame('mother', $vote->relationship);
        $this->assertSame((new Owner($user, null))->identityHash('gift-vote'), $vote->voter_hash);
        $this->assertStringNotContainsString('user:', $vote->voter_hash);
    }

    #[Test]
    public function the_crowd_signal_is_inert_below_the_threshold_and_counts_at_it(): void
    {
        $product = $this->cooking[4];
        $feedback = app(GiftFeedback::class);

        for ($voter = 1; $voter <= 4; $voter++) {
            $this->actingAs(User::factory()->create())
                ->postJson('/be-nl/gift/feedback', ['group_id' => $product->id, 'vote' => 'up', 'relationship' => 'mother'])
                ->assertOk();
        }

        // Four people: nothing, for any kind of person.
        $this->assertSame([], $feedback->crowd([$product->id], 'mother', Market::BeNl));
        $this->assertSame([], $feedback->crowd([$product->id], null, Market::BeNl));

        $brief = new TasteBrief(market: Market::BeNl, interests: ['cooking'], relationship: 'mother', limit: 12);
        $before = collect(app(SuggestionEngine::class)->suggest($brief))->keyBy(fn (Suggestion $s) => $s->group->id);
        $this->assertSame(0.0, $before->get($product->id)->breakdown['crowd_votes']);

        $this->actingAs(User::factory()->create())
            ->postJson('/be-nl/gift/feedback', ['group_id' => $product->id, 'vote' => 'up', 'relationship' => 'mama'])
            ->assertOk();

        // Five different people, "mama" read as a mother: it counts.
        $crowd = $feedback->crowd([$product->id], 'mother', Market::BeNl);
        $this->assertArrayHasKey($product->id, $crowd);
        $this->assertGreaterThan(0, $crowd[$product->id]);

        $after = collect(app(SuggestionEngine::class)->suggest($brief))->keyBy(fn (Suggestion $s) => $s->group->id);
        $this->assertGreaterThan(0, $after->get($product->id)->breakdown['crowd_votes']);
    }

    #[Test]
    public function another_owners_person_never_sees_this_owners_thumbs(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $alicesMum = $this->person($alice);
        $bobsMum = $this->person($bob);
        $product = $this->cooking[0];

        $this->actingAs($alice)->postJson('/be-nl/gift/feedback', [
            'group_id' => $product->id,
            'vote' => 'down',
            'recipient_id' => $alicesMum->id,
        ])->assertOk();

        // Bob naming Alice's person writes nothing on her.
        $this->actingAs($bob)->postJson('/be-nl/gift/feedback', [
            'group_id' => $this->cooking[1]->id,
            'vote' => 'down',
            'recipient_id' => $alicesMum->id,
        ])->assertOk();
        $this->assertSame(1, RecipientFeedback::query()->where('recipient_id', $alicesMum->id)->count());

        // Alice's "no" is final for her mum, and nothing for Bob's.
        $this->assertNotContains($product->id, $this->boardIds($this->actingAs($alice)->post('/be-nl/gift', ['recipient_id' => $alicesMum->id, 'interests' => ['cooking']])));
        $this->assertContains($product->id, $this->boardIds($this->actingAs($bob)->post('/be-nl/gift', ['recipient_id' => $bobsMum->id, 'interests' => ['cooking']])));
    }

    #[Test]
    public function without_a_saved_person_a_thumb_down_is_only_a_crowd_vote(): void
    {
        // A guest, shopping for "a mother": the swap still replaces the card.
        $first = $this->boardIds($this->post('/be-nl/gift', ['relationship' => 'mother', 'interests' => ['cooking']]));
        $rejected = $first[0];

        $after = $this->boardIds($this->post('/be-nl/gift/swap', [
            'relationship' => 'mother',
            'interests' => ['cooking'],
            'rejected' => $rejected,
        ]));
        $this->assertNotContains($rejected, $after);

        $this->assertSame(0, RecipientFeedback::query()->count());
        $this->assertDatabaseHas('gift_votes', ['group_id' => $rejected, 'relationship' => 'mother', 'vote' => 'down']);

        // A product of another market is refused.
        $foreign = ProductGroup::factory()->forMarket(Market::NlNl)->create();
        $this->postJson('/be-nl/gift/feedback', ['group_id' => $foreign->id, 'vote' => 'up'])->assertNotFound();
    }

    #[Test]
    public function thumbs_go_with_the_person_the_account_and_the_year(): void
    {
        $user = User::factory()->create();
        $mama = $this->person($user);

        $this->actingAs($user)->postJson('/be-nl/gift/feedback', ['group_id' => $this->cooking[0]->id, 'vote' => 'up', 'recipient_id' => $mama->id])->assertOk();
        $this->assertSame(1, RecipientFeedback::query()->count());
        $this->assertSame(1, GiftVote::query()->count());

        // Deleting the person deletes what was said about ideas for them.
        $mama->delete();
        $this->assertSame(0, RecipientFeedback::query()->count());

        // Deleting the account deletes its crowd votes, found by their code.
        $user->delete();
        $this->assertSame(0, GiftVote::query()->count());

        // Anybody's vote goes a year after it last changed.
        $other = User::factory()->create();
        $this->actingAs($other)->postJson('/be-nl/gift/feedback', ['group_id' => $this->cooking[1]->id, 'vote' => 'up'])->assertOk();
        $this->actingAs($other)->postJson('/be-nl/gift/feedback', ['group_id' => $this->cooking[2]->id, 'vote' => 'up'])->assertOk();
        GiftVote::query()->where('group_id', $this->cooking[1]->id)->update(['updated_at' => now()->subDays(366)]);

        Artisan::call('bc:prune-personal-data');

        $this->assertDatabaseMissing('gift_votes', ['group_id' => $this->cooking[1]->id]);
        $this->assertDatabaseHas('gift_votes', ['group_id' => $this->cooking[2]->id]);
    }
}

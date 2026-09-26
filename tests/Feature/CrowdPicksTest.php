<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\Market;
use App\Jobs\CountListSignals;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Ai\AiClient;
use App\Services\Gift\CrowdPicks;
use App\Services\Gift\SuggestionEngine;
use App\Services\Gift\TasteBrief;
use App\Services\Gift\TasteDeck;
use App\Services\Search\GiftIntentParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Chosen by others for someone like them" (docs/features/crowd-picks.md):
 * counted in different people, from five, as a pair only when the same lists
 * say both, inside one market, and shown on the card only from five.
 */
class CrowdPicksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Invariant 1: nothing here may reach the model.
        $this->mock(AiClient::class, function ($mock): void {
            $mock->shouldNotReceive('json');
            $mock->shouldNotReceive('chat');
        });
    }

    #[Test]
    public function a_pair_counts_only_when_the_same_people_shopped_for_both(): void
    {
        $knife = $this->group('Koksmes');

        // Five people shopping for a father, five others for somebody who cooks.
        foreach (range(1, 5) as $i) {
            $this->listFor($knife, relationship: 'father');
            $this->listFor($knife, interests: ['cooking']);
        }
        $this->countSignals();

        $this->assertSame(
            ['interest:cooking', 'recipient:father'],
            $this->contextsOf($knife),
            'Both facts, and not "a father who cooks": nobody shopped for one.',
        );

        $pan = $this->group('Pan');

        foreach (range(1, 5) as $i) {
            $this->listFor($pan, relationship: 'father', interests: ['cooking']);
        }
        $this->countSignals();

        $this->assertContains('interest:cooking+recipient:father', $this->contextsOf($pan));
        $this->assertSame(5, (int) DB::table('crowd_picks')->where('group_id', $pan->id)->where('context', 'interest:cooking+recipient:father')->value('owners'));
    }

    #[Test]
    public function one_person_with_many_lists_is_one_person(): void
    {
        $pan = $this->group('Pan');
        $keen = User::factory()->create();

        foreach (range(1, 4) as $i) {
            $this->listFor($pan, relationship: 'father', owner: $keen);
        }
        foreach (range(1, 3) as $i) {
            $this->listFor($pan, relationship: 'father');
        }
        $this->countSignals();

        $this->assertSame([], $this->contextsOf($pan), 'Four people, not seven lists.');
    }

    #[Test]
    public function lists_count_only_for_their_own_market(): void
    {
        $pan = $this->group('Pan');

        // Five lists in another market holding this market's product.
        foreach (range(1, 5) as $i) {
            $this->listFor($pan, relationship: 'father', market: Market::NlNl);
        }
        $this->countSignals();

        $this->assertSame([], $this->contextsOf($pan));

        // And a pick in be-nl is nothing to a shopper in nl-nl.
        DB::table('crowd_picks')->insert(['market' => 'be-nl', 'context' => 'recipient:father', 'group_id' => $pan->id, 'owners' => 9]);

        $picks = app(CrowdPicks::class);
        $this->assertArrayHasKey($pan->id, $picks->forBrief(new TasteBrief(market: Market::BeNl, relationship: 'father')));
        $this->assertSame([], $picks->forBrief(new TasteBrief(market: Market::NlNl, relationship: 'father')));
        $this->assertSame([], $picks->provenGifts(Market::NlNl, 10));
        $this->assertSame([$pan->id], $picks->provenGifts(Market::BeNl, 10));
    }

    #[Test]
    public function the_gift_finder_labels_a_pick_only_from_five_people(): void
    {
        $pan = $this->group('Pan', ['interest:cooking']);
        $plain = $this->group('Schort', ['interest:cooking']);

        foreach (range(1, 4) as $i) {
            $this->listFor($pan, relationship: 'father', interests: ['cooking']);
        }
        $this->countSignals();

        $cards = $this->cards($this->post('/be-nl/gift', $this->brief())->assertOk());
        $this->assertFalse($cards[$pan->id], 'Four people: no label.');
        $this->assertFalse($cards[$plain->id]);

        $this->listFor($pan, relationship: 'father', interests: ['cooking']);
        $this->countSignals();

        $cards = $this->cards($this->post('/be-nl/gift', $this->brief())->assertOk());
        $this->assertTrue($cards[$pan->id], 'Five people: labelled, and tonight\'s count is not hidden behind last night\'s cache.');
        $this->assertFalse($cards[$plain->id]);
    }

    #[Test]
    public function a_proven_gift_the_search_missed_still_reaches_the_board_and_ranks_higher(): void
    {
        // Nothing in its title or tags says cooking.
        $proven = $this->group('Iets moois');
        $this->group('Pan', ['interest:cooking']);
        $this->group('Schort', ['interest:cooking']);

        $brief = new TasteBrief(market: Market::BeNl, interests: ['cooking'], relationship: 'father', limit: 8);
        $engine = app(SuggestionEngine::class);

        $this->assertNotContains($proven->id, array_map(fn ($p) => $p->group->id, $engine->suggest($brief)));

        DB::table('crowd_picks')->insert([
            'market' => 'be-nl', 'context' => 'interest:cooking+recipient:father', 'group_id' => $proven->id, 'owners' => 6,
        ]);
        CrowdPicks::forgetCached();

        $picks = $engine->suggest($brief);
        $found = collect($picks)->first(fn ($p) => $p->group->id === $proven->id);

        $this->assertNotNull($found);
        $this->assertTrue($found->chosenByOthers());
        $this->assertGreaterThan(0.0, $found->breakdown['crowd']);
    }

    #[Test]
    public function a_row_under_the_threshold_is_ignored_even_if_it_is_in_the_table(): void
    {
        $pan = $this->group('Pan', ['interest:cooking']);

        // Written under a lower setting, or by hand: still not shown.
        DB::table('crowd_picks')->insert(['market' => 'be-nl', 'context' => 'recipient:father', 'group_id' => $pan->id, 'owners' => 2]);

        $this->assertSame([], app(CrowdPicks::class)->forBrief(new TasteBrief(market: Market::BeNl, relationship: 'father')));
        $this->assertSame([], app(CrowdPicks::class)->provenGifts(Market::BeNl, 10));
    }

    #[Test]
    public function an_empty_count_changes_nothing(): void
    {
        $pan = $this->group('Pan', ['interest:cooking']);
        $this->countSignals();

        $this->assertSame(0, DB::table('crowd_picks')->count());

        $picks = app(SuggestionEngine::class)->suggest(new TasteBrief(market: Market::BeNl, interests: ['cooking'], relationship: 'father'));

        $this->assertSame([$pan->id], array_map(fn ($p) => $p->group->id, $picks));
        $this->assertFalse($picks[0]->chosenByOthers());
        $this->assertSame(0.0, $picks[0]->breakdown['crowd']);
    }

    #[Test]
    public function this_or_that_labels_its_ideas_too(): void
    {
        $choices = [];

        foreach (['gaming', 'music', 'reading'] as $other) {
            $cooking = $this->group('Kook '.$other, ['interest:cooking']);
            $choices[] = ['shown' => [$cooking->id, $this->group('Iets '.$other, ["interest:{$other}"])->id], 'picked' => $cooking->id];
        }

        $proven = $this->group('Wok', ['interest:cooking']);
        DB::table('crowd_picks')->insert(['market' => 'be-nl', 'context' => 'interest:cooking', 'group_id' => $proven->id, 'owners' => 5]);

        $response = $this->post('/be-nl/gift/taste', ['choices' => $choices, 'for' => 'someone'])->assertOk();
        $picks = collect($response->viewData('page')['props']['result']['picks'])->keyBy('id');

        $this->assertTrue($picks[$proven->id]['chosenByOthers']);
    }

    #[Test]
    public function this_or_that_draws_proven_gifts_through_its_usual_filters(): void
    {
        $proven = $this->group('Kookboek', ['interest:cooking']);
        $tooDear = ProductGroup::factory()->forMarket(Market::BeNl)->priced(9_999_999)->create(['gift_tags' => ['interest:music']]);
        $this->group('Koptelefoon', ['interest:music']);
        $this->group('Spel', ['interest:gaming']);

        DB::table('crowd_picks')->insert([
            ['market' => 'be-nl', 'context' => 'interest:cooking', 'group_id' => $proven->id, 'owners' => 5],
            ['market' => 'be-nl', 'context' => 'interest:music', 'group_id' => $tooDear->id, 'owners' => 50],
        ]);

        $shown = collect(app(TasteDeck::class)->next(Market::BeNl, [], [], 0))->flatten()->pluck('id')->all();

        $this->assertContains($proven->id, $shown);
        $this->assertNotContains($tooDear->id, $shown, 'Outside the price window: proven or not, it is not drawn.');
    }

    /** @return array<string, mixed> */
    private function brief(): array
    {
        return ['relationship' => 'father', 'interests' => ['cooking'], 'budget_min' => 0, 'budget_max' => 200];
    }

    /** @return array<int, bool> group id => labelled */
    private function cards(TestResponse $response): array
    {
        return collect($response->viewData('page')['props']['picks'] ?? [])
            ->mapWithKeys(fn (array $pick) => [$pick['id'] => $pick['chosenByOthers']])
            ->all();
    }

    /** @return list<string> */
    private function contextsOf(ProductGroup $group): array
    {
        return DB::table('crowd_picks')->where('group_id', $group->id)->orderBy('context')->pluck('context')->all();
    }

    private function countSignals(): void
    {
        (new CountListSignals)->handle(app(GiftIntentParser::class));
    }

    /** @param list<string> $interests */
    private function listFor(
        ProductGroup $group,
        ?string $relationship = null,
        array $interests = [],
        ?User $owner = null,
        Market $market = Market::BeNl,
    ): Wishlist {
        $owner ??= User::factory()->create();

        $recipient = $relationship === null && $interests === [] ? null : Recipient::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Iemand',
            'relationship' => $relationship,
            'interests' => $interests,
        ]);

        $list = Wishlist::create([
            'owner_user_id' => $owner->id,
            'title' => 'Een lijst',
            'market' => $market,
            'kind' => ListKind::ForSomeone,
            'visibility' => 'private',
            'recipient_id' => $recipient?->id,
        ]);

        WishlistItem::query()->create([
            'wishlist_id' => $list->id,
            'group_id' => $group->id,
            'snapshot_title' => $group->title,
            'accepted_at' => now(),
        ]);

        return $list;
    }

    /** @param list<string> $tags */
    private function group(string $title, array $tags = [], Market $market = Market::BeNl): ProductGroup
    {
        return ProductGroup::factory()->forMarket($market)->priced(4000)->create([
            'gift_tags' => $tags,
            'title' => $title.' '.Str::random(4),
        ]);
    }
}

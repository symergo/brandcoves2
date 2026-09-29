<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CoveKind;
use App\Enums\ListKind;
use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Models\CovePlan;
use App\Models\DailyPickSet;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use App\Services\Ai\AiClient;
use App\Services\Social\Friends;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Find a gift" as one flow: who it is for first, three ways in, and one
 * results page that the questions and This or that both end on. See
 * docs/features/find-a-gift.md.
 */
class FindAGiftTest extends TestCase
{
    use RefreshDatabase;

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

        foreach (['cooking', 'gaming', 'music', 'reading'] as $interest) {
            foreach ([1500, 3000, 6000] as $price) {
                $this->byInterest[$interest][] = ProductGroup::factory()->forMarket(Market::BeNl)->priced($price)->create([
                    'gift_tags' => ["interest:{$interest}"],
                    'title' => 'Product '.Str::random(6),
                ]);
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function cookingChoices(): array
    {
        $choices = [];

        foreach (['gaming', 'music', 'reading'] as $i => $other) {
            $cooking = $this->byInterest['cooking'][$i];
            $choices[] = ['shown' => [$cooking->id, $this->byInterest[$other][$i]->id], 'picked' => $cooking->id];
        }

        return $choices;
    }

    private function persona(string $slug, ?string $relationship, string $publishedAt): DailyPickSet
    {
        $persona = DailyPickSet::create([
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Persona->value,
            'slug' => $slug,
            'theme_title' => Str::headline($slug),
            'theme_slug' => $slug,
            'theme_blurb' => 'Voor wie dat is.',
            'status' => PublishStatus::Published->value,
            'published_at' => $publishedAt,
        ]);

        CovePlan::create([
            'market' => Market::BeNl,
            'kind' => CoveKind::Persona->value,
            'slug' => $slug,
            'title' => Str::headline($slug),
            'status' => 'used',
            'edition_id' => $persona->id,
            'brief' => $relationship === null ? null : ['relationship' => $relationship],
        ]);

        return $persona;
    }

    #[Test]
    public function the_first_question_offers_your_people_and_kinds_of_person(): void
    {
        $user = User::factory()->create();
        Recipient::factory()->create(['owner_user_id' => $user->id, 'name' => 'Mama', 'relationship' => 'mama']);

        $this->actingAs($user)->get('/be-nl/gift')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Gift/Wizard')
                ->where('picks', null)
                ->where('tasteUrl', '/be-nl/gift/taste')
                // The closed vocabulary, in the market's words.
                ->where('options.relationships.1', ['value' => 'mother', 'label' => 'Mama'])
                ->has('options.relationships', 10)
                // "mama" typed on the person is read as the vocabulary, so
                // the Coves for her can come first without asking again.
                ->where('recipients.0.relationshipType', 'mother'));
    }

    #[Test]
    public function the_types_carry_who_their_plan_was_written_for(): void
    {
        $this->persona('de-thuiskok', null, '2026-09-01');
        $this->persona('voor-mama', 'mother', '2026-08-01');

        $this->get('/be-nl/gift')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('personas', 2)
                // Newest first from the server; the page puts Mum's first
                // when Mum was chosen.
                ->where('personas.0.relationship', null)
                ->where('personas.1.relationship', 'mother')
                ->where('personas.1.url', '/be-nl/gift-ideas/voor-mama'));
    }

    #[Test]
    public function this_or_that_carries_a_kind_of_person_and_skips_its_own_who_question(): void
    {
        $this->get('/be-nl/gift/taste?relationship=mother')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Gift/Taste')
                ->where('carried', ['person' => null, 'relationship' => 'mother', 'forMe' => false, 'gender' => null]));

        // Anything outside the vocabulary is not carried.
        $this->get('/be-nl/gift/taste?relationship=grote-baas')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('carried', ['person' => null, 'relationship' => null, 'forMe' => false, 'gender' => null]));
    }

    #[Test]
    public function this_or_that_carries_only_your_own_person(): void
    {
        $user = User::factory()->create();
        $mum = Recipient::factory()->create(['owner_user_id' => $user->id, 'name' => 'Mum', 'relationship' => 'mother']);
        $theirs = Recipient::factory()->create(['owner_user_id' => User::factory()->create()->id, 'name' => 'Theirs']);

        $this->actingAs($user)->get("/be-nl/gift/taste?person={$mum->id}")
            ->assertInertia(fn ($page) => $page
                ->where('carried', ['person' => ['id' => $mum->id, 'name' => 'Mum'], 'relationship' => 'mother', 'forMe' => false, 'gender' => null]));

        $this->actingAs($user)->get("/be-nl/gift/taste?person={$theirs->id}")
            ->assertInertia(fn ($page) => $page->where('carried', ['person' => null, 'relationship' => null, 'forMe' => false, 'gender' => null]));
    }

    #[Test]
    public function this_or_that_ends_on_the_same_results_as_the_questions(): void
    {
        $response = $this->post('/be-nl/gift/taste', [
            'choices' => $this->cookingChoices(),
            'for' => 'someone',
            'relationship' => 'father',
        ])->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Gift/Taste')
            ->where('carried.relationship', 'father')
            // The same cards as the questions' board…
            ->has('result.picks.0.fits')
            ->has('result.picks.0.chosenByOthers')
            // …and the same sections under them.
            ->has('result.offlineIdeas')
            ->has('result.communityCoves')
            ->has('result.pageUrl')
            ->where('result.nextSteps', [])
            ->where('result.askUrl', '/be-nl/ask')
            // What was learned, ready to post to the questions.
            ->where('result.refine.interests', ['cooking'])
            ->where('result.refine.relationship', 'father'));

        $questions = $this->post('/be-nl/gift', ['interests' => ['cooking'], 'relationship' => 'father'])->assertOk();
        $wizard = array_keys($questions->viewData('page')['props']);
        $taste = array_keys($response->viewData('page')['props']['result']);

        // Every section the questions' results have, This or that's have too.
        foreach (['picks', 'pageUrl', 'offlineIdeas', 'communityCoves', 'nextSteps', 'personUrl', 'askUrl'] as $key) {
            $this->assertContains($key, $wizard, "The questions' results lack {$key}.");
            $this->assertContains($key, $taste, "This or that's results lack {$key}.");
        }
    }

    #[Test]
    public function refining_posts_what_was_learned_to_the_questions(): void
    {
        $refine = $this->post('/be-nl/gift/taste', [
            'choices' => $this->cookingChoices(),
            'for' => 'someone',
            'relationship' => 'father',
        ])->viewData('page')['props']['result']['refine'];

        // The questions accept it as it is and answer with a board.
        $this->post('/be-nl/gift', $refine)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Gift/Wizard')
                ->where('brief.interests', ['cooking'])
                ->where('brief.relationship', 'father')
                ->where('picks', fn ($picks) => count($picks) > 0));
    }

    #[Test]
    public function a_saved_person_gets_their_list_and_their_next_steps_from_this_or_that(): void
    {
        $user = User::factory()->create();
        $mum = Recipient::factory()->create(['owner_user_id' => $user->id, 'name' => 'Mum']);

        $this->actingAs($user)->post('/be-nl/gift/taste', [
            'choices' => $this->cookingChoices(),
            'for' => 'someone',
            'recipient_id' => $mum->id,
        ])->assertOk()->assertInertia(fn ($page) => $page
            ->where('carried.person.id', $mum->id)
            ->where('result.personUrl', "/be-nl/people/{$mum->id}")
            ->where('result.refine.recipient_id', $mum->id)
            ->where('result.into.id', fn ($id) => is_string($id)));

        // The save lands on a list for her, made once.
        $this->assertSame(1, Wishlist::query()
            ->where('recipient_id', $mum->id)
            ->where('kind', ListKind::ForSomeone->value)
            ->count());
    }

    #[Test]
    public function the_persons_own_page_gets_only_the_ideas(): void
    {
        $sam = Recipient::factory()->create();

        $this->post("/be-nl/for/{$sam->share_token}/taste", ['choices' => $this->cookingChoices()])
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('mode', 'self')
                ->missing('result.askUrl')
                ->missing('result.refine')
                ->where('result.offlineIdeas', []));
    }

    #[Test]
    public function for_myself_sets_aside_a_person_and_a_relationship(): void
    {
        /*
         * "Voor mezelf" (owner, 2026-09-28). A saved person or a relationship
         * left in the form from before must not steer ideas meant for you.
         */
        $user = User::factory()->create();
        $mum = Recipient::factory()->create(['owner_user_id' => $user->id, 'name' => 'Mum', 'relationship' => 'mother']);

        $this->actingAs($user)
            ->post('/be-nl/gift', [
                'interests' => ['cooking'],
                'relationship' => 'mother',
                'recipient_id' => $mum->id,
                'for_me' => true,
            ])
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Gift/Wizard')
                ->where('brief.for_me', true)
                ->where('personUrl', null)
                ->has('picks'));
    }

    #[Test]
    public function this_or_that_carries_for_myself_and_skips_its_own_who_question(): void
    {
        $this->get('/be-nl/gift/taste?for=me')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('carried', ['person' => null, 'relationship' => null, 'forMe' => true, 'gender' => null]));
    }

    #[Test]
    public function every_results_page_ends_on_ask_others(): void
    {
        $this->post('/be-nl/gift', ['interests' => ['cooking']])
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('askUrl', '/be-nl/ask'));
    }

    #[Test]
    public function who_is_it_for_offers_your_people_as_cards_friends_included(): void
    {
        // Owner, 2026-09-27: "the cards of the people you know / are connected with".
        $me = User::factory()->create();
        $friend = User::factory()->create(['name' => 'Sam']);
        app(Friends::class)->link($me, $friend);
        Recipient::create(['owner_user_id' => $me->id, 'name' => 'Mama', 'relationship' => 'mother']);

        $this->actingAs($me)->get('/be-nl/gift')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Gift/Wizard')
            ->has('people', 2)
            ->where('people', fn ($people) => collect($people)->pluck('name')->sort()->values()->all() === ['Mama', 'Sam']));

        // A visitor without an account has no people rows (the name chips stay).
        $this->app['auth']->forgetGuards();
        $this->get('/be-nl/gift')->assertOk()->assertInertia(fn ($page) => $page->where('people', []));
    }
}

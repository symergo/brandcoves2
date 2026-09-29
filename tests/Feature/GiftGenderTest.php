<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\User;
use App\Models\UserTaste;
use App\Services\Ai\AiClient;
use App\Services\Gift\DeckSeed;
use App\Services\Gift\GiftTags;
use App\Services\Gift\SuggestionEngine;
use App\Services\Gift\TasteBrief;
use App\Services\Gift\TasteCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Voor hem / Voor haar", asked on its own (owner, 2026-09-29), after the
 * gender split of the relations was reverted. A product carries a gender only
 * when it is genuinely for one, and only that product is ever left out. See
 * docs/features/gift-gender.md.
 */
class GiftGenderTest extends TestCase
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
    public function for_her_leaves_out_what_is_tagged_for_him_and_keeps_the_rest(): void
    {
        $forHim = $this->product('Scheerset', ['interest:beauty', 'gender:male']);
        $forHer = $this->product('Make-upset', ['interest:beauty', 'gender:female']);
        $either = $this->product('Badjas', ['interest:beauty']);

        $ids = $this->picks(new TasteBrief(market: Market::BeNl, interests: ['beauty'], gender: 'female', limit: 8));

        $this->assertNotContains($forHim->id, $ids);
        $this->assertContains($forHer->id, $ids);
        $this->assertContains($either->id, $ids, 'no gender tag: it suits both and is never left out');

        // Not asked: nothing is left out.
        $this->assertContains($forHim->id, $this->picks(new TasteBrief(market: Market::BeNl, interests: ['beauty'], limit: 8)));
    }

    #[Test]
    public function mama_is_her_without_being_asked(): void
    {
        $forHim = $this->product('Scheerset', ['interest:beauty', 'gender:male']);

        $brief = new TasteBrief(market: Market::BeNl, interests: ['beauty'], relationship: 'mother', limit: 8);

        $this->assertSame('female', $brief->gender()?->value);
        $this->assertNotContains($forHim->id, $this->picks($brief));
    }

    #[Test]
    public function find_a_gift_remembers_it_on_a_saved_person_and_shows_it_on_their_card(): void
    {
        $user = User::factory()->create();
        $sam = Recipient::factory()->create(['owner_user_id' => $user->id, 'name' => 'Sam', 'relationship' => 'friend']);

        $this->actingAs($user)
            ->post('/be-nl/gift', ['interests' => ['beauty'], 'recipient_id' => $sam->id, 'gender' => 'male'])
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('brief.gender', 'male'));

        $this->assertSame('male', $sam->fresh()->gender);

        $this->actingAs($user)
            ->get('/be-nl/gift')
            ->assertInertia(fn ($page) => $page->where('recipients.0.gender', 'male'));
    }

    #[Test]
    public function the_person_page_keeps_it_like_the_age(): void
    {
        $user = User::factory()->create();
        $sam = Recipient::factory()->create(['owner_user_id' => $user->id, 'name' => 'Sam', 'relationship' => 'friend']);

        $this->actingAs($user)
            ->patch("/be-nl/recipients/{$sam->id}", ['age_band' => '30-49', 'gender' => 'female'])
            ->assertRedirect();

        $this->assertSame('female', $sam->fresh()->gender);

        $this->actingAs($user)
            ->get("/be-nl/people/{$sam->id}")
            ->assertInertia(fn ($page) => $page->where('profile.about.gender', 'female'));

        $this->actingAs($user)
            ->patch("/be-nl/recipients/{$sam->id}", ['gender' => 'other'])
            ->assertSessionHasErrors('gender');
    }

    #[Test]
    public function my_taste_keeps_your_own_and_voor_mezelf_uses_it(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/be-nl/my-taste', ['interests' => ['beauty'], 'gender' => 'male'])->assertRedirect();

        $this->assertSame('male', UserTaste::query()->findOrFail($user->id)->gender);

        $this->actingAs($user)
            ->get('/be-nl/gift')
            ->assertInertia(fn ($page) => $page->where('myTaste.gender', 'male'));
    }

    #[Test]
    public function rather_not_say_is_remembered_and_leaves_nothing_out(): void
    {
        $forHim = $this->product('Scheerset', ['interest:beauty', 'gender:male']);
        $user = User::factory()->create();
        $sam = Recipient::factory()->create(['owner_user_id' => $user->id, 'name' => 'Sam', 'relationship' => 'friend']);

        $this->actingAs($user)
            ->post('/be-nl/gift', ['interests' => ['beauty'], 'recipient_id' => $sam->id, 'gender' => 'unsaid'])
            ->assertOk();

        $this->assertSame('unsaid', $sam->fresh()->gender);

        // Nothing left out, and "rather not say" is not overruled by a relation that implies one.
        $this->assertContains($forHim->id, $this->picks(new TasteBrief(market: Market::BeNl, interests: ['beauty'], gender: 'unsaid', limit: 8)));
        $this->assertNull((new TasteBrief(market: Market::BeNl, relationship: 'mother', gender: 'unsaid'))->gender());
        $this->assertTrue((new DeckSeed(gender: 'unsaid'))->allows(new TasteCard(1, ['gender:male'], [], 3000)));
    }

    #[Test]
    public function rather_not_say_is_never_a_product_tag(): void
    {
        $this->assertSame(['male', 'female'], GiftTags::vocabulary()['gender']);
    }

    #[Test]
    public function an_unknown_gender_is_refused(): void
    {
        $this->post('/be-nl/gift', ['interests' => ['beauty'], 'gender' => 'other'])->assertSessionHasErrors('gender');
    }

    #[Test]
    public function the_games_leave_out_a_card_tagged_for_the_other_one(): void
    {
        $seed = new DeckSeed(gender: 'female');

        $this->assertFalse($seed->allows(new TasteCard(1, ['interest:beauty', 'gender:male'], [], 3000)));
        $this->assertTrue($seed->allows(new TasteCard(2, ['interest:beauty', 'gender:female'], [], 3000)));
        $this->assertTrue($seed->allows(new TasteCard(3, ['interest:beauty'], [], 3000)));
    }

    #[Test]
    public function swiping_carries_it_from_find_a_gift(): void
    {
        $this->get('/be-nl/gift/swipe?relationship=friend&gender=female')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('carried.gender', 'female'));
    }

    /** @return list<int> */
    private function picks(TasteBrief $brief): array
    {
        return array_map(fn ($s) => $s->group->id, app(SuggestionEngine::class)->suggest($brief));
    }

    /** @param list<string> $tags */
    private function product(string $title, array $tags): ProductGroup
    {
        return ProductGroup::factory()->forMarket(Market::BeNl)->priced(3000)->create([
            'gift_tags' => $tags,
            'title' => $title.' '.Str::random(4),
        ]);
    }
}

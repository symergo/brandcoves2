<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Models\GiftProfileCard;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\User;
use App\Services\Ai\AiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "My gift profile": made after This or that about yourself, opened as the
 * Gift Finder filled in. See docs/features/gift-profile-card.md.
 */
class GiftProfileCardTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, list<ProductGroup>> */
    private array $byInterest = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(AiClient::class, function ($mock): void {
            $mock->shouldNotReceive('json');
            $mock->shouldNotReceive('chat');
        });

        foreach (['cooking', 'coffee', 'gaming', 'music', 'reading'] as $interest) {
            foreach ([2500, 4000, 6000] as $price) {
                $this->byInterest[$interest][] = ProductGroup::factory()->forMarket(Market::BeNl)->priced($price)->create([
                    'gift_tags' => ["interest:{$interest}"],
                    'title' => 'Product '.Str::random(6),
                ]);
            }
        }
    }

    /**
     * Cooking picked three times, and gaming disliked twice.
     *
     * @return list<array<string, mixed>>
     */
    private function choices(): array
    {
        $choices = [];

        foreach (['music', 'reading', 'coffee'] as $i => $other) {
            $cooking = $this->byInterest['cooking'][$i];
            $choices[] = ['shown' => [$cooking->id, $this->byInterest[$other][$i]->id], 'picked' => $cooking->id];
        }

        $choices[] = ['shown' => [$this->byInterest['gaming'][0]->id], 'verdict' => 'dislike'];
        $choices[] = ['shown' => [$this->byInterest['gaming'][1]->id], 'verdict' => 'dislike'];

        return $choices;
    }

    private function make(array $extra = []): array
    {
        return $this->postJson('/be-nl/gift/card', ['choices' => $this->choices(), ...$extra])
            ->assertOk()
            ->json();
    }

    #[Test]
    public function the_self_pages_offer_a_card(): void
    {
        $this->get('/be-nl/gift/taste')
            ->assertInertia(fn ($page) => $page->where('urls.card', '/be-nl/gift/card'));

        $sam = Recipient::factory()->create();

        $this->get("/be-nl/for/{$sam->share_token}/taste")
            ->assertInertia(fn ($page) => $page->where('urls.card', '/be-nl/gift/card'));
    }

    #[Test]
    public function a_card_keeps_the_profile_and_never_the_choices(): void
    {
        $made = $this->make();

        $card = GiftProfileCard::query()->sole();
        $this->assertNull($card->name);
        $this->assertSame(['cooking'], $card->profile['interests']);
        $this->assertSame(['gaming'], $card->profile['avoid']);
        $this->assertNotNull($card->profile['budgetMin']);
        // jsonb keeps its own key order, so compared as a set.
        $this->assertEqualsCanonicalizing(
            ['interests', 'avoid', 'budgetMin', 'budgetMax', 'vibe', 'preferences', 'values'],
            array_keys($card->profile),
        );
        $this->assertStringNotContainsString((string) $this->byInterest['cooking'][0]->id, json_encode($card->profile));

        $this->assertSame(url("/be-nl/gift/card/{$card->token}"), $made['url']);
        $this->assertStringContainsString('€', $made['summary']);
    }

    #[Test]
    public function the_profile_is_worked_out_here_not_taken_from_the_request(): void
    {
        $this->postJson('/be-nl/gift/card', [
            'choices' => $this->choices(),
            'profile' => ['interests' => ['gaming']],
            'interests' => ['gaming'],
        ])->assertOk();

        $this->assertSame(['cooking'], GiftProfileCard::query()->sole()->profile['interests']);
    }

    #[Test]
    public function nothing_learned_makes_no_card(): void
    {
        $this->postJson('/be-nl/gift/card', [
            'choices' => [['shown' => [$this->byInterest['music'][0]->id, $this->byInterest['coffee'][0]->id]]],
        ])->assertStatus(422);

        $this->assertSame(0, GiftProfileCard::query()->count());
    }

    #[Test]
    public function opening_the_card_seeds_the_gift_finder(): void
    {
        $this->make(['name' => 'Emma']);
        $card = GiftProfileCard::query()->sole();

        // Somebody else, no account, no session of the maker's.
        $this->flushSession();

        $response = $this->get("/be-nl/gift/card/{$card->token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Gift/Wizard')
                ->where('brief.interests', ['cooking'])
                ->where('brief.avoid', ['interest:gaming'])
                ->where('brief.budget_max', intdiv($card->profile['budgetMax'], 100))
                ->where('recipients', [])
                ->where('picks', null)
                ->where('card.title', __('site.gift.card.title_named', ['name' => 'Emma']))
                ->where('card.makeOwn', '/be-nl/gift/taste')
                // Only the maker may take it down.
                ->where('card.remove', null));

        $this->assertStringContainsString('noindex, nofollow', $response->getContent());
    }

    #[Test]
    public function no_name_unless_one_was_typed(): void
    {
        $this->make();
        $card = GiftProfileCard::query()->sole();

        $this->get("/be-nl/gift/card/{$card->token}")
            ->assertInertia(fn ($page) => $page->where('card.title', __('site.gift.card.title_anonymous')));
    }

    #[Test]
    public function the_maker_removes_it_with_their_key(): void
    {
        $made = $this->make();
        $card = GiftProfileCard::query()->sole();

        $this->flushSession();

        // A stranger, with a wrong key, cannot; and cannot tell the card exists.
        $this->deleteJson($made['remove'], ['key' => 'wrong'])->assertNotFound();
        $this->assertSame(1, GiftProfileCard::query()->count());

        $this->deleteJson($made['remove'], ['key' => $made['key']])->assertOk();
        $this->assertSame(0, GiftProfileCard::query()->count());

        $this->get("/be-nl/gift/card/{$card->token}")->assertNotFound();
    }

    #[Test]
    public function the_maker_is_recognised_in_the_same_browser_and_by_account(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->make();
        $card = GiftProfileCard::query()->sole();

        $this->get("/be-nl/gift/card/{$card->token}")
            ->assertInertia(fn ($page) => $page->where('card.remove', "/be-nl/gift/card/{$card->token}"));

        // Signed in, the account is enough even without the session's key.
        $this->flushSession();
        $this->actingAs($user)->deleteJson("/be-nl/gift/card/{$card->token}")->assertOk();
        $this->assertSame(0, GiftProfileCard::query()->count());
    }

    #[Test]
    public function the_card_leaks_neither_its_key_nor_its_owner(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/be-nl/gift/card', ['choices' => $this->choices()])->assertOk();
        $card = GiftProfileCard::query()->sole();

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $html = $this->get("/be-nl/gift/card/{$card->token}")->getContent();
        $this->assertStringNotContainsString($card->owner_key_hash, $html);
        $this->assertStringNotContainsString($user->email, $html);
    }

    #[Test]
    public function a_card_nobody_opened_for_a_year_is_pruned_and_an_opened_one_stays(): void
    {
        $this->make();
        $this->make();
        [$kept, $old] = GiftProfileCard::query()->orderBy('id')->get()->all();

        $this->travel(200)->days();
        $this->get("/be-nl/gift/card/{$kept->token}")->assertOk();

        $this->travel(200)->days();
        $this->artisan('bc:prune-personal-data')->assertSuccessful();

        $this->assertNotNull($kept->fresh());
        $this->assertNull($old->fresh());
    }
}

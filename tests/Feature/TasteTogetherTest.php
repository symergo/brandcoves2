<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\ListVisibility;
use App\Enums\Market;
use App\Enums\TasteSource;
use App\Http\Middleware\TrackAnonymousIdentity;
use App\Models\AnonymousIdentity;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\TasteInvite;
use App\Models\TasteRun;
use App\Models\User;
use App\Models\Wishlist;
use App\Services\Ai\AiClient;
use App\Services\Gift\TasteTogether;
use App\Services\Wishlist\ListBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * This or that together: a giver's link, several anonymous players, one
 * combined profile the giver can add to the person. See
 * docs/features/taste-together.md.
 */
class TasteTogetherTest extends TestCase
{
    use RefreshDatabase;

    private const INTERESTS = ['cooking', 'coffee', 'gaming', 'music', 'reading', 'gardening'];

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
                $this->byInterest[$interest][] = ProductGroup::factory()->forMarket(Market::BeNl)->priced($price)->create([
                    'gift_tags' => ["interest:{$interest}"],
                    'title' => 'Product '.Str::random(6),
                ]);
            }
        }
    }

    /** A giver, their person, and the group list for that person. */
    private function giver(string $name = 'Dad', array $recipient = []): array
    {
        $user = User::factory()->create();
        $person = Recipient::factory()->create(['owner_user_id' => $user->id, 'name' => $name, ...$recipient]);
        $list = Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'recipient_id' => $person->id,
            'kind' => ListKind::Group,
            'market' => Market::BeNl,
            'visibility' => ListVisibility::Link,
        ]);

        return [$user, $person, $list];
    }

    private function openLink(User $user, Recipient $person): TasteInvite
    {
        $this->actingAs($user)
            ->post("/be-nl/recipients/{$person->id}/taste-together")
            ->assertRedirect();

        return TasteInvite::query()->where('recipient_id', $person->id)->sole();
    }

    /**
     * One pick of $win over $lose, per index given.
     *
     * @param  list<array{0: string, 1: string, 2: int}>  $rounds
     * @return list<array<string, mixed>>
     */
    private function picks(array $rounds): array
    {
        return array_map(function (array $round) {
            [$win, $lose, $i] = $round;
            $winner = $this->byInterest[$win][$i];

            return ['shown' => [$winner->id, $this->byInterest[$lose][$i]->id], 'picked' => $winner->id];
        }, $rounds);
    }

    /** Play as a fresh anonymous visitor. */
    private function playAsSomebody(TasteInvite $invite, array $choices)
    {
        $visitor = AnonymousIdentity::create(['last_seen_at' => now()]);

        // A guest, whatever the previous request in this test was signed in as.
        $this->app['auth']->forgetGuards();

        return $this->withCookie(TrackAnonymousIdentity::COOKIE, (string) $visitor->getKey())
            ->post("/be-nl/t/{$invite->token}", ['choices' => $choices]);
    }

    #[Test]
    public function the_group_list_offers_its_owner_a_link_for_the_person(): void
    {
        [$user, $person, $list] = $this->giver();

        $this->actingAs($user)->get("/be-nl/lists/{$list->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tasteTogether.url', null)
                ->where('tasteTogether.players', 0)
                ->where('tasteTogether.profile', null));

        $invite = $this->openLink($user, $person);

        // A second press hands back the same link rather than splitting the answers.
        $this->actingAs($user)->post("/be-nl/recipients/{$person->id}/taste-together");
        $this->assertSame(1, TasteInvite::query()->count());

        $this->actingAs($user)->get("/be-nl/lists/{$list->id}")
            ->assertInertia(fn ($page) => $page
                ->where('tasteTogether.url', url("/be-nl/t/{$invite->token}"))
                ->where('tasteTogether.open', true));
    }

    #[Test]
    public function somebody_elses_person_gets_no_link(): void
    {
        [, $person] = $this->giver();

        $this->actingAs(User::factory()->create())
            ->post("/be-nl/recipients/{$person->id}/taste-together")
            ->assertNotFound();

        $this->assertSame(0, TasteInvite::query()->count());
    }

    #[Test]
    public function a_player_needs_no_account_and_sees_the_name_and_nothing_else(): void
    {
        [$user, $person] = $this->giver('Dad', ['notes' => 'Surprise party on the 12th', 'interests' => ['secret origami']]);
        $invite = $this->openLink($user, $person);

        $this->app['auth']->forgetGuards();

        $response = $this->get("/be-nl/t/{$invite->token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Gift/Taste')
                ->where('mode', 'together')
                ->where('person', ['name' => 'Dad'])
                ->where('recipients', [])
                ->where('full', false)
                ->has('rounds', 4));

        $html = $response->getContent();
        $this->assertStringNotContainsString('Surprise party', $html);
        $this->assertStringNotContainsString('secret origami', $html);
        $this->assertStringContainsString('noindex, nofollow', $html);
    }

    #[Test]
    public function runs_from_several_people_combine_into_one_profile(): void
    {
        [$user, $person, $list] = $this->giver();
        $invite = $this->openLink($user, $person);

        // Each player picked cooking once: alone, a thin reading; together, two good rounds.
        $this->playAsSomebody($invite, $this->picks([['cooking', 'gaming', 0], ['music', 'reading', 0]]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('result.recorded', true));

        $this->playAsSomebody($invite, $this->picks([['cooking', 'gardening', 1], ['coffee', 'reading', 1]]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('result.recorded', true)
                // The player sees their own reading, never the combined one.
                ->where('result.profile.answered', 2));

        $this->assertSame(2, TasteRun::query()->count());

        $combined = app(TasteTogether::class)->combined($invite->fresh());
        $this->assertSame(['cooking'], $combined->interests);
        $this->assertSame(4, $combined->answered);

        $this->actingAs($user)->get("/be-nl/lists/{$list->id}")
            ->assertInertia(fn ($page) => $page
                ->where('tasteTogether.players', 2)
                ->where('tasteTogether.profile.interests', ['cooking']));
    }

    #[Test]
    public function playing_again_replaces_your_run_rather_than_counting_twice(): void
    {
        [$user, $person] = $this->giver();
        $invite = $this->openLink($user, $person);
        $visitor = AnonymousIdentity::create(['last_seen_at' => now()]);
        $this->app['auth']->forgetGuards();

        foreach ([['cooking', 'gaming', 0], ['music', 'reading', 1]] as $round) {
            $this->withCookie(TrackAnonymousIdentity::COOKIE, (string) $visitor->getKey())
                ->post("/be-nl/t/{$invite->token}", ['choices' => $this->picks([$round])])
                ->assertOk();
        }

        $run = TasteRun::query()->sole();
        $this->assertSame($this->byInterest['music'][1]->id, $run->choices[0]['picked']);
    }

    #[Test]
    public function nothing_about_the_players_reaches_the_giver(): void
    {
        [$user, $person, $list] = $this->giver();
        $invite = $this->openLink($user, $person);

        $this->playAsSomebody($invite, $this->picks([['cooking', 'gaming', 0], ['cooking', 'music', 1]]));

        $run = TasteRun::query()->sole();
        $this->assertArrayNotHasKey('participant_hash', $run->toArray());

        $props = $this->actingAs($user)->get("/be-nl/lists/{$list->id}")->viewData('page')['props']['tasteTogether'];

        // A count and a combined profile; no runs, no hashes, no times.
        $this->assertSame(['url', 'open', 'players', 'max', 'profile', 'thin', 'applied', 'urls'], array_keys($props));
        $this->assertStringNotContainsString($run->participant_hash, json_encode($props));
        $this->assertArrayNotHasKey('scores', $props['profile']);
    }

    #[Test]
    public function applying_adds_to_the_person_and_keeps_what_was_there(): void
    {
        [$user, $person] = $this->giver('Dad', ['interests' => ['reading']]);
        $invite = $this->openLink($user, $person);

        $this->playAsSomebody($invite, $this->picks([['cooking', 'gaming', 0], ['coffee', 'music', 0]]));
        $this->playAsSomebody($invite, $this->picks([['cooking', 'gardening', 1], ['coffee', 'music', 2]]));

        $this->actingAs($user)
            ->post("/be-nl/recipients/{$person->id}/taste-together/apply")
            ->assertRedirect()
            ->assertSessionHas('success', __('site.gift.taste.saved', ['name' => 'Dad']));

        $person->refresh();
        $this->assertSame(['coffee', 'cooking', 'reading'], $person->interests);
        $this->assertSame(TasteSource::Suggested, $person->taste_source);
        $this->assertNotNull(app(ListBudget::class)->forRecipient($person)['min']);
        $this->assertNotNull($invite->fresh()->applied_at);
    }

    #[Test]
    public function what_the_person_said_about_themselves_is_never_overwritten(): void
    {
        [$user, $person] = $this->giver('Mum', ['interests' => ['gardening'], 'taste_source' => TasteSource::Self]);
        $invite = $this->openLink($user, $person);

        $this->playAsSomebody($invite, $this->picks([['cooking', 'gaming', 0], ['cooking', 'music', 1], ['coffee', 'reading', 2]]));

        $this->actingAs($user)
            ->post("/be-nl/recipients/{$person->id}/taste-together/apply")
            ->assertSessionHas('success', __('site.gift.taste.saved_theirs', ['name' => 'Mum']));

        $person->refresh();
        $this->assertSame(['gardening'], $person->interests);
        $this->assertSame(TasteSource::Self, $person->taste_source);
        // What the group will spend is the giver's fact, not their taste.
        $this->assertNotNull(app(ListBudget::class)->forRecipient($person)['min']);
    }

    #[Test]
    public function a_player_never_writes_to_the_person(): void
    {
        [$user, $person] = $this->giver('Dad', ['interests' => ['reading']]);
        $invite = $this->openLink($user, $person);

        $this->playAsSomebody($invite, $this->picks([['cooking', 'gaming', 0], ['cooking', 'music', 1]]));

        $this->assertSame(['reading'], $person->fresh()->interests);
    }

    #[Test]
    public function a_stopped_link_is_gone_and_a_new_one_starts_afresh(): void
    {
        [$user, $person, $list] = $this->giver();
        $invite = $this->openLink($user, $person);
        $this->playAsSomebody($invite, $this->picks([['cooking', 'gaming', 0]]));

        $this->actingAs($user)
            ->delete("/be-nl/recipients/{$person->id}/taste-together")
            ->assertRedirect();

        $this->app['auth']->forgetGuards();
        $this->get("/be-nl/t/{$invite->token}")->assertNotFound();
        $this->post("/be-nl/t/{$invite->token}", ['choices' => $this->picks([['cooking', 'gaming', 1]])])->assertNotFound();

        // The giver still sees what was chosen before the link stopped.
        $this->actingAs($user)->get("/be-nl/lists/{$list->id}")
            ->assertInertia(fn ($page) => $page
                ->where('tasteTogether.open', false)
                ->where('tasteTogether.url', null)
                ->where('tasteTogether.players', 1));

        $this->actingAs($user)->post("/be-nl/recipients/{$person->id}/taste-together")->assertRedirect();

        $fresh = TasteInvite::query()->where('recipient_id', $person->id)->latest('id')->first();
        $this->assertNotSame($invite->token, $fresh->token);

        $this->actingAs($user)->get("/be-nl/lists/{$list->id}")
            ->assertInertia(fn ($page) => $page
                ->where('tasteTogether.open', true)
                ->where('tasteTogether.players', 0));
    }

    #[Test]
    public function a_full_link_takes_no_more_players(): void
    {
        [$user, $person] = $this->giver();
        $invite = $this->openLink($user, $person);

        for ($i = 0; $i < TasteTogether::MAX_PARTICIPANTS; $i++) {
            TasteRun::create([
                'taste_invite_id' => $invite->id,
                'participant_hash' => hash('sha256', (string) $i),
                'choices' => [],
                'answered' => 1,
            ]);
        }

        $this->app['auth']->forgetGuards();

        $this->get("/be-nl/t/{$invite->token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('full', true)->where('rounds', []));

        $this->playAsSomebody($invite, $this->picks([['cooking', 'gaming', 0]]))
            ->assertInertia(fn ($page) => $page->where('result.recorded', false));

        $this->assertSame(TasteTogether::MAX_PARTICIPANTS, TasteRun::query()->count());
    }

    #[Test]
    public function an_unknown_link_is_not_found(): void
    {
        $this->get('/be-nl/t/zzzzzzzzzz')->assertNotFound();
    }

    #[Test]
    public function old_runs_and_then_their_empty_links_are_pruned(): void
    {
        [$user, $person] = $this->giver();
        $invite = $this->openLink($user, $person);
        $this->playAsSomebody($invite, $this->picks([['cooking', 'gaming', 0]]));

        $this->travel(181)->days();
        $this->artisan('bc:prune-personal-data')->assertSuccessful();

        $this->assertSame(0, TasteRun::query()->count());
        $this->assertSame(0, TasteInvite::query()->count());
    }
}

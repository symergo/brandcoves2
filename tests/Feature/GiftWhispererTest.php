<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\ListKind;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Enums\TasteSource;
use App\Models\Event;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use App\Services\Gift\SuggestionEngine;
use App\Services\Gift\TasteBrief;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Show me something else", and the promise attached to it.
 *
 * `gift_cove.whisperer_step2` says *"what you rejected is never offered
 * again"*. Two separate defects meant it did not hold: the swap rendered a
 * single card instead of a board, and the rejected list lived in component
 * state that the swap's own response destroyed.
 *
 * {@see a_rejection_survives_the_round_trip} is the bug as it actually
 * appeared. It is the one that a client-side fix would not have caught,
 * because it only shows up on the *second* swap.
 */
class GiftWhispererTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::create([
            'source' => Source::Awin->value,
            'external_id' => 'shop',
            'name' => 'Shop',
        ]);
    }

    /**
     * Enough stock that a board of four can be refilled several times.
     *
     * An offer per group, because retrieval matches on `products.search_vector`
     * — the generated column lives on the offer, not on the group, so a group
     * with nobody selling it is correctly invisible to the engine.
     */
    private function catalogue(int $count = 20): void
    {
        for ($i = 0; $i < $count; $i++) {
            $price = 2000 + $i * 100;
            $title = "Koffiemolen {$i} voor koffie";

            $group = ProductGroup::create([
                'market' => Market::BeNl,
                'identity_key' => 'k'.bin2hex(random_bytes(5)),
                'identity_kind' => 'ean',
                'title' => $title,
                'slug' => 'p-'.bin2hex(random_bytes(3)),
                'category' => 'Koffie',
                'image_url' => 'https://img.test/x.jpg',
                'min_price' => $price,
                'merchant_count' => 1,
                'in_stock' => true,
                'giftable' => true,
            ]);

            Product::create([
                'source' => Source::Awin,
                'market' => Market::BeNl,
                'merchant_id' => $this->merchant->id,
                'group_id' => $group->id,
                'external_id' => 'e'.bin2hex(random_bytes(5)),
                'title' => $title,
                'merchant_category' => 'Koffie',
                'price' => $price,
                'currency' => 'EUR',
                'affiliate_url' => 'https://example.test/buy',
                'availability' => Availability::InStock,
                'status' => ProductStatus::Active,
                'identity_key' => $group->identity_key,
            ]);
        }
    }

    /** @return list<int> */
    private function pickIds(TestResponse $response): array
    {
        $picks = $response->viewData('page')['props']['picks'] ?? [];

        return array_column($picks, 'id');
    }

    /** @return array<string, mixed> */
    private function brief(): array
    {
        return ['interests' => ['coffee'], 'budget_max' => 100];
    }

    #[Test]
    public function a_brief_returns_a_board_of_four(): void
    {
        $this->catalogue();

        $ids = $this->pickIds($this->post('/be-nl/gift', $this->brief())->assertOk());

        $this->assertCount(4, $ids);
    }

    #[Test]
    public function a_swap_returns_a_full_board_not_one_card(): void
    {
        /*
         * The grid used to collapse to a single card: `swap()` scored with
         * `withLimit(1)` and rendered that one pick, so the three the visitor
         * had kept were thrown away by the render rather than by the ranker.
         */
        $this->catalogue();

        $first = $this->pickIds($this->post('/be-nl/gift', $this->brief())->assertOk());

        $after = $this->pickIds(
            $this->post('/be-nl/gift/swap', [...$this->brief(), 'rejected' => $first[0]])->assertOk()
        );

        $this->assertCount(4, $after);
    }

    #[Test]
    public function a_rejected_pick_is_not_offered_again(): void
    {
        $this->catalogue();

        $first = $this->pickIds($this->post('/be-nl/gift', $this->brief())->assertOk());
        $rejected = $first[0];

        $after = $this->pickIds(
            $this->post('/be-nl/gift/swap', [...$this->brief(), 'rejected' => $rejected])->assertOk()
        );

        $this->assertNotContains($rejected, $after);
    }

    #[Test]
    public function a_rejection_survives_the_round_trip(): void
    {
        /*
         * The actual bug, and the reason the memory is server-side.
         *
         * The client accumulated rejections in component state and posted them
         * back — but the swap response rebuilt the component, so the list was
         * empty again by the next swap. The first rejection could therefore
         * reappear on the second one, and a `preserveState` fix would still
         * have lost it on a reload or a back-navigation.
         */
        $this->catalogue();

        $first = $this->pickIds($this->post('/be-nl/gift', $this->brief())->assertOk());
        $rejectedFirst = $first[0];

        $second = $this->pickIds(
            $this->post('/be-nl/gift/swap', [...$this->brief(), 'rejected' => $rejectedFirst])->assertOk()
        );

        $third = $this->pickIds(
            $this->post('/be-nl/gift/swap', [...$this->brief(), 'rejected' => $second[0]])->assertOk()
        );

        $this->assertNotContains($rejectedFirst, $third, 'the first rejection came back');
        $this->assertNotContains($second[0], $third);
    }

    #[Test]
    public function a_plain_suggest_still_honours_earlier_rejections(): void
    {
        // "Try again" used to re-post the same brief and re-render the same four
        // cards, which is not what the button says.
        $this->catalogue();

        $first = $this->pickIds($this->post('/be-nl/gift', $this->brief())->assertOk());

        $this->post('/be-nl/gift/swap', [...$this->brief(), 'rejected' => $first[0]])->assertOk();

        $again = $this->pickIds($this->post('/be-nl/gift', $this->brief())->assertOk());

        $this->assertNotContains($first[0], $again);
    }

    #[Test]
    public function rejecting_under_one_brief_does_not_narrow_another(): void
    {
        /*
         * Describing your mother and then a colleague must not have one poison
         * the other — different questions, different right answers. The bucket
         * arithmetic is unit-tested in {@see \Tests\Unit\RejectionMemoryTest};
         * this asserts the two briefs really do reach different buckets through
         * the endpoint, by rejecting almost everything under one of them and
         * checking the other is untouched.
         */
        $this->catalogue();

        $mother = ['interests' => ['coffee'], 'budget_max' => 100];
        $colleague = ['interests' => ['coffee'], 'budget_max' => 100, 'vibe' => 'playful'];

        $before = $this->pickIds($this->post('/be-nl/gift', $colleague)->assertOk());

        // Six swaps under the first brief, throwing away a couple of dozen ids.
        $picks = $this->pickIds($this->post('/be-nl/gift', $mother)->assertOk());

        for ($i = 0; $i < 6; $i++) {
            $picks = $this->pickIds(
                $this->post('/be-nl/gift/swap', [...$mother, 'rejected' => $picks[0]])->assertOk()
            );
        }

        // The other brief still gets exactly what it got before any of that.
        $after = $this->pickIds($this->post('/be-nl/gift', $colleague)->assertOk());

        $this->assertSame($before, $after);
    }

    #[Test]
    public function starting_over_forgets_everything(): void
    {
        // Opening the wizard is starting over, and the button says so.
        $this->catalogue();

        $first = $this->pickIds($this->post('/be-nl/gift', $this->brief())->assertOk());

        $this->post('/be-nl/gift/swap', [...$this->brief(), 'rejected' => $first[0]])->assertOk();

        $this->get('/be-nl/gift')->assertOk();

        $fresh = $this->pickIds($this->post('/be-nl/gift', $this->brief())->assertOk());

        $this->assertSame($first, $fresh);
    }

    /*
    |--------------------------------------------------------------------------
    | "Four more", and the invariant behind it
    |--------------------------------------------------------------------------
    |
    | Every action renders `suggest(brief minus memory)`. The board on screen
    | is therefore always recomputable server-side, and "Four more" is exactly
    | "remember what is on screen, then suggest again". The catalogue here has
    | one category and identical title tokens, so every pair scores similarity
    | 1.0 in the diversifier and the ranking is the plain score order — which
    | makes "top four minus the rejected one" checkable to the id.
    */

    #[Test]
    public function four_more_returns_a_board_you_have_not_seen(): void
    {
        $this->catalogue();

        $first = $this->pickIds($this->post('/be-nl/gift', $this->brief())->assertOk());
        $next = $this->pickIds($this->post('/be-nl/gift/more', $this->brief())->assertOk());

        $this->assertCount(4, $next);
        $this->assertSame([], array_intersect($first, $next));
    }

    #[Test]
    public function four_more_after_a_swap_does_not_skip_a_board(): void
    {
        /*
         * The oracle for the invariant. If a swap remembered the board it
         * returned (as it did until 2026-09-13), the recompute in `more()`
         * would already be the *next* board, and pressing the button would
         * skip four cards the visitor never saw.
         */
        $this->catalogue();

        $first = $this->pickIds($this->post('/be-nl/gift', $this->brief())->assertOk());
        $second = $this->pickIds(
            $this->post('/be-nl/gift/swap', [...$this->brief(), 'rejected' => $first[0]])->assertOk()
        );
        $third = $this->pickIds($this->post('/be-nl/gift/more', $this->brief())->assertOk());

        $expected = app(SuggestionEngine::class)->suggest(
            (new TasteBrief(market: Market::BeNl, interests: ['coffee'], budgetMax: 10000))
                ->excluding([$first[0], ...$second])
        );

        $this->assertSame(array_map(fn ($p) => $p->group->id, $expected), $third);
    }

    #[Test]
    public function a_second_swap_keeps_the_three_you_did_not_reject(): void
    {
        // Failed before the swap stopped remembering its own board: the second
        // swap replaced all four cards instead of the one rejected.
        $this->catalogue();

        $first = $this->pickIds($this->post('/be-nl/gift', $this->brief())->assertOk());
        $second = $this->pickIds(
            $this->post('/be-nl/gift/swap', [...$this->brief(), 'rejected' => $first[0]])->assertOk()
        );
        $third = $this->pickIds(
            $this->post('/be-nl/gift/swap', [...$this->brief(), 'rejected' => $second[0]])->assertOk()
        );

        $kept = array_slice($second, 1);

        $this->assertSame([], array_diff($kept, $third), 'a card the visitor kept was replaced');
        $this->assertNotContains($second[0], $third);
    }

    #[Test]
    public function four_more_is_recorded(): void
    {
        $this->catalogue();

        $this->post('/be-nl/gift/more', $this->brief())->assertOk();

        $this->assertTrue(Event::query()->where('kind', 'gift.more')->exists());
    }

    /*
    |--------------------------------------------------------------------------
    | A saved person
    |--------------------------------------------------------------------------
    |
    | Every catalogue title contains "koffiemolen", so a stored avoid word of
    | exactly that empties the board — a clean probe for "was the profile
    | applied".
    */

    /** @return array{User, Recipient} */
    private function mother(array $attributes = []): array
    {
        $user = User::factory()->create();
        $recipient = Recipient::factory()->create([
            'owner_user_id' => $user->id,
            'name' => 'Mum',
            ...$attributes,
        ]);

        return [$user, $recipient];
    }

    #[Test]
    public function the_age_is_one_of_the_fixed_groups_or_nothing(): void
    {
        $this->catalogue();

        $this->post('/be-nl/gift', [...$this->brief(), 'age_band' => '13-17'])->assertOk();
        $this->post('/be-nl/gift', [...$this->brief(), 'age_band' => 'teen'])->assertSessionHasErrors('age_band');
    }

    #[Test]
    public function a_saved_person_fills_in_what_the_brief_leaves_out(): void
    {
        $this->catalogue();
        [$user, $mum] = $this->mother(['avoid' => ['koffiemolen']]);

        // No `avoid` key posted at all, so the stored one applies.
        $ids = $this->pickIds(
            $this->actingAs($user)
                ->postJson('/be-nl/gift', ['interests' => ['coffee'], 'recipient_id' => $mum->id])
                ->assertOk()
        );

        $this->assertSame([], $ids);
    }

    #[Test]
    public function a_cleared_answer_beats_the_stored_one(): void
    {
        /*
         * The `+=` overlay in `GiftController::brief()`: a posted key wins even
         * when it is empty. Without this, clearing "avoid" in the wizard would
         * silently put the stored words back.
         */
        $this->catalogue();
        [$user, $mum] = $this->mother(['avoid' => ['koffiemolen']]);

        $ids = $this->pickIds(
            $this->actingAs($user)
                ->postJson('/be-nl/gift', ['interests' => ['coffee'], 'avoid' => [], 'recipient_id' => $mum->id])
                ->assertOk()
        );

        $this->assertCount(4, $ids);
    }

    #[Test]
    public function somebody_elses_person_is_not_used(): void
    {
        $this->catalogue();
        [, $theirMum] = $this->mother(['avoid' => ['koffiemolen']]);
        $me = User::factory()->create();

        $response = $this->actingAs($me)
            ->postJson('/be-nl/gift', ['interests' => ['coffee'], 'recipient_id' => $theirMum->id])
            ->assertOk();

        // A guessed uuid attaches nothing: not their avoid words, not their list.
        $this->assertCount(4, $this->pickIds($response));
        $this->assertNull($response->viewData('page')['props']['recipientList']);
    }

    #[Test]
    public function remembering_is_opt_in(): void
    {
        $this->catalogue();
        [$user, $mum] = $this->mother();
        $before = $mum->fresh()->updated_at;

        $this->travel(1)->minutes();

        $this->actingAs($user)
            ->post('/be-nl/gift', [...$this->brief(), 'recipient_id' => $mum->id])
            ->assertOk();

        $mum->refresh();

        // Not a field written, not even a touch: the row is as it was.
        $this->assertSame([], $mum->interests);
        $this->assertNull($mum->budget_max);
        $this->assertTrue($before->equalTo($mum->updated_at));
    }

    /**
     * The taste question, added 2026-09-14: which way a person's taste goes,
     * which the vibe cannot say. Asked as pairs of opposites; several may be
     * chosen, only the twelve poles are accepted, and the answer rides the
     * brief back to the page.
     */
    #[Test]
    public function the_taste_is_several_of_the_offered_poles_or_nothing(): void
    {
        $this->catalogue();

        $this->post('/be-nl/gift', [...$this->brief(), 'preferences' => ['vintage', 'cosy']])
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('brief.preferences', ['vintage', 'cosy'])
                // The wizard draws the axes, so the page is given them as
                // axes, the owner's headline pair first.
                ->where('options.preferences.0.axis', 'purpose')
                ->where('options.preferences.0.poles.0.value', 'practical')
                ->where('options.preferences.0.poles.1.value', 'design')
                ->where('options.preferences.1.poles.1.value', 'vintage'));

        $this->post('/be-nl/gift', [...$this->brief(), 'preferences' => ['edgy']])
            ->assertSessionHasErrors('preferences.0');
    }

    #[Test]
    public function remembering_writes_the_answers_onto_the_person(): void
    {
        $this->catalogue();
        [$user, $mum] = $this->mother();

        $this->actingAs($user)
            ->post('/be-nl/gift', [
                'interests' => ['coffee', 'wielrennen'],
                'vibe' => 'playful',
                'preferences' => ['vintage'],
                'budget_max' => 60,
                'recipient_id' => $mum->id,
                'remember' => true,
            ])
            ->assertOk();

        $mum->refresh();

        $this->assertSame(['coffee', 'wielrennen'], $mum->interests);
        $this->assertSame('playful', $mum->vibe);
        $this->assertSame(['vintage'], $mum->preferences);
        $this->assertSame(TasteSource::Suggested, $mum->taste_source);
        // Euros in, cents stored — invariant 7.
        $this->assertSame(6000, $mum->budget_max);
    }

    #[Test]
    public function remembering_never_overwrites_what_the_person_said_themselves(): void
    {
        $this->catalogue();
        [$user, $mum] = $this->mother([
            'interests' => ['gardening'],
            'taste_source' => TasteSource::Self,
        ]);

        $this->actingAs($user)
            ->post('/be-nl/gift', [
                'interests' => ['coffee'],
                'budget_max' => 60,
                'recipient_id' => $mum->id,
                'remember' => true,
            ])
            ->assertOk();

        $mum->refresh();

        // Her own description outranks a guess; the budget is the giver's
        // fact and is not gated the same way.
        $this->assertSame(['gardening'], $mum->interests);
        $this->assertSame(6000, $mum->budget_max);
    }

    #[Test]
    public function a_brief_for_a_saved_person_names_their_list(): void
    {
        $this->catalogue();
        [$user, $mum] = $this->mother();
        $list = Wishlist::factory()->forSomeone($mum)->create(['owner_user_id' => $user->id, 'title' => 'For Mum']);

        $props = $this->actingAs($user)
            ->post('/be-nl/gift', [...$this->brief(), 'recipient_id' => $mum->id])
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame($list->id, $props['recipientList']['id']);
        $this->assertSame('For Mum', $props['recipientList']['title']);
    }

    #[Test]
    public function a_saved_person_without_a_list_gets_one_once(): void
    {
        $this->catalogue();
        [$user, $mum] = $this->mother();

        $post = fn () => $this->actingAs($user)
            ->post('/be-nl/gift', [...$this->brief(), 'recipient_id' => $mum->id])
            ->assertOk()
            ->viewData('page')['props']['recipientList'];

        $made = $post();

        $this->assertNotNull($made);

        $list = Wishlist::query()->findOrFail($made['id']);
        $this->assertSame($mum->id, $list->recipient_id);
        $this->assertSame(ListKind::ForSomeone, $list->kind);
        $this->assertSame($user->id, $list->owner_user_id);

        // Idempotent: the second brief finds the list rather than minting another.
        $again = $post();

        $this->assertSame($made['id'], $again['id']);
        $this->assertSame(1, Wishlist::query()->count());
    }

    #[Test]
    public function no_person_means_no_list(): void
    {
        $this->catalogue();

        $props = $this->post('/be-nl/gift', $this->brief())->assertOk()->viewData('page')['props'];

        $this->assertNull($props['recipientList']);
    }
}

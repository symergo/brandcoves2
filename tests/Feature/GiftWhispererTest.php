<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
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
}

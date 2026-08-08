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
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Discover\DiscoveryRequest;
use App\Services\Discover\ModeEngine;
use App\Services\Discover\ModeRegistry;
use App\Services\Discover\Retrievers\ValueRetriever;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Phase 2 modes.
 *
 * Each of these is meant to be *config* over the Phase 1 pipeline. The tests
 * are therefore about the retrievers' judgement, not about plumbing — the
 * plumbing is already covered by ModeEngineTest, and if it broke these would
 * all fail together.
 */
class DiscoveryModesTest extends TestCase
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

    private function group(string $title, array $attributes = []): ProductGroup
    {
        $group = ProductGroup::create([
            'market' => Market::BeNl,
            'identity_key' => 'k'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => $title,
            'slug' => 'p-'.bin2hex(random_bytes(3)),
            'image_url' => 'https://img.test/x.jpg',
            'min_price' => 4999,
            'merchant_count' => 1,
            'in_stock' => true,
            'giftable' => true,
            ...$attributes,
        ]);

        Product::create([
            'source' => Source::Awin,
            'market' => Market::BeNl,
            'merchant_id' => $this->merchant->id,
            'group_id' => $group->id,
            'external_id' => 'e'.bin2hex(random_bytes(5)),
            'title' => $title,
            'merchant_category' => $attributes['category'] ?? null,
            'price' => $group->min_price,
            'currency' => 'EUR',
            'affiliate_url' => 'https://example.test/buy',
            'availability' => Availability::InStock,
            'status' => ProductStatus::Active,
            'identity_key' => $group->identity_key,
        ]);

        return $group;
    }

    private function engine(): ModeEngine
    {
        return app(ModeEngine::class);
    }

    private function request(array $overrides = []): DiscoveryRequest
    {
        return new DiscoveryRequest(...['market' => Market::BeNl, 'limit' => 8, ...$overrides]);
    }

    // ── Deals ─────────────────────────────────────────────────────────────

    #[Test]
    public function deals_measures_against_our_own_median_not_a_merchants_was_price(): void
    {
        // Genuinely down: our recorded median is well above today's price.
        $real = $this->group('Echte korting', [
            'min_price' => 5000,
            'median_price' => 10000,
            'category' => 'A',
        ]);

        // Permanently "discounted": the median moved with it, so there is no
        // saving to report however loudly the feed says otherwise.
        $this->group('Altijd in de aanbieding', [
            'min_price' => 5000,
            'median_price' => 5100,
            'category' => 'B',
        ]);

        $result = $this->engine()->discover('deals', $this->request());

        $ids = array_map(fn ($i) => $i->group->id, $result->items);

        $this->assertNotEmpty($ids);
        $this->assertSame($real->id, $ids[0]);
    }

    #[Test]
    public function a_cross_merchant_gap_counts_as_value_without_any_history(): void
    {
        // The one claim that needs no history and cannot be faked by a single
        // merchant: the other shop's price is the evidence.
        $gap = $this->group('Zelfde product, twee prijzen', [
            'min_price' => 6000,
            'max_price' => 10000,
            'merchant_count' => 2,
            'category' => 'A',
        ]);

        $result = $this->engine()->discover('deals', $this->request());

        $this->assertContains($gap->id, array_map(fn ($i) => $i->group->id, $result->items));
    }

    #[Test]
    public function a_trivial_saving_is_not_a_deal(): void
    {
        $this->group('Drie procent eraf', [
            'min_price' => 9700,
            'median_price' => 10000,
            'category' => 'A',
        ]);

        /*
         * A 3% "deal" is a broken promise, not a weak result, so the value
         * retriever must not claim it.
         *
         * Asserted on `sources` rather than on an empty page: the Deals profile
         * is `value 0.8, fresh 0.2`, so a brand-new product legitimately
         * appears via the fresh lane. What must not happen is it being
         * presented as a saving.
         */
        $items = $this->engine()->discover('deals', $this->request())->items;

        foreach ($items as $item) {
            $this->assertNotContains('value', $item->sources);
        }
    }

    // ── Trends ────────────────────────────────────────────────────────────

    #[Test]
    public function trends_prefers_the_recently_seen(): void
    {
        $old = $this->group('Al lang hier', ['category' => 'A']);
        $old->forceFill(['first_seen_at' => now()->subYear()])->save();

        // The offer row has to be backdated too, not just the group. "Rising"
        // is measured on when shops started carrying it, and a year-old product
        // whose only offer row was written this second would otherwise look
        // like it was picked up today.
        DB::table('products')->where('group_id', $old->id)
            ->update(['created_at' => now()->subYear()]);

        $new = $this->group('Net binnen', ['category' => 'B']);
        $new->forceFill(['first_seen_at' => now()->subDays(2)])->save();

        $result = $this->engine()->discover('trends', $this->request(), seed: 3);
        $ids = array_map(fn ($i) => $i->group->id, $result->items);

        $this->assertSame($new->id, $ids[0]);
        // Not merely ranked lower — a year-old product is not "what's current"
        // at all, so it never enters the pool.
        $this->assertNotContains($old->id, $ids);
    }

    // ── Compare ───────────────────────────────────────────────────────────

    #[Test]
    public function compare_returns_a_ladder_across_the_price_range(): void
    {
        foreach ([2000, 4000, 6000, 8000, 12000, 20000, 40000] as $price) {
            $this->group("Koptelefoon {$price}", ['min_price' => $price, 'category' => 'Audio']);
        }

        $result = $this->engine()->discover('compare', $this->request(['query' => 'koptelefoon', 'limit' => 4]));
        $prices = array_map(fn ($i) => $i->group->min_price, $result->items);

        $this->assertNotEmpty($prices);

        /*
         * The point of Compare is the *shape* of the category. Returning the
         * four cheapest would be the bottom of the market presented as a
         * comparison, so the sample has to span the range.
         */
        $this->assertGreaterThan(
            10000,
            max($prices) - min($prices),
            'Compare returned a narrow slice rather than a spectrum.'
        );
    }

    #[Test]
    public function compare_needs_something_to_compare(): void
    {
        $this->group('Koptelefoon', ['category' => 'Audio']);

        // No seed and no query would be "the whole catalogue, by price", which
        // is not a comparison. The retriever reports itself unavailable and the
        // mode returns nothing rather than something meaningless.
        $this->assertSame([], $this->engine()->discover('compare', $this->request())->items);
    }

    // ── Projects ──────────────────────────────────────────────────────────

    #[Test]
    public function a_goal_is_decomposed_into_slots_and_each_is_filled_once(): void
    {
        foreach ([
            'Zit sta bureau eiken' => 40000,
            'Bureaustoel ergonomisch mesh' => 30000,
            'Monitor 27 inch IPS' => 25000,
            'Bureaulamp LED' => 6000,
            'Draadloos toetsenbord en muis' => 7000,
        ] as $title => $price) {
            $this->group($title, ['min_price' => $price, 'category' => 'Kantoor']);
        }

        $result = $this->engine()->discover('projects', $this->request([
            'goal' => 'thuiswerkplek inrichten',
            'budgetMax' => 150000,
        ]));

        $titles = array_map(fn ($i) => $i->group->title, $result->items);

        // A kit, not five of the same thing: one product per slot.
        $this->assertGreaterThanOrEqual(3, count($titles));
        $this->assertSame(count($titles), count(array_unique($titles)));
    }

    #[Test]
    public function an_unmatched_goal_degrades_to_a_search_rather_than_to_nothing(): void
    {
        $this->group('Aquarium 200 liter', ['category' => 'Dieren']);

        // The person told us something real. Treating an unknown goal as one
        // slot keeps that, where an empty page would throw it away.
        $result = $this->engine()->discover('projects', $this->request(['goal' => 'aquarium']));

        $this->assertNotEmpty($result->items);
    }

    #[Test]
    public function a_slot_with_no_match_is_left_out_rather_than_filled_wrongly(): void
    {
        // Only a desk exists. A kit with four of five parts is honest; a kit
        // with a mismatched fifth is not.
        $this->group('Zit sta bureau eiken', ['min_price' => 40000, 'category' => 'Kantoor']);

        $result = $this->engine()->discover('projects', $this->request([
            'goal' => 'thuiswerkplek',
            'budgetMax' => 150000,
        ]));

        $this->assertCount(1, $result->items);
    }

    // ── Collaborative ─────────────────────────────────────────────────────

    #[Test]
    public function co_occurrence_needs_more_than_one_list_to_agree(): void
    {
        $seed = $this->group('Koffiemolen', ['category' => 'Keuken']);
        $neighbour = $this->group('Melkopschuimer', ['category' => 'Keuken']);

        $user = User::create(['email' => 'a@example.test']);

        // One list pairing two products is one person's opinion.
        $this->listWith($user, [$seed, $neighbour]);

        $engine = $this->engine();
        $request = $this->request(['seedGroupIds' => [$seed->id]]);

        $this->assertNotContains(
            $neighbour->id,
            array_map(fn ($i) => $i->group->id, $engine->discover('serendipity', $request, seed: 2)->items),
        );

        // A second list agreeing makes it a signal.
        $this->listWith(User::create(['email' => 'b@example.test']), [$seed, $neighbour]);

        $this->assertContains(
            $neighbour->id,
            array_map(fn ($i) => $i->group->id, $engine->discover('serendipity', $request, seed: 2)->items),
        );
    }

    /** @param list<ProductGroup> $groups */
    private function listWith(User $user, array $groups): void
    {
        $list = Wishlist::create([
            'owner_user_id' => $user->id,
            'title' => 'L',
            'market' => Market::BeNl,
        ]);

        foreach ($groups as $group) {
            WishlistItem::create([
                'wishlist_id' => $list->id,
                'group_id' => $group->id,
                'snapshot_title' => $group->title,
                'snapshot_price' => $group->min_price,
            ]);
        }
    }

    // ── The axis as a whole ───────────────────────────────────────────────

    #[Test]
    public function every_enabled_mode_answers_without_erroring(): void
    {
        $this->group('Koptelefoon Sony', ['category' => 'Audio', 'surprise_score' => 60, 'median_price' => 9000]);
        $this->group('Sous-vide circulator', ['category' => 'Keuken', 'surprise_score' => 90]);

        foreach (app(ModeRegistry::class)->all() as $key => $profile) {
            // A mode that 500s is worse than a disabled one — the axis is
            // rendered as a single dial, so one broken stop breaks the control.
            $this->get("/be-nl/discover/{$key}?q=koptelefoon")
                ->assertOk()
                ->assertInertia(fn ($page) => $page->where('mode', $key)->has('items'));
        }
    }

    #[Test]
    public function a_disabled_mode_is_absent_rather_than_broken(): void
    {
        $registry = app(ModeRegistry::class);

        // Both are declared in config so the axis is documented in one place,
        // and both would return a plausible page — which is precisely why they
        // are off. A plausible wrong answer costs more than a missing one.
        $this->assertFalse($registry->has('inspiration'));
        $this->assertFalse($registry->has('advisor'));

        $this->get('/be-nl/discover/inspiration')->assertNotFound();
    }

    #[Test]
    public function the_quality_filter_applies_in_every_mode(): void
    {
        // Out of stock, so unbuyable, so wrong everywhere — not a per-mode
        // decision.
        $this->group('Uitverkocht koopje', [
            'in_stock' => false,
            'min_price' => 5000,
            'median_price' => 20000,
            'category' => 'A',
        ]);

        foreach (['deals', 'trends', 'serendipity', 'guides'] as $mode) {
            $items = $this->engine()->discover($mode, $this->request(['query' => 'koopje']))->items;

            foreach ($items as $item) {
                $this->assertTrue($item->group->in_stock, "[{$mode}] returned an out-of-stock product");
            }
        }
    }

    #[Test]
    public function deals_ignores_history_from_a_source_that_forbids_price_tracking(): void
    {
        $group = $this->group('Alleen bij Amazon', [
            'min_price' => 5000,
            'median_price' => 20000,
            'category' => 'A',
        ]);

        $product = DB::table('products')->where('group_id', $group->id)->first();
        DB::table('products')->where('id', $product->id)->update(['source' => Source::Amazon->value]);

        // A recorded low that the retriever must refuse to read.
        DB::table('price_history')->insert([
            'product_id' => $product->id,
            'price' => 5000,
            'availability' => 'in_stock',
            'captured_at' => now()->subDays(3),
            'captured_on' => now()->subDays(3)->toDateString(),
        ]);

        /*
         * COMPLIANCE: the price is stored — that is permitted — but a
         * user-facing feature built on *retained* pricing may not use it.
         *
         * Asserted on the retriever directly rather than through the Deals
         * profile: that profile also runs `fresh`, which legitimately sets
         * novelty to 1.0 for a brand-new product, and signals merge by max. The
         * mixed result would pass this test for the wrong reason.
         */
        $candidates = app(ValueRetriever::class)
            ->retrieve($this->request(), 8);

        foreach ($candidates as $candidate) {
            if ($candidate->group->id === $group->id) {
                $this->assertLessThan(
                    1.0,
                    $candidate->signal('novelty'),
                    'A historic-low claim was built from a source that forbids price tracking.'
                );
            }
        }

        $this->assertNotEmpty($candidates);
    }
}

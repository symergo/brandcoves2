<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\Merchant;
use App\Models\PopularRank;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Gift\Suggestion;
use App\Services\Gift\SuggestionEngine;
use App\Services\Gift\SuggestionProfile;
use App\Services\Gift\TasteBrief;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a bestseller chart is and is not allowed to do to a gift suggestion.
 *
 * This is the test the whole demand feature is fenced by. `SuggestionEngine`
 * carries an explicit `surprise` term whose stated purpose is to stop the
 * best-stocked product winning every tie — "exactly the failure this whole
 * feature exists to avoid". Adding a demand signal to the same pipeline is the
 * obvious way to undo that, quietly, while looking like an improvement.
 *
 * So: chart data may put a product in front of the scorer, and for the gift
 * profile it may not move it once there.
 */
class SuggestionEngineDemandTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $shop;

    private Merchant $bol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Merchant::create([
            'source' => Source::Awin->value,
            'external_id' => 'shop',
            'name' => 'Shop',
        ]);

        $this->bol = Merchant::create([
            'source' => Source::Bol->value,
            'external_id' => 'bol',
            'name' => 'bol.com',
        ]);
    }

    private function giftable(string $title, int $price, int $merchants = 2, ?Merchant $merchant = null): ProductGroup
    {
        $group = ProductGroup::create([
            'market' => Market::BeNl,
            'identity_key' => 'k'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => $title,
            'slug' => 'p-'.bin2hex(random_bytes(3)),
            'category' => 'Koptelefoon',
            'image_url' => 'https://img.test/x.jpg',
            'min_price' => $price,
            'merchant_count' => $merchants,
            'in_stock' => true,
            'giftable' => true,
            'first_seen_at' => now()->subDays(random_int(1, 60)),
        ]);

        Product::create([
            'source' => ($merchant ?? $this->shop)->source,
            'market' => Market::BeNl,
            'merchant_id' => ($merchant ?? $this->shop)->id,
            'group_id' => $group->id,
            'external_id' => 'e'.bin2hex(random_bytes(5)),
            'title' => $title,
            'merchant_category' => 'Koptelefoon',
            'price' => $price,
            'currency' => 'EUR',
            'affiliate_url' => 'https://example.test/buy',
            'availability' => Availability::InStock,
            'status' => ProductStatus::Active,
            'identity_key' => $group->identity_key,
        ]);

        return $group;
    }

    private function charted(ProductGroup $group, int $rank): void
    {
        PopularRank::create([
            'source' => Source::Bol->value,
            'market' => Market::BeNl->value,
            'category_external_id' => PopularRank::OVERALL,
            'external_id' => 'chart-'.$group->id,
            'rank' => $rank,
            'captured_on' => now()->toDateString(),
            'captured_at' => now(),
            'group_id' => $group->id,
        ]);

        // The demand map is cached per market, and these tests write ranks after
        // the engine may already have read it.
        Cache::flush();
    }

    private function brief(SuggestionProfile $profile): TasteBrief
    {
        return new TasteBrief(
            market: Market::BeNl,
            interests: ['koptelefoon'],
            budgetMax: 20000,
            limit: 4,
            profile: $profile,
        );
    }

    /** @return list<Suggestion> */
    private function suggest(SuggestionProfile $profile): array
    {
        // A fresh container instance each time: ChartDemand memoises per request,
        // and these assertions turn on data written between calls.
        $this->app->forgetInstance(SuggestionEngine::class);

        return app(SuggestionEngine::class)->suggest($this->brief($profile));
    }

    #[Test]
    public function chart_data_does_not_move_a_gift_suggestion(): void
    {
        $groups = [];

        foreach (range(1, 12) as $i) {
            $groups[$i] = $this->giftable("Koptelefoon model {$i}", 5000 + ($i * 500));
        }

        $before = array_map(
            fn (Suggestion $s) => [$s->group->id, round($s->score, 6)],
            $this->suggest(SuggestionProfile::forSomeone()),
        );

        // Put the least likely candidate at the very top of the chart.
        $this->charted($groups[12], 1);
        $this->charted($groups[11], 2);

        $after = array_map(
            fn (Suggestion $s) => [$s->group->id, round($s->score, 6)],
            $this->suggest(SuggestionProfile::forSomeone()),
        );

        /*
         * Identical — same products, same scores, same order.
         *
         * Buying for another person is precisely where `surprise()` is doing its
         * job: "something stocked by every shop is something they have already
         * been shown". A demand term here would pull against that and turn the
         * Whisperer into a bestseller list, which is a thing anybody can already
         * get from the retailer directly.
         */
        $this->assertSame($before, $after);
    }

    #[Test]
    public function your_own_list_does_take_demand_into_account(): void
    {
        $plain = $this->giftable('Koptelefoon alpha', 8000);
        $popular = $this->giftable('Koptelefoon beta', 8000);

        $breakdown = fn (array $picks, int $id) => collect($picks)
            ->firstWhere(fn (Suggestion $s) => $s->group->id === $id)
            ?->breakdown['demand'] ?? null;

        $this->charted($popular, 1);

        $picks = $this->suggest(SuggestionProfile::forMyself());

        /*
         * The opposite question to the one above, and the reason
         * SuggestionProfile exists at all. Nobody wants a surprising kettle on
         * their own wishlist — they want the one that turns out to be good, and
         * "a lot of people bought it" is the cheapest evidence of that going.
         */
        $this->assertGreaterThan(0.0, $breakdown($picks, $popular->id));
        $this->assertSame(0.0, $breakdown($picks, $plain->id));
    }

    #[Test]
    public function a_single_merchant_bestseller_still_reaches_the_candidate_pool(): void
    {
        /*
         * The coverage bug this fixes.
         *
         * The pool is capped at 300 and ordered by merchant_count. A product
         * pulled from bol's chart is sold by bol alone, so it sorts dead last
         * and falls off the end — the things people demonstrably buy would be
         * systematically missing from gift suggestions, with nothing in the
         * output to show for it.
         */
        $this->fillPool();

        // Priced at the budget's sweet spot, so if it reaches the scorer at all
        // it wins — which is what makes a four-result assertion enough to prove
        // pool membership without running MMR over three hundred candidates.
        $bestseller = $this->giftable(
            'Koptelefoon bestseller',
            17000,
            merchants: 1,
            merchant: $this->bol,
        );

        $this->charted($bestseller, 1);

        $picks = $this->suggest(SuggestionProfile::forSomeone());

        $this->assertContains(
            $bestseller->id,
            array_map(fn (Suggestion $s) => $s->group->id, $picks),
        );
    }

    #[Test]
    public function the_reserved_slice_obeys_the_same_filters_as_the_rest(): void
    {
        $this->fillPool();

        // A charting bestseller, priced to win, that the brief has explicitly
        // ruled out.
        $forbidden = $this->giftable(
            'Koptelefoon met alcohol',
            17000,
            merchants: 1,
            merchant: $this->bol,
        );

        $this->charted($forbidden, 1);

        $brief = new TasteBrief(
            market: Market::BeNl,
            interests: ['koptelefoon'],
            budgetMax: 20000,
            avoid: ['alcohol'],
            limit: 4,
            profile: SuggestionProfile::forSomeone(),
        );

        $this->app->forgetInstance(SuggestionEngine::class);
        $picks = app(SuggestionEngine::class)->suggest($brief);

        $this->assertNotEmpty($picks, 'The fixture must produce suggestions for the exclusion to mean anything.');

        /*
         * "Avoid" is a hard filter, never a penalty — one violation makes the
         * whole page untrustworthy. A second query with its own copy of the
         * conditions is exactly how a rule ends up holding on one path and
         * quietly not on the other, which is why the slice reuses the same
         * builder rather than rebuilding it.
         */
        $this->assertNotContains(
            $forbidden->id,
            array_map(fn (Suggestion $s) => $s->group->id, $picks),
        );
    }

    /**
     * Overfill the candidate pool with better-stocked products.
     *
     * More than CANDIDATE_POOL rows, every one of them with a higher
     * `merchant_count` than a chart entry can have, so the ordering pushes the
     * bestseller off the end. That is the exact condition the reserved slice
     * exists for — without it the fixture proves nothing.
     *
     * Bulk-inserted rather than looped through Eloquent: 305 saves plus 305
     * offers is a minute of wall clock for a fixture nothing asserts against.
     */
    private function fillPool(int $count = 305): void
    {
        $groups = [];
        $products = [];
        $now = now();

        foreach (range(1, $count) as $i) {
            $key = 'filler-'.$i;

            $groups[] = [
                'market' => Market::BeNl->value,
                'identity_key' => $key,
                'identity_kind' => 'ean',
                'title' => "Koptelefoon filler {$i}",
                'slug' => 'filler-'.$i,
                'category' => 'Koptelefoon',
                'image_url' => 'https://img.test/x.jpg',
                'min_price' => 5000 + $i,
                'merchant_count' => 5,
                'in_stock' => true,
                'giftable' => true,
                'first_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        ProductGroup::query()->insert($groups);

        foreach (ProductGroup::query()->where('slug', 'like', 'filler-%')->get() as $group) {
            $products[] = [
                'source' => Source::Awin->value,
                'market' => Market::BeNl->value,
                'merchant_id' => $this->shop->id,
                'group_id' => $group->id,
                'external_id' => 'x-'.$group->id,
                'title' => $group->title,
                'merchant_category' => 'Koptelefoon',
                'price' => $group->min_price,
                'currency' => 'EUR',
                'affiliate_url' => 'https://example.test/buy',
                'availability' => Availability::InStock->value,
                'status' => ProductStatus::Active->value,
                'identity_key' => $group->identity_key,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        Product::query()->insert($products);
    }
}

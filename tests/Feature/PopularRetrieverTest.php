<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Enums\Source;
use App\Models\PopularRank;
use App\Models\ProductGroup;
use App\Services\Discover\Candidate;
use App\Services\Discover\DiscoveryRequest;
use App\Services\Discover\ModeEngine;
use App\Services\Discover\Retrievers\PopularRetriever;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Demand, turned into signals the ranker already understands.
 *
 * The claim under test is the one the whole rank *history* exists for: a chart
 * position says what is popular, and only a difference between two positions
 * says what is becoming popular. Trends runs at γ = 0.7, so novelty is where a
 * climber has to land for the mode to mean "what's current" rather than "what
 * sells most".
 */
class PopularRetrieverTest extends TestCase
{
    use RefreshDatabase;

    private function group(string $title, int $merchants = 1): ProductGroup
    {
        return ProductGroup::create([
            'market' => Market::BeNl,
            'identity_key' => 'k'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => $title,
            'slug' => 'p-'.bin2hex(random_bytes(3)),
            'image_url' => 'https://img.test/x.jpg',
            'min_price' => 4999,
            'merchant_count' => $merchants,
            'in_stock' => true,
            'giftable' => true,
            // So `fresh` can see it too — the degradation test needs a
            // retriever left standing when `popular` stands down.
            'first_seen_at' => now()->subDay(),
        ]);
    }

    private function rank(ProductGroup $group, int $rank, string $on, string $category = PopularRank::OVERALL): void
    {
        PopularRank::create([
            'source' => Source::Bol->value,
            'market' => Market::BeNl->value,
            'category_external_id' => $category,
            'external_id' => 'e'.$group->id.'-'.$category,
            'rank' => $rank,
            'captured_on' => $on,
            'captured_at' => $on.' 03:40:00',
            'group_id' => $group->id,
        ]);
    }

    private function retrieve(int $take = 10): array
    {
        return app(PopularRetriever::class)->retrieve(
            new DiscoveryRequest(market: Market::BeNl),
            $take,
        );
    }

    #[Test]
    public function a_climber_beats_a_permanent_number_one_on_novelty(): void
    {
        $steady = $this->group('Always first');
        $climber = $this->group('On the way up');

        $lastWeek = now()->subDays(7)->toDateString();
        $today = now()->toDateString();

        $this->rank($steady, 1, $lastWeek);
        $this->rank($climber, 40, $lastWeek);

        $this->rank($steady, 1, $today);
        $this->rank($climber, 6, $today);

        $signals = collect($this->retrieve())
            ->mapWithKeys(fn (Candidate $c) => [$c->group->title => $c->signals]);

        // Position still favours the steady number one — it is, after all, the
        // best-selling thing here.
        $this->assertGreaterThan(
            $signals['On the way up']['relevance'],
            $signals['Always first']['relevance'],
        );

        /*
         * And novelty flips it. #40 → #6 is the event; sitting at #1 for a year
         * is a fact. With Trends at γ = 0.7 against α = 0.3, this is what makes
         * the mode answer "what's current" rather than "what sells most" —
         * which `fresh` was already approximating badly.
         */
        $this->assertGreaterThan(
            $signals['Always first']['novelty'],
            $signals['On the way up']['novelty'],
        );
        $this->assertSame(0.2, $signals['Always first']['novelty']);
    }

    #[Test]
    public function a_new_entry_scores_maximum_novelty(): void
    {
        $arrival = $this->group('Just arrived');

        $this->rank($this->group('Old news'), 3, now()->subDays(7)->toDateString());
        $this->rank($arrival, 9, now()->toDateString());

        $signals = collect($this->retrieve())
            ->firstWhere(fn (Candidate $c) => $c->group->title === 'Just arrived')
            ->signals;

        // It was not on the chart at all a week ago. Nothing about a product is
        // more "current" than that.
        $this->assertSame(1.0, $signals['novelty']);
    }

    #[Test]
    public function the_first_ever_snapshot_claims_no_novelty_at_all(): void
    {
        $this->rank($this->group('Day one'), 3, now()->toDateString());

        $signals = collect($this->retrieve())->first()->signals;

        /*
         * The day a new environment first pulls a chart there is one snapshot
         * and nothing to compare against. Reporting every product as a new entry
         * would be technically true and completely misleading — at Trends'
         * γ = 0.7 it would rank the whole page on a gap in our own data and
         * explain every result as "new" on the one day the claim cannot be
         * supported.
         *
         * Unset, so the ranker applies its neutral default rather than the
         * maximum.
         */
        $this->assertArrayNotHasKey('novelty', $signals);
        $this->assertArrayHasKey('relevance', $signals);
    }

    #[Test]
    public function a_gap_in_our_own_data_does_not_fake_a_chart_full_of_arrivals(): void
    {
        $group = $this->group('Steady');

        // Two snapshots, four weeks apart — a skipped run, a deploy that ate a
        // night. The comparison reaches for the nearest older snapshot rather
        // than "exactly seven days ago", because finding nothing there would
        // award every product maximum novelty for a hole in our own data.
        $this->rank($group, 12, now()->subDays(28)->toDateString());
        $this->rank($group, 12, now()->toDateString());

        $signals = collect($this->retrieve())->first()->signals;

        $this->assertSame(0.2, $signals['novelty']);
    }

    #[Test]
    public function a_products_best_position_across_charts_is_the_one_that_counts(): void
    {
        $group = $this->group('In two charts');
        $today = now()->toDateString();

        $this->rank($group, 90, $today, PopularRank::OVERALL);
        $this->rank($group, 2, $today, '4770');

        $signals = collect($this->retrieve())->first()->signals;

        // #2 in headphones and #90 overall is a strong seller, not a weak one.
        // Averaging would punish a product for charting in a large category as
        // well as a small one.
        $best = 1.0 / (1.0 + log(2));

        $this->assertEqualsWithDelta($best, $signals['relevance'], 0.0001);
    }

    #[Test]
    public function a_trimmed_pool_keeps_the_top_of_the_chart(): void
    {
        $today = now()->toDateString();

        // Ranked worst-first on insert, so an unsorted trim would keep exactly
        // the wrong ones.
        foreach (range(1, 9) as $i) {
            $this->rank($this->group("Chart position {$i}"), 10 - $i, $today);
        }

        $titles = array_map(
            fn (Candidate $c) => $c->group->title,
            $this->retrieve(take: 3),
        );

        /*
         * `whereIn` returns rows in whatever order Postgres finds them, and the
         * pool is three times the asked-for size — so trimming without sorting
         * discards the top of the chart at random. The ranker reorders
         * afterwards, which is what makes this easy to miss: the page still
         * looks plausible, built from the wrong candidates.
         */
        sort($titles);
        $this->assertSame(['Chart position 7', 'Chart position 8', 'Chart position 9'], $titles);
    }

    #[Test]
    public function an_unpresentable_entry_is_not_rescued_by_charting(): void
    {
        $group = $this->group('No image');
        $group->update(['image_url' => null]);

        $this->rank($group, 1, now()->toDateString());

        // The quality gate is not optional per retriever. A card with no image
        // reads as broken whether or not it is a bestseller.
        $this->assertSame([], $this->retrieve());
    }

    #[Test]
    public function an_ungrouped_rank_row_is_skipped(): void
    {
        PopularRank::create([
            'source' => Source::Bol->value,
            'market' => Market::BeNl->value,
            'category_external_id' => PopularRank::OVERALL,
            'external_id' => 'never-grouped',
            'rank' => 1,
            'captured_on' => now()->toDateString(),
            'captured_at' => now(),
            'group_id' => null,
        ]);

        // It charted, and we have nothing to show for it yet — usually because
        // grouping has not caught up. Not an error, just not a candidate.
        $this->assertSame([], $this->retrieve());
    }

    #[Test]
    public function a_stale_snapshot_stands_the_retriever_down(): void
    {
        $this->rank($this->group('Ancient'), 1, now()->subDays(30)->toDateString());

        $request = new DiscoveryRequest(market: Market::BeNl);

        /*
         * A month of nothing means the puller is broken or the credentials
         * lapsed. Ranking on it would present month-old demand as current, so
         * the mode renormalises onto `fresh` instead — the same degradation
         * every unavailable retriever gets.
         */
        $this->assertFalse(app(PopularRetriever::class)->isAvailable($request));
    }

    #[Test]
    public function trends_still_returns_a_full_page_with_no_chart_data_at_all(): void
    {
        // Spread across days on purpose: CatalogueAge treats a single day
        // holding a fifth of the catalogue as a bulk import and suppresses
        // novelty for it, so a one-row fixture would leave `fresh` empty for a
        // reason that has nothing to do with what this test is asserting.
        foreach (range(1, 6) as $day) {
            $this->group("Catalogue item {$day}")
                ->forceFill(['first_seen_at' => now()->subDays($day)])
                ->save();
        }

        // No ranks anywhere. `popular` carries 0.6 of the Trends profile, and
        // the engine must renormalise onto `fresh` rather than serving two
        // fifths of a page.
        $result = app(ModeEngine::class)->discover(
            'trends',
            new DiscoveryRequest(market: Market::BeNl, limit: 4),
        );

        $this->assertNotEmpty($result->items);
    }
}

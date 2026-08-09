<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Enums\Vibe;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Gift\SuggestionEngine;
use App\Services\Gift\SuggestionProfile;
use App\Services\Gift\TasteBrief;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The gift engine.
 *
 * The load-bearing assertion is the diversification one: a recommender whose
 * MMR stage quietly stops working looks exactly like one that works, right up
 * until every result page shows four of the same thing.
 */
class SuggestionEngineTest extends TestCase
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
     * A giftable, retrievable product.
     *
     * An offer is created alongside the group because retrieval matches on
     * products.search_vector — the generated column is on the offer, not on the
     * group. A group with no offer is invisible to the engine, which is correct:
     * nobody sells it.
     */
    private function giftable(
        string $title,
        int $price,
        ?string $category = null,
        ?string $brand = null,
        int $merchants = 1,
    ): ProductGroup {
        $group = ProductGroup::create([
            'market' => Market::BeNl,
            'identity_key' => 'k'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => $title,
            'slug' => 'p-'.bin2hex(random_bytes(3)),
            'category' => $category,
            'brand' => $brand,
            'image_url' => 'https://img.test/x.jpg',
            'min_price' => $price,
            'merchant_count' => $merchants,
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
            'brand' => $brand,
            'merchant_category' => $category,
            'price' => $price,
            'currency' => 'EUR',
            'affiliate_url' => 'https://example.test/buy',
            'availability' => Availability::InStock,
            'status' => ProductStatus::Active,
            'identity_key' => $group->identity_key,
        ]);

        return $group;
    }

    private function engine(): SuggestionEngine
    {
        return app(SuggestionEngine::class);
    }

    #[Test]
    public function it_finds_products_that_match_an_interest(): void
    {
        $this->giftable('Manfrotto statief voor camera', 8999, 'Foto');
        $this->giftable('Wasmachine 8kg', 39999, 'Witgoed');

        $picks = $this->engine()->suggest(new TasteBrief(
            market: Market::BeNl,
            interests: ['photography'],
        ));

        $this->assertNotEmpty($picks);
        $this->assertStringContainsString('statief', $picks[0]->group->title);
    }

    #[Test]
    public function a_product_that_is_not_giftable_is_never_suggested(): void
    {
        $tripod = $this->giftable('Manfrotto statief voor camera', 8999, 'Foto');
        $tripod->update(['giftable' => false]);

        $picks = $this->engine()->suggest(new TasteBrief(
            market: Market::BeNl,
            interests: ['photography'],
        ));

        // The classifier's verdict is the gate. One vacuum-cleaner filter in a
        // gift result destroys trust in everything else on the page.
        $this->assertSame([], array_map(fn ($p) => $p->group->id, $picks));
    }

    #[Test]
    public function the_avoid_list_is_a_hard_filter_not_a_penalty(): void
    {
        $this->giftable('Whisky cadeauset met glazen', 4999, 'Drank');
        $this->giftable('Koffiemolen handmatig', 4999, 'Keuken');

        $picks = $this->engine()->suggest(new TasteBrief(
            market: Market::BeNl,
            interests: ['coffee'],
            avoid: ['whisky'],
        ));

        // Someone who wrote "no alcohol" is not expressing a preference to be
        // weighed against price. A single violation makes the page untrustworthy.
        foreach ($picks as $pick) {
            $this->assertStringNotContainsStringIgnoringCase('whisky', $pick->group->title);
        }
    }

    #[Test]
    public function nothing_above_the_budget_is_suggested(): void
    {
        $this->giftable('Espressomachine De Longhi', 89900, 'Keuken');
        $this->giftable('Koffiemolen handmatig', 4999, 'Keuken');

        $picks = $this->engine()->suggest(new TasteBrief(
            market: Market::BeNl,
            interests: ['coffee'],
            budgetMax: 6000,
        ));

        $this->assertNotEmpty($picks);

        foreach ($picks as $pick) {
            $this->assertLessThanOrEqual(6000, $pick->group->min_price);
        }
    }

    #[Test]
    public function budget_fit_peaks_below_the_ceiling_rather_than_at_the_cheapest(): void
    {
        // Same category and near-identical titles, so budget fit is the only
        // signal that differs — otherwise this would be testing MMR by accident.
        $cheap = $this->giftable('Koffiemolen model A', 800, 'Keuken');
        $wellJudged = $this->giftable('Koffiemolen model B', 8500, 'Keuken');

        $picks = $this->engine()->suggest(new TasteBrief(
            market: Market::BeNl,
            interests: ['coffee'],
            budgetMax: 10000,
            limit: 2,
        ));

        $ids = array_map(fn ($p) => $p->group->id, $picks);

        // A €8 gift against a €100 budget reads as thoughtless, not thrifty.
        $this->assertSame($wellJudged->id, $ids[0]);
        $this->assertContains($cheap->id, $ids);
    }

    #[Test]
    public function diversification_breaks_up_a_cluster_of_near_duplicates(): void
    {
        /*
         * THE TEST THIS CLASS EXISTS FOR.
         *
         * Six speakers that all score well for the same reason, plus three
         * genuinely different presents that score slightly lower. Ranked purely
         * by score, the top four are four speakers. That is a worse answer than
         * a speaker plus three other things, even though each speaker
         * individually beats each alternative.
         */
        foreach (['JBL', 'Sony', 'Bose', 'Marshall', 'Denon', 'Sonos'] as $brand) {
            $this->giftable("{$brand} bluetooth speaker draagbaar", 8000, 'Audio', $brand);
        }

        $this->giftable('Koptelefoon studio over-ear', 7000, 'Audio', 'AKG');
        $this->giftable('Platenspeler met ingebouwde voorversterker', 7500, 'Muziek', 'Audio-Technica');
        $this->giftable('Ukelele sopraan mahonie', 6500, 'Instrumenten', 'Fender');

        $picks = $this->engine()->suggest(new TasteBrief(
            market: Market::BeNl,
            interests: ['music'],
            budgetMax: 10000,
            limit: 4,
        ));

        $this->assertCount(4, $picks);

        $speakers = array_filter(
            $picks,
            fn ($p) => str_contains(mb_strtolower($p->group->title), 'bluetooth speaker'),
        );

        // Without MMR this is 4. The point of the stage is that it is not.
        $this->assertLessThanOrEqual(
            2,
            count($speakers),
            'MMR failed: the results collapsed into a cluster of near-duplicates.'
        );

        $categories = array_unique(array_map(fn ($p) => $p->group->category, $picks));
        $this->assertGreaterThan(1, count($categories));
    }

    #[Test]
    public function swapping_never_returns_something_already_rejected(): void
    {
        $first = $this->giftable('Koffiemolen handmatig', 4999, 'Keuken');
        $this->giftable('French press glazen kan', 3499, 'Keuken');

        $brief = new TasteBrief(market: Market::BeNl, interests: ['coffee'], limit: 1);

        $replacement = $this->engine()->suggest($brief->excluding([$first->id]));

        $this->assertNotEmpty($replacement);
        $this->assertNotSame($first->id, $replacement[0]->group->id);
    }

    #[Test]
    public function an_interest_with_no_matches_still_returns_suggestions(): void
    {
        // Nothing here answers "gardening". Returning an empty page would throw
        // away everything the person just told us about the budget and the
        // occasion — a fallback browse is a worse answer, not no answer.
        $this->giftable('Koffiemolen handmatig', 4999, 'Keuken');

        $picks = $this->engine()->suggest(new TasteBrief(
            market: Market::BeNl,
            interests: ['gardening'],
        ));

        $this->assertNotEmpty($picks);
    }

    #[Test]
    public function every_pick_carries_the_arithmetic_that_produced_it(): void
    {
        $this->giftable('Manfrotto statief voor camera', 8999, 'Foto');

        $pick = $this->engine()->suggest(new TasteBrief(
            market: Market::BeNl,
            interests: ['photography'],
            vibe: Vibe::Practical,
        ))[0];

        // "Why did it pick this" is the first question everyone asks — the
        // shopper now, and whoever tunes the weights later.
        $this->assertArrayHasKey('interest_fit', $pick->breakdown);
        $this->assertArrayHasKey('budget_fit', $pick->breakdown);
        $this->assertGreaterThan(0, $pick->breakdown['interest_fit']);
        $this->assertNotSame('', $pick->topSignal());
    }

    #[Test]
    public function a_market_only_ever_sees_its_own_catalogue(): void
    {
        $this->giftable('Manfrotto statief voor camera', 8999, 'Foto');

        $picks = $this->engine()->suggest(new TasteBrief(
            market: Market::Es,
            interests: ['photography'],
        ));

        // Identity is market-scoped for a reason: a Belgian price is not a
        // Spanish one, and letting it show here is the same bug as letting it
        // masquerade as "cheapest".
        $this->assertSame([], $picks);
    }

    #[Test]
    public function the_self_profile_does_not_penalise_a_cheap_item(): void
    {
        // Same product, same interest. Only the price differs.
        $this->giftable('Koffiemolen handmatig klein', 1200, 'Keuken', 'Hario');
        $this->giftable('Koffiemolen elektrisch groot', 8500, 'Keuken', 'Baratza');

        $brief = new TasteBrief(
            market: Market::BeNl,
            interests: ['coffee'],
            budgetMax: 10000,
        );

        $forSomeone = $this->engine()->suggest($brief->rankedAs(SuggestionProfile::forSomeone()));
        $forMyself = $this->engine()->suggest($brief->rankedAs(SuggestionProfile::forMyself()));

        /*
         * The sweet-spot curve is a rule about how a *present* is received: a
         * €12 gift against a €100 budget reads as thoughtless. Nobody thinks
         * their own €12 wish is thoughtless, so on a self-brief the two prices
         * must score the same on budget — otherwise every affordable thing
         * quietly sinks to the bottom of your own wishlist, which is both wrong
         * and completely invisible.
         */
        $giftBudget = array_map(fn ($p) => $p->breakdown['budget_fit'], $forSomeone);
        $selfBudget = array_map(fn ($p) => $p->breakdown['budget_fit'], $forMyself);

        $this->assertGreaterThan(0.01, max($giftBudget) - min($giftBudget));
        $this->assertEqualsWithDelta(0.0, max($selfBudget) - min($selfBudget), 0.001);
    }

    #[Test]
    public function a_search_inside_a_brief_still_honours_avoid(): void
    {
        $this->giftable('Whisky cadeauset met glazen', 4999, 'Drank');
        $this->giftable('Koffiemolen handmatig', 4999, 'Keuken');

        $picks = $this->engine()->suggest(
            (new TasteBrief(market: Market::BeNl, avoid: ['whisky']))
                ->searching('cadeauset')
        );

        /*
         * The point of folding the typed query into the brief: "no alcohol"
         * used to hold on the suggestions page and stop holding the moment
         * somebody used the search box. A hard filter that applies on one
         * surface and not the neighbouring one is not a hard filter.
         */
        $titles = array_map(fn ($p) => $p->group->title, $picks);

        foreach ($titles as $title) {
            $this->assertStringNotContainsStringIgnoringCase('whisky', $title);
        }
    }

    #[Test]
    public function a_typed_query_outranks_a_derived_angle(): void
    {
        $this->giftable('Espresso tamper RVS 58mm', 3500, 'Keuken');
        $this->giftable('Koffiemolen handmatig', 3500, 'Keuken');

        $picks = $this->engine()->suggest(
            (new TasteBrief(market: Market::BeNl, interests: ['coffee'], limit: 2))
                ->searching('tamper')
        );

        // Someone who typed the words has told us exactly what they want.
        // Burying that under a guess derived from "coffee" is how a search box
        // starts to feel broken.
        $this->assertNotEmpty($picks);
        $this->assertStringContainsStringIgnoringCase('tamper', $picks[0]->group->title);
    }

    #[Test]
    public function it_answers_fast_enough_to_sit_in_a_request(): void
    {
        for ($i = 0; $i < 250; $i++) {
            $this->giftable("Koffiemolen variant {$i}", 3000 + $i * 10, 'Keuken', 'Brand'.($i % 12));
        }

        $start = microtime(true);
        $this->engine()->suggest(new TasteBrief(market: Market::BeNl, interests: ['coffee']));
        $elapsed = (microtime(true) - $start) * 1000;

        // Generous against the 100 ms target: this runs on a laptop against a
        // containerised Postgres. It is a smoke alarm for an accidental N+1 or
        // a per-term subquery, not a benchmark.
        $this->assertLessThan(500, $elapsed, "Gift engine took {$elapsed}ms");
    }
}

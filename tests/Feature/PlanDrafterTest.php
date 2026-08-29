<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\CovePlan;
use App\Models\GuideTopic;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Cove\PlanDrafter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Give me ten more of these to curate."
 *
 * The blank page, one level up from the curation screen. What this file pins is
 * the part that would silently rot: that every kind draws on the source that
 * actually knows something — the observance calendar, the mined topic queue, the
 * gift wizard's interests — that nothing it writes is ever approved, and that
 * running out of ideas is reported as a sentence rather than as a zero.
 */
class PlanDrafterTest extends TestCase
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

    #[Test]
    public function it_drafts_daily_coves_for_the_next_unplanned_days(): void
    {
        $result = $this->drafter()->draft(CoveKind::Daily, Market::BeNl, 5, withProducts: false);

        $this->assertSame(5, $result->count());

        foreach ($result->plans as $plan) {
            $this->assertSame(CoveKind::Daily, $plan->kind);
            $this->assertNotNull($plan->drop_date);

            // Never today's: that edition is already built, and a plan for it
            // would be read too late to change anything.
            $this->assertTrue($plan->drop_date->isAfter(today()));

            // A draft, always. A button that produced approved plans would be a
            // content farm with a nicer interface.
            $this->assertSame('draft', $plan->status);
        }
    }

    #[Test]
    public function a_day_somebody_has_already_decided_about_is_left_alone(): void
    {
        $tomorrow = today()->addDay();

        CovePlan::create([
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Daily->value,
            'drop_date' => $tomorrow,
            'title' => 'Mine',
            'status' => 'rejected',
        ]);

        $result = $this->drafter()->draft(CoveKind::Daily, Market::BeNl, 3, withProducts: false);

        /*
         * Whatever its status. A rejected plan is a decision, and the unique
         * index on dated rows means a second one for that day cannot even be
         * inserted — so skipping it is both correct and the only option.
         */
        $dates = array_map(fn (CovePlan $p) => $p->drop_date->toDateString(), $result->plans);

        $this->assertNotContains($tomorrow->toDateString(), $dates);
        $this->assertSame(1, CovePlan::query()->whereDate('drop_date', $tomorrow)->count());
    }

    #[Test]
    public function guides_are_drafted_from_the_topic_queue_most_demand_first(): void
    {
        $this->shelf();

        $this->topic(['topic' => 'koptelefoon', 'score' => 90.0]);
        $this->topic(['topic' => 'espressomachine', 'score' => 10.0]);

        $result = $this->drafter()->draft(CoveKind::Guide, Market::BeNl, 1);

        $this->assertSame(1, $result->count());
        $this->assertSame(CoveKind::Guide, $result->plans[0]->kind);

        // A search topic is worth writing in proportion to its demand, so the
        // one call for one guide has to take the right one.
        $this->assertSame('koptelefoon', $result->plans[0]->focus_keyphrase);

        // And the topic is spoken for: the same idea is never handed out twice.
        $this->assertSame($result->plans[0]->id, GuideTopic::query()->where('topic', 'koptelefoon')->first()->plan_id);
    }

    #[Test]
    public function seasonal_guides_are_drafted_by_how_soon_the_window_opens(): void
    {
        $this->shelf();

        $this->topic(['topic' => 'halloween', 'origin' => 'seasonal', 'season_from' => '09-15', 'season_to' => '10-31', 'score' => 1.0]);
        $this->topic(['topic' => 'kerst', 'origin' => 'seasonal', 'season_from' => '11-01', 'season_to' => '12-24', 'score' => 99.0]);

        $result = $this->drafter()->draft(CoveKind::Seasonal, Market::BeNl, 1);

        /*
         * Not by score. A seasonal guide is urgent because its window is coming,
         * and a high score in March is no reason to write the Christmas one
         * first — the point of a seasonal page is that it is already indexed
         * when the season starts.
         */
        $this->assertSame('halloween', $result->plans[0]->focus_keyphrase);
        $this->assertSame(CoveKind::Seasonal, $result->plans[0]->kind);
        $this->assertSame('09-15', $result->plans[0]->season_from);
    }

    #[Test]
    public function guides_run_out_with_a_sentence_rather_than_a_zero(): void
    {
        $this->shelf();
        $this->topic();

        $result = $this->drafter()->draft(CoveKind::Guide, Market::BeNl, 10);

        $this->assertSame(1, $result->count());

        /*
         * The reason is the useful part: "the queue is empty, mine some more" is
         * a next step, and "1 of 10" on its own reads as a bug and gets the
         * button pressed again.
         */
        $this->assertNotNull($result->shortfall);
        $this->assertStringContainsString('bc:refresh-discovery', $result->shortfall);
    }

    #[Test]
    public function personas_come_one_per_interest_with_that_interests_own_product_words(): void
    {
        $result = $this->drafter()->draft(CoveKind::Persona, Market::BeNl, 3, withProducts: false);

        $this->assertSame(3, $result->count());

        $plan = $result->plans[0];

        $this->assertSame(CoveKind::Persona, $plan->kind);
        $this->assertNull($plan->drop_date);
        $this->assertNotEmpty($plan->slug);

        /*
         * The reason this is worth doing automatically rather than typing:
         * "cooking" as a query finds gift listicles, and the wizard's own nouns
         * find products.
         */
        $this->assertContains('koksmes', $plan->queries);

        // Each interest once. Two personas for cooking is two pages about the
        // same person.
        $slugs = array_map(fn (CovePlan $p) => $p->slug, $result->plans);
        $this->assertSame($slugs, array_unique($slugs));
    }

    #[Test]
    public function a_second_persona_run_continues_where_the_first_stopped(): void
    {
        $first = $this->drafter()->draft(CoveKind::Persona, Market::BeNl, 2, withProducts: false);
        $second = $this->drafter()->draft(CoveKind::Persona, Market::BeNl, 2, withProducts: false);

        $this->assertSame([], array_intersect($first->ids(), $second->ids()));
        $this->assertSame(4, CovePlan::query()->where('kind', CoveKind::Persona->value)->count());
    }

    #[Test]
    public function renaming_a_drafted_persona_does_not_make_its_interest_available_again(): void
    {
        $plan = $this->drafter()->draft(CoveKind::Persona, Market::BeNl, 1, withProducts: false)->plans[0];

        /*
         * The note asks to be renamed, so it will be. The interest is read back
         * out of that note rather than out of the title for exactly this reason:
         * a run after an editor improved the titles would otherwise re-draft
         * every persona somebody had cared about and skip the ones they ignored.
         */
        $plan->update(['title' => 'De thuiskok die alles al heeft', 'slug' => 'de-thuiskok']);

        $again = $this->drafter()->draft(CoveKind::Persona, Market::BeNl, 1, withProducts: false);

        $this->assertNotSame($plan->id, $again->plans[0]->id);
        $this->assertNotSame($plan->queries, $again->plans[0]->queries);
    }

    #[Test]
    public function a_persona_never_steals_a_slug_a_guide_already_holds(): void
    {
        // One slug namespace per market covers every dateless kind, so this
        // collision is possible and must not fail the insert.
        CovePlan::create([
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Guide->value,
            'slug' => 'koken',
            'title' => 'Beste koken',
            'status' => 'approved',
        ]);

        $result = $this->drafter()->draft(CoveKind::Persona, Market::BeNl, 1, withProducts: false);

        $this->assertNotSame('koken', $result->plans[0]->slug);
        $this->assertSame('Beste koken', CovePlan::query()->where('slug', 'koken')->first()->title);
    }

    #[Test]
    public function the_shortlists_are_not_the_same_seven_products_every_time(): void
    {
        $this->shelf();

        $result = $this->drafter()->draft(CoveKind::Persona, Market::BeNl, 2);

        /*
         * None of these plans has been built, so the rolling repeat memory in
         * `daily_picks` cannot see them. Without this run's own memory the
         * highest scoring products in the market would be suggested for every
         * one of them, and the feature would look broken on first sight.
         */
        $first = $result->plans[0]->items()->pluck('group_id')->all();
        $second = $result->plans[1]->items()->pluck('group_id')->all();

        $this->assertSame([], array_intersect($first, $second));
    }

    #[Test]
    public function advice_and_shop_are_refused_with_the_reason(): void
    {
        $drafter = $this->drafter();

        foreach ([CoveKind::Advice, CoveKind::Shop] as $kind) {
            $this->assertFalse($drafter->canDraft($kind));

            $result = $drafter->draft($kind, Market::BeNl, 5);

            /*
             * Nothing in the data suggests an advice article, and nothing builds
             * a Shop plan. Inventing titles from a template would fill the queue
             * with plausible-looking work nobody meant.
             */
            $this->assertSame(0, $result->count());
            $this->assertNotNull($result->shortfall);
        }

        $this->assertSame(0, CovePlan::query()->count());
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function drafter(): PlanDrafter
    {
        return app(PlanDrafter::class);
    }

    /** @param array<string, mixed> $attributes */
    private function topic(array $attributes = []): GuideTopic
    {
        return GuideTopic::create(array_merge([
            'market' => Market::BeNl->value,
            'topic' => 'koptelefoon',
            'member_queries' => ['koptelefoon'],
            'search_volume' => 42,
            'available_products' => 8,
            'origin' => 'search',
            'status' => 'candidate',
        ], $attributes));
    }

    /** Enough of a catalogue that a shortlist can actually be filled. */
    private function shelf(): void
    {
        foreach (['Sony', 'Sennheiser', 'JBL', 'Philips', 'Marshall', 'AKG', 'Bose', 'Beyerdynamic'] as $i => $brand) {
            $group = ProductGroup::create([
                'market' => Market::BeNl,
                'identity_key' => 'k'.bin2hex(random_bytes(5)),
                'identity_kind' => 'ean',
                'title' => "{$brand} koptelefoon",
                'slug' => 'p-'.bin2hex(random_bytes(3)),
                'brand' => $brand,
                'category' => 'audio',
                'image_url' => 'https://img.test/x.jpg',
                'min_price' => 5000 + $i * 4000,
                'merchant_count' => 2,
                'in_stock' => true,
                'giftable' => true,
                'worth_showing' => true,
            ]);

            Product::create([
                'source' => Source::Awin,
                'market' => Market::BeNl,
                'merchant_id' => $this->merchant->id,
                'group_id' => $group->id,
                'external_id' => 'e'.bin2hex(random_bytes(5)),
                'title' => "{$brand} koptelefoon",
                'price' => 5000 + $i * 4000,
                'currency' => 'EUR',
                'affiliate_url' => 'https://example.test/buy',
                'availability' => Availability::InStock,
                'status' => ProductStatus::Active,
                'identity_key' => $group->identity_key,
            ]);
        }
    }
}

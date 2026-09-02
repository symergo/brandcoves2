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
use App\Services\Guides\TopicPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The topic queue is an idea feed, not a second publishing pipeline.
 *
 * It used to publish: queue a topic and one night the builder chose its own
 * products, wrote about them and put the page live. The shortlist that made the
 * page worth reading was nobody's decision, and the only editorial control was
 * rewriting sentences afterwards.
 *
 * Now a topic becomes a *draft plan*, pre-filled with what the builder would
 * have chosen, for a person to react to.
 */
class TopicPlannerTest extends TestCase
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
    public function a_mined_topic_becomes_a_draft_guide_plan(): void
    {
        $this->shelf();
        $topic = $this->topic();

        $plan = app(TopicPlanner::class)->draft($topic);

        $this->assertSame(CoveKind::Guide, $plan->kind);
        $this->assertSame(Market::BeNl, $plan->market);

        /*
         * A draft, always. The whole point of drafting is that somebody reads it
         * before it publishes — a queue producing approved plans would be the
         * old pipeline with an extra table in it.
         */
        $this->assertSame('draft', $plan->status);

        // The phrase the page is written to answer, carried across because it is
        // the reason the topic exists at all.
        $this->assertSame('koptelefoon', $plan->focus_keyphrase);
        $this->assertSame(['koptelefoon', 'draadloze koptelefoon'], $plan->queries);
        $this->assertSame($plan->id, $topic->fresh()->plan_id);
    }

    #[Test]
    public function the_plan_opens_with_products_already_on_it(): void
    {
        $this->shelf();

        $plan = app(TopicPlanner::class)->draft($this->topic());

        /*
         * A plan that opens empty asks an editor to invent seven products from
         * nothing. These are suggestions to react to.
         */
        $this->assertGreaterThan(0, $plan->items()->count());

        // And no notes: a note is the reason a *person* chose something, and
        // putting "suggested by the planner" there would send that sentence to
        // the writer as the reason the product is on the page.
        $this->assertNull($plan->items()->first()->note);
    }

    #[Test]
    public function a_seasonal_topic_becomes_a_seasonal_cove_with_its_window(): void
    {
        $this->shelf();

        $plan = app(TopicPlanner::class)->draft($this->topic([
            'topic' => 'halloween',
            'origin' => 'seasonal',
            'season_from' => '09-15',
            'season_to' => '10-31',
        ]));

        /*
         * The distinction lives on the topic and nowhere else, so this is the
         * only moment it can be carried across.
         */
        $this->assertSame(CoveKind::Seasonal, $plan->kind);
        $this->assertSame('09-15', $plan->season_from);
        $this->assertSame('10-31', $plan->season_to);
    }

    #[Test]
    public function the_slug_is_language_prefixed_like_a_folded_guide(): void
    {
        $this->shelf();

        $plan = app(TopicPlanner::class)->draft($this->topic());

        // Same shape a guide has always had, so a folded guide and a newly
        // planned one are addressed identically.
        $this->assertSame('beste-koptelefoon', $plan->slug);
        $this->assertSame('guides/beste-koptelefoon', $plan->kind->path($plan->slug, Market::BeNl));
    }

    #[Test]
    public function a_slug_a_persona_already_holds_is_suffixed_not_stolen(): void
    {
        $this->shelf();

        CovePlan::create([
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Persona->value,
            'slug' => 'beste-koptelefoon',
            'title' => 'De audiofiel',
            'status' => 'approved',
        ]);

        $plan = app(TopicPlanner::class)->draft($this->topic());

        // One slug namespace per market covers every dateless kind, so a
        // collision here is possible and must not silently fail the insert.
        $this->assertSame('beste-koptelefoon-2', $plan->slug);
    }

    #[Test]
    public function a_topic_cannot_be_drafted_twice(): void
    {
        $this->shelf();
        $topic = $this->topic();

        app(TopicPlanner::class)->draft($topic);

        /*
         * Refused rather than made idempotent: a second plan for one topic is
         * two people writing the same guide, and quietly returning the first
         * would hide that somebody had already started.
         */
        $this->expectException(InvalidArgumentException::class);

        app(TopicPlanner::class)->draft($topic->fresh());
    }

    #[Test]
    public function the_note_records_why_the_topic_was_offered(): void
    {
        $this->shelf();

        $plan = app(TopicPlanner::class)->draft($this->topic(['search_volume' => 180]));

        // The evidence is the most persuasive argument for writing the page and
        // is invisible from the plan otherwise.
        $this->assertStringContainsString('180', (string) $plan->note);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** @param array<string, mixed> $attributes */
    private function topic(array $attributes = []): GuideTopic
    {
        return GuideTopic::create(array_merge([
            'market' => Market::BeNl->value,
            'topic' => 'koptelefoon',
            'member_queries' => ['koptelefoon', 'draadloze koptelefoon'],
            'search_volume' => 42,
            'available_products' => 8,
            'origin' => 'search',
            'status' => 'candidate',
        ], $attributes));
    }

    private function shelf(): void
    {
        foreach (['Sony', 'Sennheiser', 'JBL', 'Philips', 'Marshall', 'AKG'] as $i => $brand) {
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

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PickMode;
use App\Enums\ProductStatus;
use App\Enums\PublishStatus;
use App\Enums\Source;
use App\Models\CovePlan;
use App\Models\DailyPickSet;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Ai\AiClient;
use App\Services\Cove\EditionBuilder;
use ArrayObject;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A guide is planned, curated and briefed, exactly like every other Cove.
 *
 * Until the fold it was the one kind of page nobody could decide anything about:
 * the builder chose its own products from a search, wrote about them, and
 * published. There was no shortlist to curate, nowhere to record why a product
 * was on it, and no way to tell the writer what the piece was for.
 *
 * These tests pin that the three things which were already true of a Daily Cove
 * are now true of an article: the curated shortlist leads, `pick_mode` is
 * obeyed, and `build_instructions` reach the model.
 */
class CoveArticleBuildTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::today()->setTime(12, 0));

        $this->merchant = Merchant::create([
            'source' => Source::Awin->value,
            'external_id' => 'shop',
            'name' => 'Shop',
        ]);
    }

    #[Test]
    public function an_approved_guide_publishes_at_its_slug(): void
    {
        $this->shelf();
        $plan = $this->plan();

        $edition = app(EditionBuilder::class)->buildArticle($plan);

        $this->assertNotNull($edition);
        $this->assertSame(CoveKind::Guide, $edition->kind);
        $this->assertSame('beste-koptelefoons', $edition->slug);
        $this->assertNull($edition->drop_date);
        $this->assertSame(PublishStatus::Published, $edition->status);

        // The address a reader reaches it by is unchanged from the old `guides`
        // table, which is the whole point of folding rather than replacing.
        $this->assertSame('guides/beste-koptelefoons', $edition->kind->path($edition->slug));
        $this->assertSame($edition->id, $plan->fresh()->edition_id);
    }

    #[Test]
    public function the_shortlist_is_a_price_ladder_of_one_product_per_brand(): void
    {
        $this->shelf();
        $plan = $this->plan();

        $edition = app(EditionBuilder::class)->buildArticle($plan);

        $groups = $edition->picks()->with('group')->get()->map(fn ($p) => $p->group);

        // One per brand: seven versions of the same thing at seven prices looks
        // like a comparison and offers no choice.
        $this->assertSame(
            $groups->pluck('brand')->unique()->count(),
            $groups->count(),
        );

        // And cheapest first, so it reads as a ladder rather than as a ranking
        // we would then have to defend.
        $prices = $groups->pluck('min_price')->all();
        $sorted = $prices;
        sort($sorted);
        $this->assertSame($sorted, $prices);
    }

    #[Test]
    public function a_locked_guide_publishes_exactly_the_curated_list_in_order(): void
    {
        $this->shelf();

        /*
         * Curated in deliberately anti-price order — most expensive first — so
         * that a page which came back as a ladder would prove the curator's
         * decision had been overruled.
         */
        $chosen = collect([
            $this->product('Studiokoptelefoon', 39900, 'Beyer'),
            $this->product('Reiskoptelefoon', 24900, 'Bose'),
            $this->product('Sportkoptelefoon', 19900, 'Jabra'),
            $this->product('Kinderkoptelefoon', 9900, 'Puro'),
            $this->product('Budgetkoptelefoon', 4900, 'Koss'),
        ]);

        $plan = $this->plan(PickMode::Locked);

        foreach ($chosen as $rank => $group) {
            $plan->items()->create(['group_id' => $group->id, 'rank' => $rank + 1]);
        }

        $edition = app(EditionBuilder::class)->buildArticle($plan);

        // Exactly the shortlist, in the curator's order, and nothing from the
        // shelf underneath it.
        $this->assertSame(
            $chosen->pluck('id')->all(),
            $edition->picks()->orderBy('rank')->pluck('group_id')->all(),
        );
    }

    #[Test]
    public function a_locked_guide_under_the_floor_does_not_publish(): void
    {
        $this->shelf();

        $plan = $this->plan(PickMode::Locked);
        $plan->items()->create(['group_id' => $this->product('Reiskoptelefoon', 24900, 'Bose')->id, 'rank' => 1]);

        /*
         * A locked plan is the page outright, so a short one is a short page —
         * and finding that out at build time is exactly what the curation
         * screen's warning exists to prevent.
         */
        $this->assertNull(app(EditionBuilder::class)->buildArticle($plan));
    }

    #[Test]
    public function an_open_guide_leads_with_the_curated_products_and_fills_the_rest(): void
    {
        $this->shelf();
        $chosen = $this->product('Nichekoptelefoon', 51900, 'Grado');

        $plan = $this->plan();
        $plan->items()->create(['group_id' => $chosen->id, 'rank' => 1]);

        $edition = app(EditionBuilder::class)->buildArticle($plan);

        $ranked = $edition->picks()->orderBy('rank')->pluck('group_id')->all();

        // Curated first even though it is the most expensive thing on the shelf:
        // the ladder applies to the engine's half, not to a person's decision.
        $this->assertSame($chosen->id, $ranked[0]);
        $this->assertGreaterThan(1, count($ranked));
    }

    #[Test]
    public function the_editors_instructions_and_notes_reach_the_writer(): void
    {
        $this->shelf();
        $chosen = $this->product('Reiskoptelefoon', 24900, 'Bose');

        $plan = $this->plan();
        $plan->update(['build_instructions' => 'Nadruk op reizen, niet op techniek.']);
        $plan->items()->create([
            'group_id' => $chosen->id,
            'rank' => 1,
            'note' => 'Vouwt plat in een rugzak.',
        ]);

        $prompts = $this->captureAiPrompts();

        app(EditionBuilder::class)->buildArticle($plan);

        $sent = implode("\n", $prompts->getArrayCopy());

        // Both halves of the brief. The instruction steers the piece; the note
        // is the reason this product is on the list, and it is the one sentence
        // a search result could never have supplied.
        $this->assertStringContainsString('Nadruk op reizen', $sent);
        $this->assertStringContainsString('Vouwt plat in een rugzak', $sent);
    }

    #[Test]
    public function the_writer_is_told_how_to_link(): void
    {
        $this->shelf();
        $plan = $this->plan();

        $systems = new ArrayObject;

        $this->mock(AiClient::class, function ($mock) use ($systems) {
            $mock->shouldReceive('isEnabled')->andReturn(true);
            $mock->shouldReceive('json')->andReturnUsing(
                function (string $feature, string $system) use ($systems) {
                    $systems->append($system);

                    return [];
                }
            );
        });

        app(EditionBuilder::class)->buildArticle($plan);

        /*
         * The omission this fixes. A Cove's prose has always been given the link
         * contract; the guide prompt never was, which is why guide copy was a
         * wall of text with no internal links on a site whose whole argument is
         * comparison.
         */
        $this->assertStringContainsString('[[product:', implode("\n", $systems->getArrayCopy()));
    }

    #[Test]
    public function a_curators_verdict_outranks_the_models(): void
    {
        $this->shelf();
        $chosen = $this->product('Reiskoptelefoon', 24900, 'Bose');

        $plan = $this->plan();
        $plan->items()->create([
            'group_id' => $chosen->id,
            'rank' => 1,
            'verdict' => 'Beste voor de trein',
        ]);

        $this->mock(AiClient::class, function ($mock) {
            $mock->shouldReceive('isEnabled')->andReturn(true);
            $mock->shouldReceive('json')->andReturn([
                'title' => 'Koptelefoons',
                'intro' => 'Een selectie.',
                'items' => [['verdict' => 'Beste allrounder', 'copy' => 'Compact en stil.']],
            ]);
        });

        $edition = app(EditionBuilder::class)->buildArticle($plan);
        $pick = $edition->picks()->first();

        // Everywhere else on a plan the curator wins; there is no reason the
        // rendered "best for X" should be the exception.
        $this->assertSame('Beste voor de trein', $pick->verdict);
        $this->assertSame('Compact en stil.', $pick->blurb);
    }

    #[Test]
    public function a_guide_with_too_few_products_does_not_publish(): void
    {
        // Two products, against a floor of five. A "best of" with two entries is
        // a list with gaps and reads as one.
        $this->product('Reiskoptelefoon', 24900, 'Bose');
        $this->product('Studiokoptelefoon', 39900, 'Beyer');

        $edition = app(EditionBuilder::class)->buildArticle($this->plan());

        $this->assertNull($edition);
        $this->assertSame(0, DailyPickSet::query()->count());
    }

    #[Test]
    public function an_advice_article_publishes_with_no_products_at_all(): void
    {
        $plan = $this->plan();
        $plan->update([
            'kind' => CoveKind::Advice->value,
            'slug' => 'hoe-lees-je-een-review',
            'title' => 'Hoe lees je een review',
        ]);

        $edition = app(EditionBuilder::class)->buildArticle($plan->fresh());

        // The one kind whose substance is the prose. Demanding products would
        // either block it or pad it with things the writing is not about.
        $this->assertNotNull($edition);
        $this->assertSame(0, $edition->picks()->count());
    }

    #[Test]
    public function a_draft_plan_is_not_built(): void
    {
        $this->shelf();
        $plan = $this->plan();
        $plan->update(['status' => 'draft']);

        // A draft is somebody thinking out loud, and pressing a button is not a
        // reason to publish it.
        $this->assertNull(app(EditionBuilder::class)->buildArticle($plan->fresh()));
    }

    #[Test]
    public function rebuilding_refreshes_the_page_without_republishing_it(): void
    {
        $this->shelf();
        $plan = $this->plan();

        $first = app(EditionBuilder::class)->buildArticle($plan);
        $publishedAt = $first->published_at;

        $this->travel(3)->days();
        $second = app(EditionBuilder::class)->buildArticle($plan->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DailyPickSet::query()->count());

        /*
         * Stamping `now()` on every refresh would make a months-old guide look
         * new to a crawler each time stock moved, which is the fastest way to
         * teach one to stop believing the date.
         */
        $this->assertSame($publishedAt->toDateTimeString(), $second->published_at->toDateTimeString());
        $this->assertTrue($second->last_checked_at->gt($publishedAt));
    }

    #[Test]
    public function an_authored_guide_costs_nothing_to_publish(): void
    {
        $this->shelf();
        $plan = $this->plan();
        $plan->update([
            'blurb' => 'Wat je moet weten voor je er een koopt.',
            'body' => 'Let op pasvorm en ruisonderdrukking.',
        ]);

        $prompts = $this->captureAiPrompts();

        $edition = app(EditionBuilder::class)->buildArticle($plan->fresh());

        // Authored prose wins outright and skips the model: generating a second
        // article and throwing it away is spend with no output.
        $this->assertSame('Let op pasvorm en ruisonderdrukking.', $edition->body);
        $this->assertSame('planned', $edition->editorial_source);
        $this->assertSame([], $prompts->getArrayCopy());
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function captureAiPrompts(): ArrayObject
    {
        $prompts = new ArrayObject;

        $this->mock(AiClient::class, function ($mock) use ($prompts) {
            $mock->shouldReceive('isEnabled')->andReturn(true);
            $mock->shouldReceive('json')->andReturnUsing(
                function (string $feature, string $system, string $prompt) use ($prompts) {
                    $prompts->append($prompt);

                    return ['title' => 'Koptelefoons', 'intro' => 'Een selectie.'];
                }
            );
        });

        return $prompts;
    }

    private function plan(PickMode $mode = PickMode::Open): CovePlan
    {
        return CovePlan::create([
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Guide->value,
            'slug' => 'beste-koptelefoons',
            'title' => 'Beste koptelefoons',
            'focus_keyphrase' => 'koptelefoon',
            'status' => 'approved',
            'pick_mode' => $mode->value,
        ]);
    }

    /** Enough of a shelf that the guide floor is clearable. */
    private function shelf(): void
    {
        foreach (['Sony', 'Sennheiser', 'JBL', 'Philips', 'Marshall', 'AKG'] as $i => $brand) {
            $this->product("{$brand} koptelefoon", 5000 + $i * 4000, $brand);
        }
    }

    private function product(string $title, int $price, string $brand): ProductGroup
    {
        $group = ProductGroup::create([
            'market' => Market::BeNl,
            'identity_key' => 'k'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => $title,
            'slug' => 'p-'.bin2hex(random_bytes(3)),
            'brand' => $brand,
            'category' => 'audio',
            'image_url' => 'https://img.test/x.jpg',
            'min_price' => $price,
            'merchant_count' => 2,
            'in_stock' => true,
            'giftable' => true,
            'worth_showing' => true,
            'surprise_score' => 50,
        ]);

        Product::create([
            'source' => Source::Awin,
            'market' => Market::BeNl,
            'merchant_id' => $this->merchant->id,
            'group_id' => $group->id,
            'external_id' => 'e'.bin2hex(random_bytes(5)),
            'title' => $title,
            'price' => $price,
            'currency' => 'EUR',
            'affiliate_url' => 'https://example.test/buy',
            'availability' => Availability::InStock,
            'status' => ProductStatus::Active,
            'identity_key' => $group->identity_key,
        ]);

        return $group;
    }
}

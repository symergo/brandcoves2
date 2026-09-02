<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\PickMode;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\CovePlan;
use App\Models\CovePlanItem;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Ai\AiClient;
use App\Services\Cove\EditionBuilder;
use App\Services\Curation\PlanCurator;
use ArrayObject;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A Cove is written about the products a person chose.
 *
 * The old arrangement was the other way round: the engine picked, and the model
 * wrote about whatever it was handed. These tests pin the inversion — that the
 * shortlist decides the page, in the order it was curated, and that a locked
 * plan gets exactly what it asked for and nothing else.
 */
class CovePlanCurationTest extends TestCase
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
    public function the_curated_products_lead_the_edition_in_the_curators_order(): void
    {
        $this->seedFinds();

        $first = $this->find('Handgemaakte kruidenpers', 4500, 'Keuken', 5);
        $second = $this->find('Botanisch droogrek', 8900, 'Wonen', 5);

        // Deliberately low surprise scores: on the engine's ranking neither of
        // these gets anywhere near the page. That is the point of curating.
        $plan = $this->plan();
        $plan->items()->create(['group_id' => $second->id, 'rank' => 1]);
        $plan->items()->create(['group_id' => $first->id, 'rank' => 2]);

        $edition = app(EditionBuilder::class)->build(Market::BeNl);

        $this->assertNotNull($edition);
        $this->assertSame(
            [$second->id, $first->id],
            $edition->picks()->orderBy('rank')->limit(2)->pluck('group_id')->all(),
        );
    }

    #[Test]
    public function an_open_plan_lets_the_engine_fill_the_rest(): void
    {
        $this->seedFinds();
        $chosen = $this->find('Handgemaakte kruidenpers', 4500, 'Keuken', 5);

        $plan = $this->plan(PickMode::Open);
        $plan->items()->create(['group_id' => $chosen->id, 'rank' => 1]);

        $edition = app(EditionBuilder::class)->build(Market::BeNl);

        $this->assertSame(
            (int) config('giftcoves.picks.per_day'),
            $edition->picks()->count(),
        );
    }

    #[Test]
    public function a_locked_plan_publishes_exactly_the_shortlist(): void
    {
        /*
         * The engine has eight perfectly good candidates sitting there. A
         * locked plan means none of them appear — otherwise "locked" is a
         * preference rather than a decision, and an editor who counted three
         * products gets seven.
         */
        $this->seedFinds();

        $chosen = collect([
            $this->find('Kruidenpers', 4500, 'Keuken', 1),
            $this->find('Droogrek', 8900, 'Wonen', 1),
            $this->find('Vijzel', 2900, 'Keuken', 1),
        ]);

        $plan = $this->plan(PickMode::Locked);

        $chosen->each(fn (ProductGroup $group, int $i) => $plan->items()->create([
            'group_id' => $group->id,
            'rank' => $i + 1,
        ]));

        $edition = app(EditionBuilder::class)->build(Market::BeNl);

        $this->assertNotNull($edition);
        $this->assertSame(
            $chosen->pluck('id')->all(),
            $edition->picks()->orderBy('rank')->pluck('group_id')->all(),
        );
    }

    #[Test]
    public function a_locked_plan_under_the_floor_publishes_nothing(): void
    {
        /*
         * Two products is not a page. The floor is about the reader rather than
         * about who chose the products — but it has to be visible before 06:00,
         * which is what `isBuildable()` is for and what the curation screen
         * warns on.
         */
        $this->seedFinds();

        $plan = $this->plan(PickMode::Locked);
        $plan->items()->create(['group_id' => $this->find('Kruidenpers', 4500)->id, 'rank' => 1]);
        $plan->items()->create(['group_id' => $this->find('Vijzel', 2900)->id, 'rank' => 2]);

        $this->assertFalse($plan->isBuildable());
        $this->assertNull(app(EditionBuilder::class)->build(Market::BeNl));
    }

    #[Test]
    public function a_curated_product_that_sells_out_is_dropped_at_render_not_at_build(): void
    {
        /*
         * Two different moments, and the difference matters. Dropping it at
         * build would silently shorten a locked page and lose the curator's
         * note with it; keeping it and rendering it would offer a product
         * nobody can buy. So the pick is written and the page filters it.
         */
        $this->seedFinds();
        $soldOut = $this->find('Uitverkochte pers', 4500, 'Keuken', 5);

        $plan = $this->plan();
        $plan->items()->create(['group_id' => $soldOut->id, 'rank' => 1]);

        app(EditionBuilder::class)->build(Market::BeNl);

        $soldOut->update(['in_stock' => false]);

        $this->get('/be-nl/cadeautips')
            ->assertOk()
            ->assertDontSee('Uitverkochte pers');
    }

    #[Test]
    public function a_source_that_may_not_be_mirrored_is_stored_as_a_decision(): void
    {
        /*
         * Invariant 6. An Amazon pick holds the ASIN and nothing a visitor
         * reads — no group, no title, no price — because those are re-fetched
         * live at render and may not be kept.
         */
        $this->seedFinds();

        $plan = $this->plan();
        app(PlanCurator::class)->add($plan, 'amazon:B01ABCDEFG');

        $edition = app(EditionBuilder::class)->build(Market::BeNl);

        $pick = $edition->picks()->whereNotNull('amazon_asin')->first();

        $this->assertNotNull($pick);
        $this->assertSame('B01ABCDEFG', $pick->amazon_asin);
        $this->assertNull($pick->group_id);
    }

    #[Test]
    public function a_mirrorable_source_may_not_be_curated_by_external_id(): void
    {
        // bol is in the catalogue, with offers, a price history and a
        // comparison behind it. Storing it by external id would make a second,
        // unlinked copy of a product the site can already compare properly.
        $this->expectException(InvalidArgumentException::class);

        app(PlanCurator::class)->add($this->plan(), 'bol:9200000123456');
    }

    #[Test]
    public function a_product_from_another_market_cannot_be_curated(): void
    {
        // Invariant 2: the same product elsewhere has different tax, shipping
        // and availability, and would present a price the reader cannot pay.
        $foreign = ProductGroup::create([
            'market' => Market::NlNl,
            'identity_key' => 'k-foreign',
            'identity_kind' => 'ean',
            'title' => 'Nederlandse pers',
            'slug' => 'nl-pers',
            'image_url' => 'https://img.test/x.jpg',
            'min_price' => 4500,
            'in_stock' => true,
        ]);

        $this->expectException(InvalidArgumentException::class);

        app(PlanCurator::class)->add($this->plan(), 'group:'.$foreign->id);
    }

    #[Test]
    public function reordering_renumbers_from_one(): void
    {
        $plan = $this->plan();

        $items = collect(['Een', 'Twee', 'Drie'])->map(
            fn (string $title, int $i) => $plan->items()->create([
                'group_id' => $this->find($title, 1000 + $i)->id,
                'rank' => $i + 1,
            ]),
        );

        $reversed = $items->pluck('id')->reverse()->values()->all();

        app(PlanCurator::class)->reorder($plan, $reversed);

        $this->assertSame($reversed, $plan->items()->pluck('id')->all());
        $this->assertSame([1, 2, 3], $plan->items()->pluck('rank')->all());
    }

    #[Test]
    public function a_persona_is_not_written_into_the_daily_repeat_memory(): void
    {
        /*
         * A persona is permanent and rarely rebuilt. Letting its picks into the
         * rolling 90-day memory would strip whatever is on it out of the next
         * three months of editions, for no reader-visible benefit — nobody
         * experiences a persona and a Tuesday as the same column.
         */
        $this->seedFinds();
        $shared = $this->find('Gedeelde vondst', 4500, 'Keuken', 5);

        $persona = CovePlan::create([
            'market' => Market::BeNl->value,
            'kind' => 'persona',
            'slug' => 'de-kruidenliefhebber',
            'title' => 'De kruidenliefhebber',
            'status' => 'approved',
            'pick_mode' => PickMode::Locked->value,
        ]);

        collect([$shared, $this->find('Droogrek', 8900, 'Wonen', 1), $this->find('Vijzel', 2900, 'Keuken', 1)])
            ->each(fn (ProductGroup $g, int $i) => $persona->items()->create(['group_id' => $g->id, 'rank' => $i + 1]));

        $this->assertNotNull(app(EditionBuilder::class)->buildPersona($persona));

        // Now the Daily may still use it.
        $plan = $this->plan(PickMode::Locked);
        collect([$shared, $this->find('Weckpot', 1900, 'Keuken', 1), $this->find('Etiketten', 900, 'Kantoor', 1)])
            ->each(fn (ProductGroup $g, int $i) => $plan->items()->create(['group_id' => $g->id, 'rank' => $i + 1]));

        $edition = app(EditionBuilder::class)->build(Market::BeNl);

        $this->assertContains($shared->id, $edition->picks()->pluck('group_id')->all());
    }

    #[Test]
    public function the_planner_drafts_plans_that_already_have_products_on_them(): void
    {
        /*
         * The blank page is the thing this prevents. A plan that opens empty
         * asks an editor to invent seven products from nothing; one that opens
         * with the engine's guess asks them to react, which is the job people
         * are good at.
         */
        $this->seedFinds(40);

        $this->artisan('bc:plan-coves', ['--market' => Market::BeNl->value, '--days' => 3])
            ->assertSuccessful();

        $plans = CovePlan::query()->where('market', Market::BeNl->value)->get();

        $this->assertNotEmpty($plans);

        foreach ($plans as $plan) {
            $this->assertGreaterThan(0, $plan->items()->count(), "plan {$plan->id} opened empty");
        }
    }

    #[Test]
    public function two_drafted_days_are_not_offered_the_same_products(): void
    {
        /*
         * The rolling repeat memory reads `daily_picks`, and none of these days
         * has been built — so without an in-run exclusion the highest-scoring
         * seven products in the market are suggested for every plan, and the
         * whole calendar is one edition repeated.
         */
        $this->seedFinds(40);

        $this->artisan('bc:plan-coves', ['--market' => Market::BeNl->value, '--days' => 3])
            ->assertSuccessful();

        $perPlan = CovePlan::query()
            ->where('market', Market::BeNl->value)
            ->get()
            ->map(fn (CovePlan $plan) => $plan->items()->pluck('group_id')->all());

        $all = $perPlan->flatten();

        $this->assertSame(
            $all->count(),
            $all->unique()->count(),
            'the planner suggested the same product on more than one day',
        );
    }

    #[Test]
    public function a_second_planner_run_never_appends_to_a_curated_plan(): void
    {
        // Re-running the planner is routine and idempotent. Appending a second
        // set of seven underneath somebody's curation is the kind of edit that
        // is only noticed after the page has published.
        $this->seedFinds(40);

        $this->artisan('bc:plan-coves', ['--market' => Market::BeNl->value, '--days' => 2])->assertSuccessful();

        $plan = CovePlan::query()->where('market', Market::BeNl->value)->firstOrFail();
        $plan->items()->delete();
        $plan->items()->create(['group_id' => $this->find('Enige keuze', 4500)->id, 'rank' => 1]);

        $this->artisan('bc:plan-coves', ['--market' => Market::BeNl->value, '--days' => 2])->assertSuccessful();

        $this->assertSame(1, $plan->items()->count());
    }

    #[Test]
    public function the_planner_can_be_told_to_draft_themes_only(): void
    {
        $this->seedFinds(40);

        $this->artisan('bc:plan-coves', [
            '--market' => Market::BeNl->value,
            '--days' => 2,
            '--no-products' => true,
        ])->assertSuccessful();

        $this->assertSame(0, CovePlanItem::query()->count());
        $this->assertGreaterThan(0, CovePlan::query()->count());
    }

    #[Test]
    public function the_editors_instructions_reach_the_writer(): void
    {
        /*
         * The gap the field fills. A plan could carry finished prose, a note to
         * whoever reads the plan, and a reason per product — and nowhere to say
         * how the article as a whole should be written. An editor who wanted
         * that had exactly one option: write the whole thing by hand.
         */
        $this->seedFinds();

        $plan = $this->plan();
        $plan->update(['build_instructions' => 'Kort houden. Nadruk op nostalgie, niet op techniek.']);
        $plan->items()->create(['group_id' => $this->find('Kruidenpers', 4500)->id, 'rank' => 1]);

        $prompts = $this->captureAiPrompts();

        app(EditionBuilder::class)->build(Market::BeNl);

        $this->assertTrue(
            collect($prompts->getArrayCopy())->contains(fn (string $p) => str_contains($p, 'Nadruk op nostalgie')),
            'the instructions never reached the model',
        );
    }

    #[Test]
    public function instructions_are_not_sent_when_the_prose_is_already_written(): void
    {
        /*
         * Authored prose wins outright and skips the model, so a brief for it
         * is read by nobody. Asserted rather than assumed, because the failure
         * is a field that looks like it works — and the screen says so too.
         */
        $this->seedFinds();

        $plan = $this->plan();
        $plan->update([
            'build_instructions' => 'Kort houden.',
            'editorial' => 'Dit is al geschreven.',
        ]);

        $prompts = $this->captureAiPrompts();

        $edition = app(EditionBuilder::class)->build(Market::BeNl);

        $this->assertSame('Dit is al geschreven.', $edition->editorial);
        $this->assertSame('planned', $edition->editorial_source);
        $this->assertFalse(collect($prompts->getArrayCopy())->contains(fn (string $p) => str_contains($p, 'Kort houden')));
    }

    /**
     * Every prompt the builder sends, collected as it goes.
     *
     * An ArrayObject rather than an array: the collector is handed out before
     * the build runs, and returning an array would hand back a copy taken while
     * it was still empty.
     */
    private function captureAiPrompts(): ArrayObject
    {
        $prompts = new ArrayObject;

        $this->mock(AiClient::class, function ($mock) use ($prompts) {
            $mock->shouldReceive('isEnabled')->andReturn(true);
            $mock->shouldReceive('json')->andReturnUsing(
                function (string $feature, string $system, string $prompt) use ($prompts) {
                    $prompts->append($prompt);

                    // Names a product, so the coverage retry does not fire and
                    // double the prompts being inspected.
                    return ['editorial' => 'Iets over [[product:1]].'];
                }
            );
        });

        return $prompts;
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function plan(PickMode $mode = PickMode::Open): CovePlan
    {
        return CovePlan::create([
            'market' => Market::BeNl->value,
            'drop_date' => CarbonImmutable::today()->toDateString(),
            'title' => 'Gecureerd',
            'status' => 'approved',
            'pick_mode' => $mode->value,
        ]);
    }

    private function seedFinds(int $count = 8): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->find("Bijzonder apparaat {$i}", 3000 + $i * 1500, "Categorie{$i}", 90 - $i);
        }
    }

    private function find(string $title, int $price, ?string $category = null, float $score = 60): ProductGroup
    {
        $group = ProductGroup::create([
            'market' => Market::BeNl,
            'identity_key' => 'k'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => $title,
            'slug' => 'p-'.bin2hex(random_bytes(3)),
            'category' => $category,
            'image_url' => 'https://img.test/x.jpg',
            'min_price' => $price,
            'merchant_count' => 1,
            'in_stock' => true,
            'giftable' => true,
            'surprise_score' => $score,
            'surprise_breakdown' => ['lexical' => 30],
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

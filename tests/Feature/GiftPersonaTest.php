<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\PickMode;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\CovePlan;
use App\Models\DailyPickSet;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Cove\EditionBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Gift personas: the Coves that are about a person rather than a day.
 *
 * Half of this file exists for one Postgres detail. `ORDER BY drop_date DESC`
 * sorts NULLS FIRST, and a persona has no drop date — so the moment one exists,
 * every `orderByDesc('drop_date')->first()` in the codebase returns *it* as
 * today's edition. Nothing errors, nothing looks broken, and the wrong page is
 * served on the front page and at /daily.
 *
 * Each of those surfaces is asserted separately rather than trusting the scope,
 * because the failure is silent everywhere it can happen.
 */
class GiftPersonaTest extends TestCase
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
    public function a_persona_is_built_and_served_at_its_permanent_url(): void
    {
        $this->buildPersona();

        $this->get('/be-nl/gift-ideas/de-kruidenliefhebber')
            ->assertOk()
            ->assertSee('De kruidenliefhebber')
            ->assertSee('Kruidenpers');
    }

    #[Test]
    public function a_persona_appears_on_the_gift_ideas_shelf(): void
    {
        $this->buildPersona();

        $this->get('/be-nl/gift-ideas')
            ->assertOk()
            ->assertSee('De kruidenliefhebber');
    }

    #[Test]
    public function rebuilding_a_persona_updates_it_in_place(): void
    {
        // Idempotent for the same reason a Daily is: an editor presses the
        // button, a redeploy interrupts, a job retries.
        $plan = $this->buildPersona();

        $first = DailyPickSet::query()->personas()->firstOrFail();
        app(EditionBuilder::class)->buildPersona($plan);

        $this->assertSame(1, DailyPickSet::query()->personas()->count());
        $this->assertSame(
            $first->published_at->toDateTimeString(),
            DailyPickSet::query()->personas()->firstOrFail()->published_at->toDateTimeString(),
            'a rebuild republished the page, which would make a crawler stop believing the date',
        );
    }

    #[Test]
    public function a_persona_is_never_served_as_todays_edition(): void
    {
        // The NULLS FIRST trap, on the two pages that ask for "the latest".
        $this->seedFinds();
        app(EditionBuilder::class)->build(Market::BeNl);
        $this->buildPersona();

        $this->get('/be-nl/daily')->assertOk()->assertDontSee('De kruidenliefhebber');
        $this->get('/be-nl')->assertOk()->assertDontSee('De kruidenliefhebber');
    }

    #[Test]
    public function a_persona_is_not_in_the_daily_archive_strip(): void
    {
        $this->seedFinds();

        app(EditionBuilder::class)->build(Market::BeNl, CarbonImmutable::yesterday());
        app(EditionBuilder::class)->build(Market::BeNl);
        $this->buildPersona();

        $this->get('/be-nl/daily')
            ->assertOk()
            ->assertDontSee('De kruidenliefhebber');
    }

    #[Test]
    public function the_sitemap_lists_a_persona_by_slug_and_never_as_a_dateless_daily(): void
    {
        $this->buildPersona();

        $response = $this->get('/sitemap/be-nl/1.xml')->assertOk();

        $response->assertSee('/be-nl/gift-ideas/de-kruidenliefhebber', escape: false);
        // The shape a null drop_date would produce if it reached the daily loop.
        $response->assertDontSee('/be-nl/daily/</loc>', escape: false);
    }

    #[Test]
    public function a_draft_persona_is_not_reachable(): void
    {
        // Same rule as a Daily: approval is what publishes, and a slug being
        // guessable is not a licence to read an unapproved page.
        $plan = $this->plan();
        $plan->update(['status' => 'draft']);

        $this->assertNull(app(EditionBuilder::class)->buildPersona($plan));
        $this->get('/be-nl/gift-ideas/de-kruidenliefhebber')->assertNotFound();
    }

    #[Test]
    public function a_persona_cannot_hold_a_date(): void
    {
        /*
         * Enforced by a CHECK constraint rather than by a convention, because
         * `approvedFor()` matches on (market, drop_date): a persona that
         * quietly acquired a date would be picked up by the 06:00 build and
         * published as that morning's Daily Cove.
         */
        $this->expectException(QueryException::class);

        CovePlan::create([
            'market' => Market::BeNl->value,
            'kind' => 'persona',
            'slug' => 'onmogelijk',
            'drop_date' => CarbonImmutable::today()->toDateString(),
            'title' => 'Onmogelijk',
            'status' => 'draft',
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function buildPersona(): CovePlan
    {
        $plan = $this->plan();

        $this->assertNotNull(app(EditionBuilder::class)->buildPersona($plan));

        return $plan;
    }

    private function plan(): CovePlan
    {
        $plan = CovePlan::create([
            'market' => Market::BeNl->value,
            'kind' => 'persona',
            'slug' => 'de-kruidenliefhebber',
            'title' => 'De kruidenliefhebber',
            'blurb' => 'Voor wie alles zelf droogt.',
            'status' => 'approved',
            'pick_mode' => PickMode::Locked->value,
        ]);

        collect(['Kruidenpers', 'Droogrek', 'Vijzel'])->each(
            fn (string $title, int $i) => $plan->items()->create([
                'group_id' => $this->find($title, 2000 + $i * 1000)->id,
                'rank' => $i + 1,
            ]),
        );

        return $plan;
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

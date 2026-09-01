<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\PersonaScene;
use App\Enums\PickMode;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\CovePlan;
use App\Models\DailyPickSet;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Cove\EditionBuilder;
use App\Services\Seo\Alternates;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ValueError;

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

        /*
         * The home page asserts against the props, not the page text.
         *
         * It used to be `assertDontSee`, which was right while the front page
         * showed no personas at all and became wrong the day it grew a band for
         * them: the persona's name is now on that page legitimately, and a
         * whole-page string search cannot tell the band from the trap. Both
         * halves are stated instead — not today's edition, and on the shelf.
         */
        $this->get('/be-nl')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('today.theme', fn (?string $theme) => $theme !== 'De kruidenliefhebber')
                ->where('personas.0.title', 'De kruidenliefhebber'));
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

    // --- The drawing ---------------------------------------------------------

    #[Test]
    public function the_shelf_and_the_page_carry_the_personas_scene(): void
    {
        $this->persona('de-koffiefanaat', 'De koffiefanaat', Market::BeNl, scene: PersonaScene::Coffee);

        $this->get('/be-nl/gift-ideas')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('personas.0.scene', 'coffee'));

        $this->get('/be-nl/gift-ideas/de-koffiefanaat')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('persona.scene', 'coffee'));
    }

    /**
     * Null is the state every persona written before the field was added is in,
     * and a missing drawing must not be a missing page. The component reads null
     * as `someone` and draws a figure.
     */
    #[Test]
    public function a_persona_with_no_scene_still_renders(): void
    {
        $this->persona('de-thuiskok', 'De thuiskok', Market::BeNl);

        $this->get('/be-nl/gift-ideas')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('personas.0.scene', null));

        $this->get('/be-nl/gift-ideas/de-thuiskok')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('persona.scene', null));
    }

    /**
     * The CHECK is generated from the enum, so this is what stops the two from
     * drifting — a value PHP knows and Postgres does not would only surface as a
     * failed write in production.
     *
     * Inserted through the query builder rather than the model, deliberately.
     * The Eloquent cast throws a `ValueError` on an unknown value before a query
     * is ever sent, so going through `DailyPickSet::create()` asserts the cast
     * and never reaches the constraint — which is the half that would still be
     * standing if somebody added a case to the enum and no migration.
     */
    #[Test]
    public function the_database_refuses_a_scene_the_enum_does_not_know(): void
    {
        $this->expectException(QueryException::class);

        DB::table('daily_pick_sets')->insert([
            'market' => Market::BeNl->value,
            'kind' => 'persona',
            'slug' => 'de-onbekende',
            'theme_title' => 'De onbekende',
            'theme_slug' => 'de-onbekende',
            'status' => 'published',
            'published_at' => '2026-08-20',
            'scene' => 'not-a-real-scene',
        ]);
    }

    /** And the cast is the other half: it refuses before a query is sent. */
    #[Test]
    public function the_model_refuses_a_scene_the_enum_does_not_know(): void
    {
        $this->expectException(ValueError::class);

        DailyPickSet::create([
            'market' => Market::BeNl->value,
            'kind' => 'persona',
            'slug' => 'de-onbekende',
            'theme_title' => 'De onbekende',
            'theme_slug' => 'de-onbekende',
            'status' => 'published',
            'published_at' => '2026-08-20',
            'scene' => 'not-a-real-scene',
        ]);
    }

    /**
     * The drawing is authored, so it travels from the plan to the edition the
     * same way the title and the blurb do. On the plan alone it would be a field
     * you could set and never see.
     */
    #[Test]
    public function the_builder_carries_the_scene_from_the_plan_to_the_edition(): void
    {
        $plan = $this->plan();
        $plan->update(['scene' => PersonaScene::Coffee->value]);

        $edition = app(EditionBuilder::class)->buildPersona($plan->fresh());

        $this->assertNotNull($edition);
        $this->assertSame(PersonaScene::Coffee, $edition->scene);
    }

    #[Test]
    public function the_same_persona_in_two_markets_is_paired_for_hreflang(): void
    {
        $this->persona('de-thuiskok', 'De thuiskok', Market::BeNl);
        $this->persona('de-thuiskok', 'De thuiskok', Market::NlNl);

        $alternates = app(Alternates::class)
            ->for('/be-nl/gift-ideas/de-thuiskok', Market::BeNl);

        $this->assertSame([
            'nl-BE' => url('/be-nl/gift-ideas/de-thuiskok'),
            'nl-NL' => url('/nl-nl/gift-ideas/de-thuiskok'),
        ], $alternates);
    }

    /**
     * The failure this pairing exists to prevent.
     *
     * `gift-ideas` was not in the `Alternates` match, so a persona fell through
     * to the blind market swap and claimed a twin in all five markets. Two
     * markets deliberately carry different personas — a dog one in be-nl, a DIY
     * one in nl-nl — so those claims were 404s, and one bad member is enough for
     * Google to discard the whole cluster.
     */
    #[Test]
    public function a_persona_only_one_market_carries_declares_no_alternates(): void
    {
        $this->persona('de-hondenmens', 'De hondenmens', Market::BeNl);

        $this->assertSame(
            [],
            app(Alternates::class)->for('/be-nl/gift-ideas/de-hondenmens', Market::BeNl),
        );
    }

    #[Test]
    public function an_unpublished_persona_is_not_offered_as_an_alternate(): void
    {
        $this->persona('de-thuiskok', 'De thuiskok', Market::BeNl);
        $this->persona('de-thuiskok', 'De thuiskok', Market::NlNl, published: false);

        $this->assertSame(
            [],
            app(Alternates::class)->for('/be-nl/gift-ideas/de-thuiskok', Market::BeNl),
        );
    }

    #[Test]
    public function the_shelf_itself_is_the_same_page_in_every_market(): void
    {
        // No slug, nothing keyed on a row: the plain segment swap is right, and
        // an empty shelf is still a page that exists.
        $alternates = app(Alternates::class)->for('/be-nl/gift-ideas', Market::BeNl);

        $this->assertSame(url('/nl-nl/gift-ideas'), $alternates['nl-NL'] ?? null);
        $this->assertSame(url('/be-fr/gift-ideas'), $alternates['fr-BE'] ?? null);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function persona(
        string $slug,
        string $title,
        Market $market,
        bool $published = true,
        ?PersonaScene $scene = null,
    ): DailyPickSet {
        return DailyPickSet::create([
            'market' => $market->value,
            'kind' => 'persona',
            'slug' => $slug,
            'theme_title' => $title,
            'theme_slug' => $slug,
            'theme_blurb' => 'Waar het over gaat.',
            'status' => $published ? 'published' : 'draft',
            'published_at' => $published ? '2026-08-20' : null,
            'scene' => $scene?->value,
        ]);
    }

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

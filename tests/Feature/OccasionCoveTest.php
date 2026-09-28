<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PickMode;
use App\Jobs\RefreshPersonaTopLists;
use App\Models\CovePlan;
use App\Models\DailyPickSet;
use App\Models\PersonaTopList;
use App\Services\Cove\EditionBuilder;
use App\Services\Gift\PersonaTopTen;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\MakesGiftShelf;
use Tests\TestCase;

/**
 * Gifts for an occasion (owner, 2026-09-28): a persona's page at its own
 * address, /gift-ideas/occasion/{slug}, in its own row on the shelf. See
 * docs/features/occasion-coves.md.
 */
class OccasionCoveTest extends TestCase
{
    use MakesGiftShelf;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00'));
    }

    #[Test]
    public function an_occasion_is_built_and_read_at_its_own_address(): void
    {
        $this->forbidAi();
        $edition = $this->build(CoveKind::Occasion, 'moederdag', 'Cadeaus voor Moederdag');

        $this->assertSame(CoveKind::Occasion, $edition->kind);

        $this->get('/be-nl/gift-ideas/occasion/moederdag')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('GiftIdeas/Persona')
                ->where('persona.title', 'Cadeaus voor Moederdag')
                ->has('finds', 8));

        // Not at a persona's address: the kinds do not share one.
        $this->get('/be-nl/gift-ideas/moederdag')->assertNotFound();
    }

    #[Test]
    public function a_persona_is_not_read_at_an_occasion_address(): void
    {
        $this->forbidAi();
        $this->build(CoveKind::Persona, 'de-thuiskok', 'De thuiskok');

        $this->get('/be-nl/gift-ideas/de-thuiskok')->assertOk();
        $this->get('/be-nl/gift-ideas/occasion/de-thuiskok')->assertNotFound();
    }

    #[Test]
    public function the_shelf_has_a_row_of_occasions_beside_the_personas(): void
    {
        $this->forbidAi();
        $this->build(CoveKind::Persona, 'de-thuiskok', 'De thuiskok');
        $this->build(CoveKind::Occasion, 'moederdag', 'Cadeaus voor Moederdag');

        $this->get('/be-nl/gift-ideas')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('personas.0.url', '/be-nl/gift-ideas/de-thuiskok')
                ->has('personas', 1)
                ->where('occasions.0.url', '/be-nl/gift-ideas/occasion/moederdag')
                ->has('occasions', 1));
    }

    #[Test]
    public function all_coves_shows_occasions_in_a_section_of_their_own(): void
    {
        /*
         * The persona section is described as "built around a person"; an
         * occasion in it read as a person called Moederdag (owner,
         * 2026-09-28).
         */
        $this->forbidAi();
        $this->build(CoveKind::Persona, 'de-thuiskok', 'De thuiskok');
        $this->build(CoveKind::Occasion, 'moederdag', 'Cadeaus voor Moederdag');

        $sections = collect($this->get('/be-nl/coves')->assertOk()->viewData('page')['props']['sections'])->keyBy('key');

        $this->assertSame(['De thuiskok'], array_column($sections['gift']['coves'], 'title'));
        $this->assertSame(['Cadeaus voor Moederdag'], array_column($sections['occasion']['coves'], 'title'));
    }

    #[Test]
    public function the_sitemap_lists_an_occasion_at_its_address(): void
    {
        $this->forbidAi();
        $this->build(CoveKind::Occasion, 'moederdag', 'Cadeaus voor Moederdag');

        $this->get('/sitemap/be-nl/1.xml')
            ->assertOk()
            ->assertSee('/be-nl/gift-ideas/occasion/moederdag', escape: false);
    }

    #[Test]
    public function an_occasion_gets_the_weekly_top_ten(): void
    {
        $this->forbidAi();
        $edition = $this->build(CoveKind::Occasion, 'moederdag', 'Cadeaus voor Moederdag');

        foreach (range(1, 8) as $i) {
            $this->giftable("Tuinhandschoen {$i}", 2000, ['interest:gardening'], 'Tuin');
        }

        (new RefreshPersonaTopLists(Market::BeNl))->handle(app(PersonaTopTen::class));

        $this->assertTrue(PersonaTopList::query()->where('set_id', $edition->id)->exists());
    }

    #[Test]
    public function the_database_accepts_the_kind(): void
    {
        // The CHECK constraint on both tables names every kind; a kind the
        // enum has and the constraint lacks fails only when first written.
        $plan = CovePlan::create([
            'market' => Market::BeNl->value, 'kind' => 'occasion', 'slug' => 'pensioen',
            'title' => 'Cadeaus voor een pensioen', 'status' => 'draft',
        ]);

        $this->assertSame(CoveKind::Occasion, $plan->fresh()->kind);
    }

    /** A published plan of this kind with eight products tagged for gardening. */
    private function build(CoveKind $kind, string $slug, string $title): DailyPickSet
    {
        $plan = CovePlan::create([
            'market' => Market::BeNl->value,
            'kind' => $kind->value,
            'slug' => $slug,
            'title' => $title,
            'status' => 'approved',
            'writer' => 'authored',
            'editorial' => 'Een opening.',
            'pick_mode' => PickMode::Locked->value,
            'brief' => ['interests' => ['gardening']],
        ]);

        foreach (range(1, 8) as $i) {
            $group = $this->giftable("{$title} product {$i}", 2500, ['interest:gardening'], 'Tuin');
            $plan->items()->create(['group_id' => $group->id, 'rank' => $i]);
        }

        $edition = app(EditionBuilder::class)->buildPersona($plan);
        $this->assertInstanceOf(DailyPickSet::class, $edition);

        return $edition;
    }
}

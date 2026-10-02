<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\CovePlan;
use App\Models\DailyPickSet;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Cove\EditionBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Daily Cove.
 *
 * Two things carry the feature and both are tested hard: the answer must never
 * reach the client before the round is over, and building the same day twice
 * must produce one edition rather than two.
 */
class DailyCoveTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Freeze the clock after the drop time.
         *
         * An edition is built at 06:00 for a 09:00 publish, and `published()`
         * hides it until then — correctly. Without a fixed clock this suite
         * passes when it is run in the evening and 404s when it is run in the
         * morning, which is the worst kind of failing test: one that blames
         * whoever happened to run it.
         */
        $this->travelTo(CarbonImmutable::today()->setTime(12, 0));

        $this->merchant = Merchant::create([
            'source' => Source::Awin->value,
            'external_id' => 'shop',
            'name' => 'Shop',
        ]);
    }

    private function find(string $title, int $price, ?string $category = null, float $score = 60, ?string $description = null): ProductGroup
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
            'surprise_breakdown' => ['lexical' => 30, 'exclusivity' => 15],
        ]);

        Product::create([
            'source' => Source::Awin,
            'market' => Market::BeNl,
            'merchant_id' => $this->merchant->id,
            'group_id' => $group->id,
            'external_id' => 'e'.bin2hex(random_bytes(5)),
            'title' => $title,
            'description' => $description,
            'price' => $price,
            'currency' => 'EUR',
            'affiliate_url' => 'https://example.test/buy',
            'availability' => Availability::InStock,
            'status' => ProductStatus::Active,
            'identity_key' => $group->identity_key,
        ]);

        return $group;
    }

    private function seedFinds(int $count = 8): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->find("Bijzonder apparaat {$i}", 3000 + $i * 1500, "Categorie{$i}", 90 - $i);
        }
    }

    /**
     * An approved plan for a day, themed on the fixtures' own word, with prose.
     *
     * Every Daily needs both since 2026-09-27: finds its theme names (not the
     * pool), and editorial (not a bare page). An authored plan is how a test
     * that is about something else gets both without a model.
     *
     * @param  list<string>  $queries
     * @param  array<string, mixed>  $extra
     */
    private function planDay(?CarbonImmutable $date = null, array $queries = ['apparaat'], array $extra = []): CovePlan
    {
        return CovePlan::create([
            'market' => Market::BeNl->value,
            'drop_date' => ($date ?? CarbonImmutable::today())->toDateString(),
            'title' => 'Apparaten van de dag',
            'queries' => $queries,
            'status' => 'approved',
            'writer' => 'authored',
            'editorial' => 'Een dag vol vreemde apparaten.',
            ...$extra,
        ]);
    }

    private function buildEdition(): DailyPickSet
    {
        $this->seedFinds();
        $this->planDay();

        $edition = app(EditionBuilder::class)->build(Market::BeNl);
        $this->assertNotNull($edition);

        return $edition;
    }

    // ── The edition ───────────────────────────────────────────────────────

    #[Test]
    public function building_the_same_day_twice_produces_one_edition(): void
    {
        $this->seedFinds();
        $this->planDay();
        $builder = app(EditionBuilder::class);

        $builder->build(Market::BeNl);
        $builder->build(Market::BeNl);

        // The scheduler retries, redeploys interrupt jobs, and an operator will
        // run this by hand. None of those may produce two Tuesdays.
        $this->assertSame(1, DailyPickSet::query()->count());
        $this->assertSame(
            (int) config('giftcoves.picks.per_day'),
            DailyPickSet::query()->firstOrFail()->picks()->count(),
        );
    }

    #[Test]
    public function an_edition_never_repeats_a_recent_find(): void
    {
        $this->seedFinds(16);
        $this->planDay();
        $this->planDay(CarbonImmutable::tomorrow());
        $builder = app(EditionBuilder::class);

        $today = $builder->build(Market::BeNl);
        $tomorrow = $builder->build(Market::BeNl, CarbonImmutable::tomorrow());

        $overlap = array_intersect(
            $today->picks()->pluck('group_id')->all(),
            $tomorrow->picks()->pluck('group_id')->all(),
        );

        // Repeating inside the memory window is the clearest possible signal
        // that nobody is choosing these, and it is the first thing a returning
        // visitor notices — they remember the odd ones.
        $this->assertSame([], $overlap);
    }

    #[Test]
    public function a_thin_catalogue_produces_no_edition_at_all(): void
    {
        $this->find('Enige vondst', 5000, 'Categorie');

        // A three-item edition is worse than none. Publishing a thin one on a
        // bad catalogue day teaches people the page is not worth opening.
        $this->assertNull(app(EditionBuilder::class)->build(Market::BeNl));
    }

    #[Test]
    public function tomorrows_edition_is_not_reachable_by_url(): void
    {
        $this->buildEdition();

        // A future edition is a draft. Reachable by URL, it would leak
        // tomorrow's theme and finds. The dated form 404s too — nothing is
        // published on that date, so there is nothing to redirect to.
        $this->get('/be-nl/daily/'.CarbonImmutable::tomorrow()->toDateString())
            ->assertNotFound();
    }

    #[Test]
    public function an_archived_edition_keeps_its_own_url(): void
    {
        $edition = $this->buildEdition();

        /*
         * The archive is the SEO asset. A column whose past editions 404 has
         * nothing to link to and nothing indexed.
         *
         * Addressed by name, under the market's own word for the section:
         * /be-nl/tips/vondsten-voor-thuiswerkers.
         */
        $this->get('/be-nl/tips/'.$edition->slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('finds'));

        /*
         * Every address this page has ever had still resolves, permanently and
         * in one hop. Both are indexed and the dated one is in three months of
         * digest emails; a chain through the old dated form would cost link
         * equity on the way.
         */
        $this->get('/be-nl/daily/'.$edition->slug)
            ->assertRedirect('/be-nl/tips/'.$edition->slug)
            ->assertStatus(301);

        $this->get('/be-nl/daily/'.$edition->drop_date->toDateString())
            ->assertRedirect('/be-nl/tips/'.$edition->slug)
            ->assertStatus(301);

        $this->get('/be-nl/tips/'.$edition->drop_date->toDateString())
            ->assertRedirect('/be-nl/tips/'.$edition->slug)
            ->assertStatus(301);

        // /daily with no address is today's edition, wherever it now lives.
        $this->get('/be-nl/daily')
            ->assertRedirect('/be-nl/tips')
            ->assertStatus(301);
    }

    #[Test]
    public function every_word_this_section_has_used_still_resolves(): void
    {
        /*
         * The segment has been spelled three ways: `/daily`, then a localised
         * word per market for about two hours on 2026-09-03, and now `tips`.
         *
         * All of them keep working, permanently and in one hop. The archive is
         * the SEO asset the daily column exists to build, and a column whose
         * past addresses 404 has thrown that away — which is just as true of a
         * spelling that only ever lived for an afternoon, because the links
         * made during it are the ones nobody can find again to fix.
         */
        $edition = $this->buildEdition();

        foreach (['cadeautips', 'idees-cadeaux', 'gift-tips', 'ideas-regalo'] as $retired) {
            $this->get("/be-nl/{$retired}/{$edition->slug}")
                ->assertRedirect('/be-nl/tips/'.$edition->slug)
                ->assertStatus(301);

            $this->get("/be-nl/{$retired}")
                ->assertRedirect('/be-nl/tips')
                ->assertStatus(301);
        }

        // Including the dated form on a retired segment, which still lands on
        // the named edition rather than bouncing through a second redirect.
        $this->get('/be-nl/cadeautips/'.$edition->drop_date->toDateString())
            ->assertRedirect('/be-nl/tips/'.$edition->slug)
            ->assertStatus(301);
    }

    // ── The theme is the page, not a bias on it ──────────────────────────

    #[Test]
    public function a_themed_edition_publishes_nothing_off_theme(): void
    {
        /*
         * The off-theme finds here outscore every themed one, which is exactly
         * the shape that used to fill the page with them: the general pool is
         * ordered by surprise score, and a themed day's products are ordinary
         * enough to rank below whatever is strangest in the catalogue that
         * morning. nl-nl's 4 Sep 2026 home-gym edition published four such
         * strangers under an article about home gyms.
         */
        $this->seedFinds();

        /*
         * Two categories between six products, which is what a theme looks
         * like: the feed's categories are leaf labels, so "the gym in the spare
         * room" is Hometrainer and Halterset and nothing else. The variety rule
         * in `spread` therefore runs out after two, and what happens next is
         * the whole of this test.
         */
        foreach ([
            'Hometrainer compact' => 'Hometrainer',
            'Hometrainer opvouwbaar' => 'Hometrainer',
            'Hometrainer met display' => 'Hometrainer',
            'Halterset gietijzer' => 'Halterset',
            'Halterset vinyl' => 'Halterset',
            'Halterset verstelbaar' => 'Halterset',
        ] as $i => $title) {
            $this->find($i, 4000 + strlen($i) * 90, $title, 40);
        }

        CovePlan::create([
            'market' => Market::BeNl->value,
            'drop_date' => CarbonImmutable::today()->toDateString(),
            'title' => 'De sportschool op de logeerkamer',
            'queries' => ['hometrainer', 'halterset', 'dumbbell'],
            'status' => 'approved',
            'writer' => 'authored',
            'editorial' => 'Een sportschool thuis.',
        ]);

        $edition = app(EditionBuilder::class)->build(Market::BeNl);
        $this->assertNotNull($edition);

        $titles = $edition->picks()->with('group')->get()->map(fn ($pick) => $pick->group->title);

        $this->assertCount(6, $titles);

        foreach ($titles as $title) {
            $this->assertStringNotContainsString(
                'Bijzonder apparaat',
                $title,
                "an off-theme find reached a themed edition: {$title}",
            );
        }
    }

    #[Test]
    public function a_cove_shows_one_size_of_a_product_not_each_of_them(): void
    {
        // be-fr, 2 Oct 2026: three sizes of one slipper, one under the other.
        foreach (['37/38', '39/40', '41/42'] as $i => $size) {
            $this->find("Alwero Sloffen Basic Mono Naturel {$size}", 2500, 'Sloffen', 80 - $i);
        }
        foreach (['Sonic the Hedgehog Pantoffels Sloffen', 'Sonic the Hedgehog Pantoffels Sloffen'] as $title) {
            $this->find($title, 1900, 'Sloffen', 70);
        }
        $this->find('Hot Potatoes Harrietta Sloffen Dames', 3200, 'Sloffen', 60);
        $this->find('Clog Sloffen Viv', 2900, 'Sloffen', 50);

        $this->planDay(queries: ['sloffen']);

        $edition = app(EditionBuilder::class)->build(Market::BeNl);
        $this->assertNotNull($edition);

        $titles = $edition->picks()->with('group')->get()->map(fn ($pick) => $pick->group->title)->all();

        $this->assertCount(1, array_filter($titles, fn ($t) => str_starts_with($t, 'Alwero')), implode(' | ', $titles));
        $this->assertCount(1, array_filter($titles, fn ($t) => str_starts_with($t, 'Sonic')), implode(' | ', $titles));
        $this->assertCount(4, $titles);
    }

    #[Test]
    public function a_curated_shortlist_survives_the_variety_trim(): void
    {
        /*
         * Three products from one category, which is what curating a narrow
         * theme looks like. The variety trim used to drop two of them without
         * saying so — nl-nl's home-gym edition published one of the curator's
         * three dumbbell sets and one of their two exercise bikes — and a rule
         * that silently discards a person's decision is not a variety rule, it
         * is a bug with a rationale.
         */
        $this->seedFinds();

        $plan = CovePlan::create([
            'market' => Market::BeNl->value,
            'drop_date' => CarbonImmutable::today()->toDateString(),
            'title' => 'Alles in één hoek',
            'queries' => ['halterset', 'hometrainer'],
            'status' => 'approved',
            'writer' => 'authored',
            'editorial' => 'Alles in één hoek.',
        ]);

        $shortlist = ['Halterset gietijzer', 'Halterset vinyl', 'Halterset verstelbaar'];

        foreach ($shortlist as $i => $title) {
            $plan->items()->create([
                'group_id' => $this->find($title, 4000 + $i * 500, 'Halterset', 20)->id,
                'rank' => $i + 1,
            ]);
        }

        /*
         * Five themed products in five categories, which is more than the page
         * has room for once the shortlist is on it. That surplus is the whole
         * test: the trim's first pass could fill the edition on its own, so a
         * curated product it skipped never came back through the backfill —
         * which is why the production failure needed a rich pool to appear at
         * all, and why a thin fixture would pass either way.
         */
        foreach ([
            'Hometrainer compact' => 'Hometrainers',
            'Hometrainer met display' => 'Cardiotoestellen',
            'Hometrainer opvouwbaar' => 'Fitnessapparatuur',
            'Hometrainer voor thuis' => 'Fitness',
            'Hometrainer met weerstand' => 'Cardio',
        ] as $title => $category) {
            $this->find($title, 9000, $category, 40);
        }

        $edition = app(EditionBuilder::class)->build(Market::BeNl);
        $this->assertNotNull($edition);

        $titles = $edition->picks()->with('group')->get()->map(fn ($pick) => $pick->group->title)->all();

        foreach ($shortlist as $title) {
            $this->assertContains($title, $titles, "the variety trim dropped a curated product: {$title}");
        }
    }

    #[Test]
    public function a_theme_too_thin_to_publish_holds_the_day_rather_than_padding_it(): void
    {
        /*
         * This test used to assert the opposite: below `picks.minimum` the
         * market's highest-scoring products filled the page, because a padded
         * page beat no page. It stopped beating it on 27 Sep 2026, when a day
         * titled after World Tourism Day published strangers under that title
         * with nobody having looked. A themed day that its theme cannot fill
         * now publishes nothing, and says why on the plan.
         */
        $this->seedFinds();
        $this->find('Hometrainer compact', 4000, 'Fitness', 40);

        $plan = $this->planDay(queries: ['hometrainer']);

        $this->assertNull(app(EditionBuilder::class)->build(Market::BeNl));
        $this->assertSame(0, DailyPickSet::query()->count());
        $this->assertNotNull($plan->fresh()->last_build_failed_at);
        $this->assertStringContainsString('1 of the 3', (string) $plan->fresh()->last_build_note);
    }

    // ── An uncurated day reads its theme strictly (27 Sep 2026) ──────────

    #[Test]
    public function an_uncurated_day_takes_only_what_its_theme_names_in_a_title_or_category(): void
    {
        /*
         * be-nl's "Werelddag van het toerisme", reproduced. Every wrong product
         * outscores every right one, which is how it happened: ranked by
         * surprise, the strangest match wins, and the strangest match for
         * "koffer" is never a suitcase.
         */
        $wrong = [
            // A tool case: "koffer" ends the compound, and the first part
            // makes it a tool.
            $this->find('STANLEY gereedschapkoffer voor onderhoud 142-delig', 8900, null, 95),
            // Hole saws and a drill sold in a case: the case is the packaging.
            $this->find('IRWIN gatenzagen set 9-delig in koffer', 3900, 'Gatzagen', 94),
            $this->find('DeWalt DCD796P2 accu schroefboormachine 18V in TSTAK koffer', 29900, null, 93),
            // A toy.
            $this->find('Bumba dokterskoffer', 2500, null, 92),
            // Matched only on its description, which the stored search vector
            // reads and this rule does not.
            $this->find(
                'Sensual Desire Red Lady 3-in-1 Vibrator - Stil & Waterdicht',
                3500,
                'Vibrator',
                91,
                'Discreet en compact: past in elke koffer of handbagage.',
            ),
            // A chest freezer: "koffer" starts the compound rather than ending it.
            $this->find('Diepvries CHAE 2002C', 29900, 'Kofferdiepvriezers', 90),
        ];

        $right = [
            $this->find("Samsonite S'Cure Spinner 55cm Koffer", 12900, null, 68),
            $this->find('Princess Traveller Singapore - Large - 78cm', 8900, 'Reiskoffer', 67),
            $this->find('Samsonite Handbagagekoffer 55x40x20 Zwart', 9900, null, 66),
            $this->find('Universele reisadapter wereldwijd met USB-C', 2400, 'Reisadapter', 65),
            $this->find('Traagschuim nekkussen voor op reis', 1900, null, 64),
        ];

        $this->planDay(queries: ['koffer', 'reisadapter', 'nekkussen'], extra: ['title' => 'Werelddag van het toerisme']);

        $edition = app(EditionBuilder::class)->build(Market::BeNl);
        $this->assertNotNull($edition);

        $ids = $edition->picks()->pluck('group_id')->all();

        foreach ($wrong as $group) {
            $this->assertNotContains($group->id, $ids, "off-theme product published: {$group->title}");
        }

        $this->assertCount(count($right), $ids);

        foreach ($right as $group) {
            $this->assertContains($group->id, $ids, "on-theme product missing: {$group->title}");
        }
    }

    #[Test]
    public function a_curated_product_is_not_held_to_the_theme(): void
    {
        /*
         * Curation overrides the engine, and the relevance rule is the
         * engine's. A person who puts a tool case on a travel day meant it.
         */
        $toolCase = $this->find('STANLEY gereedschapkoffer voor onderhoud 142-delig', 8900, null, 95);
        $this->find("Samsonite S'Cure Spinner 55cm Koffer", 12900, null, 68);
        $this->find('Samsonite Handbagagekoffer 55x40x20 Zwart', 9900, null, 66);

        $plan = $this->planDay(queries: ['koffer']);
        $plan->items()->create(['group_id' => $toolCase->id, 'rank' => 1]);

        $edition = app(EditionBuilder::class)->build(Market::BeNl);
        $this->assertNotNull($edition);

        $this->assertSame($toolCase->id, $edition->picks()->orderBy('rank')->value('group_id'));
        $this->assertSame(3, $edition->picks()->count());
    }

    // ── No prose, no page ────────────────────────────────────────────────

    #[Test]
    public function an_edition_whose_writer_returns_nothing_is_held_not_published(): void
    {
        $this->seedFinds();
        $plan = $this->planDay(extra: ['writer' => 'builder', 'editorial' => null]);

        $this->mock(AiClient::class, function ($mock): void {
            $mock->shouldReceive('isEnabled')->andReturn(true);
            // What be-nl got on 27 Sep 2026: the writer was asked, and what
            // came back had no editorial in it.
            $mock->shouldReceive('json')->andReturn(['editorial' => '']);
        });

        $this->assertNull(app(EditionBuilder::class)->build(Market::BeNl));
        $this->assertSame(0, DailyPickSet::query()->count());

        $plan->refresh();
        $this->assertNotNull($plan->last_build_failed_at);
        $this->assertStringContainsString('Held', (string) $plan->last_build_note);

        // Nothing was published, so the column has nothing to show.
        $this->get('/be-nl/tips')->assertNotFound();
    }

    #[Test]
    public function a_failed_rewrite_leaves_a_published_edition_as_it_was(): void
    {
        $this->seedFinds();
        $this->planDay(extra: ['writer' => 'builder', 'editorial' => null]);

        // The first build is written; every call after the first two comes
        // back empty. (The first build may make two calls: prose that names
        // none of its products is retried once.)
        $answers = [['editorial' => 'Zes vreemde apparaten, en waarom.'], ['editorial' => '']];

        $this->mock(AiClient::class, function ($mock) use (&$answers): void {
            $mock->shouldReceive('isEnabled')->andReturn(true);
            $mock->shouldReceive('json')->andReturnUsing(function () use (&$answers): array {
                return count($answers) > 1 ? array_shift($answers) : $answers[0];
            });
        });

        $builder = app(EditionBuilder::class);
        $first = $builder->build(Market::BeNl);
        $this->assertNotNull($first);
        $this->assertSame('Zes vreemde apparaten, en waarom.', $first->editorial);

        // A rebuild whose writer comes back empty is held, and the page that
        // is live keeps its prose rather than being rebuilt bare.
        $this->assertNull($builder->build(Market::BeNl));
        $this->assertSame('Zes vreemde apparaten, en waarom.', $first->fresh()->editorial);
        $this->assertTrue($first->fresh()->isPublished());
    }

    #[Test]
    public function with_the_model_off_an_unwritten_day_is_held_and_an_authored_one_publishes(): void
    {
        $this->seedFinds(16);
        $this->assertFalse(app(AiClient::class)->isEnabled(), 'the suite runs with AI off');

        // Yesterday was written by a person: it publishes without a model.
        $yesterday = $this->planDay(CarbonImmutable::yesterday());
        $published = app(EditionBuilder::class)->build(Market::BeNl, CarbonImmutable::yesterday());
        $this->assertNotNull($published);
        $this->assertSame('planned', $published->editorial_source);
        $this->assertSame('Een dag vol vreemde apparaten.', $published->editorial);
        $this->assertNull($yesterday->fresh()->last_build_failed_at);

        // Today nobody wrote anything and the model is off: held.
        $today = $this->planDay(extra: ['writer' => 'builder', 'editorial' => null]);
        $this->assertNull(app(EditionBuilder::class)->build(Market::BeNl));
        $this->assertStringContainsString('AI is switched off', (string) $today->fresh()->last_build_note);

        // The column and the front page carry on with yesterday's edition.
        $this->get('/be-nl/tips')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('edition.id', $published->id)->etc());
        $this->get('/be-nl')->assertOk();
    }

    #[Test]
    public function reacting_twice_moves_the_count_rather_than_doubling_it(): void
    {
        $edition = $this->buildEdition();
        $pick = $edition->picks()->firstOrFail();
        $user = User::create(['email' => 'reactor@example.test']);

        $this->actingAs($user)->postJson("/be-nl/picks/{$pick->id}/react", ['reaction' => 'mindblown']);
        $this->actingAs($user)->postJson("/be-nl/picks/{$pick->id}/react", ['reaction' => 'meh'])
            ->assertOk()
            ->assertJsonPath('mindblown', 0)
            ->assertJsonPath('meh', 1);
    }
}

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
            'surprise_breakdown' => ['lexical' => 30, 'exclusivity' => 15],
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

    private function seedFinds(int $count = 8): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->find("Bijzonder apparaat {$i}", 3000 + $i * 1500, "Categorie{$i}", 90 - $i);
        }
    }

    private function buildEdition(): DailyPickSet
    {
        $this->seedFinds();

        $edition = app(EditionBuilder::class)->build(Market::BeNl);
        $this->assertNotNull($edition);

        return $edition;
    }

    // ── The edition ───────────────────────────────────────────────────────

    #[Test]
    public function building_the_same_day_twice_produces_one_edition(): void
    {
        $this->seedFinds();
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
    public function a_theme_too_thin_to_publish_is_filled_rather_than_dropped(): void
    {
        /*
         * The floor under the rule above, and it is load-bearing rather than
         * theoretical: the observance calendar's queries are Dutch, so on an
         * unplanned day in `en` or `es` the themed lane matches nothing at all.
         * Below `picks.minimum` the builder refuses to publish, so an off-theme
         * find there is the difference between a padded page and no page —
         * which is the one trade where padding wins.
         */
        $this->seedFinds();
        $this->find('Hometrainer compact', 4000, 'Fitness', 40);

        CovePlan::create([
            'market' => Market::BeNl->value,
            'drop_date' => CarbonImmutable::today()->toDateString(),
            'title' => 'Eén product diep',
            'queries' => ['hometrainer'],
            'status' => 'approved',
        ]);

        $edition = app(EditionBuilder::class)->build(Market::BeNl);
        $this->assertNotNull($edition, 'a thin theme dropped the edition instead of filling it');
        $this->assertSame(6, $edition->picks()->count());
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

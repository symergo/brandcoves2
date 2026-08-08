<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\PublishStatus;
use App\Enums\Source;
use App\Models\ChallengeAttempt;
use App\Models\DailyPickSet;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\User;
use App\Services\Cove\EditionBuilder;
use App\Services\Cove\GuessBand;
use App\Services\Cove\PriceHunt;
use App\Support\Owner;
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

    // ── The game ──────────────────────────────────────────────────────────

    #[Test]
    public function bands_are_proportional_not_absolute(): void
    {
        // Being €20 out on a €40 kettle is a wild miss; €20 out on a €900
        // machine is a bullseye. A fixed threshold gets one of them wrong.
        // €20 out on a €920 machine: within 5%, a bullseye.
        $this->assertSame(GuessBand::Exact, GuessBand::classify(90000, 92000));
        // The same €20 out on a €40 kettle: 50% wrong, and it reads as wrong.
        $this->assertSame(GuessBand::Cold, GuessBand::classify(2000, 4000));
        // A third out is off but not hopeless — the bands have to distinguish
        // "nearly" from "nowhere near" or the feedback carries no information.
        $this->assertSame(GuessBand::Cool, GuessBand::classify(4000, 6000));
    }

    #[Test]
    public function the_answer_is_absent_from_the_payload_until_the_round_is_over(): void
    {
        $edition = $this->buildEdition();
        $this->assertNotNull($edition->challenge_price);

        $response = $this->get('/be-nl/daily')->assertOk();
        $challenge = $response->viewData('page')['props']['challenge'];

        /*
         * Absent, not merely hidden in the UI. A price sent "for later" is a
         * price anyone can read in DevTools, and one person doing that ruins
         * the shared-puzzle premise for everyone they post their grid to.
         */
        $this->assertNull($challenge['answer']);
    }

    #[Test]
    public function the_answer_is_revealed_once_the_tries_run_out(): void
    {
        $edition = $this->buildEdition();
        $user = User::create(['email' => 'player@example.test']);

        $last = null;
        for ($i = 0; $i < PriceHunt::MAX_ATTEMPTS; $i++) {
            // Deliberately nowhere near, so the round ends by exhaustion rather
            // than by being solved.
            $last = $this->actingAs($user)
                ->postJson("/be-nl/daily/{$edition->drop_date->toDateString()}/guess", ['guess' => 1])
                ->assertOk();
        }

        $last->assertJsonPath('finished', true)
            ->assertJsonPath('solved', false)
            ->assertJsonPath('answer', $edition->challenge_price);
    }

    #[Test]
    public function a_close_guess_solves_it_and_stops_the_round(): void
    {
        $edition = $this->buildEdition();
        $user = User::create(['email' => 'player@example.test']);
        $answer = (int) $edition->challenge_price;

        $this->actingAs($user)
            ->postJson("/be-nl/daily/{$edition->drop_date->toDateString()}/guess", [
                'guess' => ($answer / 100),
            ])
            ->assertOk()
            ->assertJsonPath('solved', true)
            ->assertJsonPath('finished', true);
    }

    #[Test]
    public function a_fifth_guess_changes_nothing(): void
    {
        $edition = $this->buildEdition();
        $user = User::create(['email' => 'player@example.test']);
        $date = $edition->drop_date->toDateString();

        for ($i = 0; $i < PriceHunt::MAX_ATTEMPTS + 3; $i++) {
            $this->actingAs($user)->postJson("/be-nl/daily/{$date}/guess", ['guess' => 1]);
        }

        // Four tries is the whole round. Anything beyond it must be a no-op,
        // not a fifth attempt recorded — otherwise the share grid lies.
        $attempt = ChallengeAttempt::query()->firstOrFail();
        $this->assertSame(PriceHunt::MAX_ATTEMPTS, (int) $attempt->attempts);
    }

    #[Test]
    public function one_player_gets_one_round_per_edition(): void
    {
        $edition = $this->buildEdition();
        $user = User::create(['email' => 'player@example.test']);
        $date = $edition->drop_date->toDateString();

        $this->actingAs($user)->postJson("/be-nl/daily/{$date}/guess", ['guess' => 10]);
        $this->actingAs($user)->postJson("/be-nl/daily/{$date}/guess", ['guess' => 20]);

        // Two attempt rows would mean two sets of tries at the same puzzle,
        // which is the one thing a daily game cannot allow.
        $this->assertSame(1, ChallengeAttempt::query()->count());
    }

    #[Test]
    public function the_streak_is_derived_from_the_days_actually_played(): void
    {
        $user = User::create(['email' => 'player@example.test']);
        $owner = new Owner(user: $user, anonymous: null);

        foreach ([0, 1, 2, 5] as $daysAgo) {
            $date = CarbonImmutable::today()->subDays($daysAgo);

            $set = DailyPickSet::create([
                'market' => Market::BeNl,
                'drop_date' => $date->toDateString(),
                'theme_title' => 'T',
                'theme_slug' => 't-'.$daysAgo,
                'status' => PublishStatus::Published->value,
                'published_at' => $date,
            ]);

            ChallengeAttempt::create([
                'set_id' => $set->id,
                'user_id' => $user->id,
                'market' => Market::BeNl->value,
                'played_on' => $date->toDateString(),
                'attempts' => 1,
            ]);
        }

        // Never stored as a counter: a stored streak drifts and then has to be
        // repaired by hand. Recomputed from the only facts that exist, it
        // cannot be wrong.
        $this->assertSame(3, app(PriceHunt::class)->streak($owner)['current']);
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
            (int) config('brandcoves.picks.per_day'),
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

        // Guessing tomorrow's puzzle by editing the URL would be an obvious
        // hole in a daily game.
        $this->get('/be-nl/daily/'.CarbonImmutable::tomorrow()->toDateString())
            ->assertNotFound();
    }

    #[Test]
    public function an_archived_edition_keeps_its_own_url(): void
    {
        $edition = $this->buildEdition();

        // The archive is the SEO asset. A daily game whose past rounds 404 has
        // nothing to link to and nothing indexed.
        $this->get('/be-nl/daily/'.$edition->drop_date->toDateString())
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('finds'));
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

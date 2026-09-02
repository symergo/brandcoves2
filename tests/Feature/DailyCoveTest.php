<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
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
         * /be-nl/cadeautips/vondsten-voor-thuiswerkers.
         */
        $this->get('/be-nl/cadeautips/'.$edition->slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('finds'));

        /*
         * Every address this page has ever had still resolves, permanently and
         * in one hop. Both are indexed and the dated one is in three months of
         * digest emails; a chain through the old dated form would cost link
         * equity on the way.
         */
        $this->get('/be-nl/daily/'.$edition->slug)
            ->assertRedirect('/be-nl/cadeautips/'.$edition->slug)
            ->assertStatus(301);

        $this->get('/be-nl/daily/'.$edition->drop_date->toDateString())
            ->assertRedirect('/be-nl/cadeautips/'.$edition->slug)
            ->assertStatus(301);

        $this->get('/be-nl/cadeautips/'.$edition->drop_date->toDateString())
            ->assertRedirect('/be-nl/cadeautips/'.$edition->slug)
            ->assertStatus(301);

        // /daily with no address is today's edition, wherever it now lives.
        $this->get('/be-nl/daily')
            ->assertRedirect('/be-nl/cadeautips')
            ->assertStatus(301);
    }

    #[Test]
    public function another_markets_word_for_the_section_is_not_an_address_here(): void
    {
        /*
         * The routes are declared once under a {market} prefix, so the pattern
         * admits every market's segment and /es/cadeau-van-de-dag/... matches
         * it. Serving that would put one market's page on another's address:
         * duplicate content, carrying hreflang that contradicts it.
         */
        $edition = $this->buildEdition();

        $this->get('/be-nl/gift-of-the-day/'.$edition->slug)->assertNotFound();
        $this->get('/be-nl/regalo-del-dia')->assertNotFound();
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

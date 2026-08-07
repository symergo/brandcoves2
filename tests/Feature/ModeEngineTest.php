<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\Merchant;
use App\Models\ModeProfileRecord;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\User;
use App\Services\Discover\DiscoveryRequest;
use App\Services\Discover\ModeEngine;
use App\Services\Discover\ModeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * One pipeline, reconfigured by a Mode Profile.
 *
 * The claim under test is architectural, not cosmetic: Search and Serendipity
 * are the two endpoints of one axis running through the same four stages, and
 * the dial between them interpolates rather than swaps. If these pass, adding
 * the other seven modes really is config.
 */
class ModeEngineTest extends TestCase
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

    private function group(
        string $title,
        int $price = 4999,
        ?string $category = null,
        ?float $surprise = null,
        int $merchants = 1,
    ): ProductGroup {
        $group = ProductGroup::create([
            'market' => Market::BeNl,
            'identity_key' => 'k'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => $title,
            'slug' => 'p-'.bin2hex(random_bytes(3)),
            'category' => $category,
            'image_url' => 'https://img.test/x.jpg',
            'min_price' => $price,
            'merchant_count' => $merchants,
            'in_stock' => true,
            'giftable' => true,
            'surprise_score' => $surprise,
        ]);

        Product::create([
            'source' => Source::Awin,
            'market' => Market::BeNl,
            'merchant_id' => $this->merchant->id,
            'group_id' => $group->id,
            'external_id' => 'e'.bin2hex(random_bytes(5)),
            'title' => $title,
            'merchant_category' => $category,
            'price' => $price,
            'currency' => 'EUR',
            'affiliate_url' => 'https://example.test/buy',
            'availability' => Availability::InStock,
            'status' => ProductStatus::Active,
            'identity_key' => $group->identity_key,
        ]);

        return $group;
    }

    private function engine(): ModeEngine
    {
        return app(ModeEngine::class);
    }

    private function request(?string $query = null, int $limit = 8): DiscoveryRequest
    {
        return new DiscoveryRequest(market: Market::BeNl, query: $query, limit: $limit);
    }

    // ── The pipeline ──────────────────────────────────────────────────────

    #[Test]
    public function search_mode_ranks_on_relevance(): void
    {
        $this->group('Sony WH-1000XM5 koptelefoon', 32999, 'Audio', surprise: 5);
        $this->group('Sous-vide circulator', 12999, 'Keuken', surprise: 95);

        $result = $this->engine()->discover('search', $this->request('koptelefoon'));

        // beta is zero in Search, so the far-more-surprising product must not
        // win. If it does, the exponents are not being applied.
        $this->assertNotEmpty($result->items);
        $this->assertStringContainsString('koptelefoon', $result->items[0]->group->title);
        $this->assertSame('relevance', $result->items[0]->reason);
    }

    #[Test]
    public function serendipity_mode_ranks_on_unexpectedness(): void
    {
        $this->group('Gewone koptelefoon', 4999, 'Audio', surprise: 2);
        $this->group('Sous-vide circulator met vacuumsealer', 12999, 'Keuken', surprise: 95);

        $result = $this->engine()->discover('serendipity', $this->request(), seed: 1);

        $this->assertNotEmpty($result->items);
        $this->assertSame('Sous-vide circulator met vacuumsealer', $result->items[0]->group->title);
    }

    #[Test]
    public function a_zero_exponent_neutralises_its_term(): void
    {
        $this->group('Koptelefoon A', 4999, 'Audio', surprise: 90);
        $this->group('Koptelefoon B', 4999, 'Audio', surprise: 1);

        $result = $this->engine()->discover('search', $this->request('koptelefoon'));

        /*
         * Both titles match equally, and their surprise scores differ by 89
         * points. With beta = 0 that difference must be worth exactly nothing —
         * x^0 = 1 — which is what lets one objective serve every mode with no
         * special-casing anywhere.
         */
        $scores = array_map(fn ($i) => round($i->score, 6), $result->items);
        $this->assertCount(2, array_unique($scores) === [] ? [] : $scores);
        $this->assertEqualsWithDelta($scores[0], $scores[1], 0.0001);
    }

    #[Test]
    public function every_result_says_why_it_is_there(): void
    {
        $this->group('Sous-vide circulator', 12999, 'Keuken', surprise: 95);

        foreach (['search', 'serendipity'] as $mode) {
            $result = $this->engine()->discover($mode, $this->request('circulator'));

            foreach ($result->items as $item) {
                // Not optional per mode. A surface that reorganises as a dial
                // moves is incomprehensible without it.
                $this->assertNotNull($item->reason, "[{$mode}] result had no reason");
            }
        }
    }

    #[Test]
    public function high_lambda_breaks_up_a_cluster(): void
    {
        foreach (['JBL', 'Sony', 'Bose', 'Marshall', 'Denon', 'Sonos'] as $i => $brand) {
            $this->group("{$brand} bluetooth speaker", 8000, 'Audio', surprise: 90 - $i);
        }

        $this->group('Sous-vide circulator', 9000, 'Keuken', surprise: 80);
        $this->group('Ukelele sopraan', 6500, 'Instrumenten', surprise: 78);

        $result = $this->engine()->discover('serendipity', $this->request(limit: 4), seed: 7);

        $categories = array_unique(array_map(fn ($i) => $i->group->category, $result->items));

        // lambda is 0.8 in Serendipity. Four speakers would be a failed
        // surprise however well each one scored on its own.
        $this->assertGreaterThan(1, count($categories));
    }

    // ── The dial ──────────────────────────────────────────────────────────

    #[Test]
    public function the_dial_interpolates_rather_than_snapping(): void
    {
        $this->group('Koptelefoon', 4999, 'Audio', surprise: 50);

        $engine = $this->engine();

        $low = $engine->discover('search', $this->request('koptelefoon'), dial: 0.0)->profile;
        $mid = $engine->discover('search', $this->request('koptelefoon'), dial: 0.5)->profile;
        $high = $engine->discover('search', $this->request('koptelefoon'), dial: 1.0)->profile;

        /*
         * This is the mode switcher's whole premise. Moving the dial from
         * Search toward Serendipity has to move alpha down and beta up
         * continuously — a threshold swap would make one control feel like nine
         * screens, which is exactly what it exists to avoid.
         */
        $this->assertGreaterThan($mid->alpha, $low->alpha);
        $this->assertGreaterThan($high->alpha, $mid->alpha);

        $this->assertLessThan($mid->beta, $low->beta);
        $this->assertLessThan($high->beta, $mid->beta);
    }

    #[Test]
    public function the_dial_cross_fades_the_retriever_mix(): void
    {
        $mid = app(ModeRegistry::class)->atPosition(0.5);

        // Halfway between Search (keyword-led) and the editorial stop, both
        // retrievers should be contributing — the mix fades rather than flips.
        $this->assertNotSame([], $mid->retrievers);
        $this->assertGreaterThan(1, count($mid->retrievers));
    }

    #[Test]
    public function the_surprise_dial_moves_beta_without_changing_mode(): void
    {
        $registry = app(ModeRegistry::class);
        $base = $registry->get('search');

        $damped = $base->withSurprise(0.0);
        $neutral = $base->withSurprise(0.5);
        $amplified = $base->withSurprise(1.0);

        $this->assertSame($base->key, $amplified->key);
        // 0.5 means "leave the profile alone", which is what a slider sitting
        // in the middle should mean.
        $this->assertEqualsWithDelta($base->beta, $neutral->beta, 0.0001);
        $this->assertLessThanOrEqual($base->beta, $damped->beta);
        $this->assertGreaterThanOrEqual($base->beta, $amplified->beta);
    }

    // ── Modes are config ──────────────────────────────────────────────────

    #[Test]
    public function a_database_row_overrides_one_field_and_leaves_the_rest(): void
    {
        $registry = app(ModeRegistry::class);
        $declared = $registry->get('search');

        ModeProfileRecord::create(['key' => 'search', 'scoring' => ['lambda' => 0.75]]);
        $registry->forget();

        $overridden = app(ModeRegistry::class)->get('search');

        // Tuning one weight after a week of reaction data must not require a
        // deployment, and must not silently freeze every other field at
        // whatever the config said the day the row was written.
        $this->assertSame(0.75, $overridden->lambda);
        $this->assertSame($declared->alpha, $overridden->alpha);
        $this->assertSame($declared->layout, $overridden->layout);
    }

    #[Test]
    public function a_profile_naming_an_unbuilt_retriever_degrades_onto_its_others(): void
    {
        $this->group('Sony koptelefoon', 4999, 'Audio', surprise: 50);

        /*
         * Search declares `semantic: 0.2`, and there is no embedding index yet.
         * The engine renormalises over what is available, so the mode returns
         * keyword results rather than four-fifths of a page — the property that
         * lets the axis be declared in full before every retriever exists.
         */
        $result = $this->engine()->discover('search', $this->request('koptelefoon'));

        $this->assertNotEmpty($result->items);
        $this->assertContains('keyword', $result->items[0]->sources);
    }

    #[Test]
    public function an_unknown_mode_is_not_found(): void
    {
        $this->get('/be-nl/discover/telepathy')->assertNotFound();
    }

    // ── The surfaces ──────────────────────────────────────────────────────

    #[Test]
    public function each_mode_has_its_own_deep_link(): void
    {
        $this->group('Sous-vide circulator', 12999, 'Keuken', surprise: 95);

        foreach (['search', 'guides', 'serendipity'] as $mode) {
            $this->get("/be-nl/discover/{$mode}")
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('mode', $mode)
                    ->has('items')
                    ->has('modeMeta.scoring'));
        }
    }

    #[Test]
    public function the_dial_endpoint_returns_a_reorganised_surface(): void
    {
        $this->group('Sony koptelefoon', 4999, 'Audio', surprise: 10);
        $this->group('Sous-vide circulator', 12999, 'Keuken', surprise: 95);

        $this->postJson('/be-nl/discover', [
            'mode' => 'search',
            'dial' => 1.0,
            'input' => ['query' => 'koptelefoon'],
        ])
            ->assertOk()
            ->assertJsonStructure(['items', 'layout', 'modeMeta' => ['scoring', 'retrievers']]);
    }

    #[Test]
    public function a_reaction_records_the_factor_that_put_the_item_on_the_page(): void
    {
        $group = $this->group('Sous-vide circulator', 12999, 'Keuken', surprise: 95);
        $user = User::create(['email' => 'reactor@example.test']);

        $this->actingAs($user)->postJson('/be-nl/discover/react', [
            'mode' => 'serendipity',
            'group_id' => $group->id,
            'reaction' => 'meh',
            'factor' => 'unexpectedness',
        ])->assertOk();

        // Without the factor a row says "they disliked it" but not "they
        // disliked it for the reason we showed it" — and it is the second half
        // that tunes a weight.
        $this->assertDatabaseHas('discovery_reactions', [
            'group_id' => $group->id,
            'reaction' => 'meh',
            'dominant_factor' => 'unexpectedness',
        ]);
    }
}

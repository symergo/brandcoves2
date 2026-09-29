<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Interest;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\RecipientType;
use App\Enums\Source;
use App\Jobs\PlanGiftLandingPages;
use App\Models\GiftLanding;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Gift\GiftLandingPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Gift landing pages (roadmap step 4, part 2): "gift ideas for dad who loves
 * cooking" exists when the catalogue fills it with eight products, and not
 * otherwise. See docs/features/gift-landing-pages.md.
 */
class GiftLandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Two interests walked instead of thirty-nine, so the planner runs
        // twenty engine passes per market rather than four hundred.
        config(['giftcoves.gift_landings.excluded_interests' => array_values(array_diff(
            Interest::values(),
            [Interest::Cooking->value, Interest::Gardening->value],
        ))]);
    }

    #[Test]
    public function the_planner_records_a_pair_the_catalogue_fills_and_nothing_thinner(): void
    {
        $this->products(Market::BeNl, 'Koksmes', 8, ['interest:cooking']);
        $this->products(Market::BeNl, 'Snoeischaar', 7, ['interest:gardening']);

        PlanGiftLandingPages::dispatchSync(Market::BeNl);

        $cooking = GiftLanding::lookup(Market::BeNl, RecipientType::Father, Interest::Cooking);

        $this->assertNotNull($cooking);
        $this->assertSame(8, $cooking->product_count);
        $this->assertSame('/be-nl/gift-ideas/for/papa/koken', $cooking->path);
        $this->assertEquals(['relationship' => 'father', 'interests' => ['cooking']], $cooking->brief);

        // Seven is a thin page.
        $this->assertNull(GiftLanding::lookup(Market::BeNl, RecipientType::Father, Interest::Gardening));

        // Dad's own page exists because one of his pairs does.
        $this->assertNotNull(GiftLanding::lookup(Market::BeNl, RecipientType::Father, null));
    }

    #[Test]
    public function a_page_that_falls_under_the_minimum_is_removed_the_next_night(): void
    {
        $knives = $this->products(Market::BeNl, 'Koksmes', 8, ['interest:cooking']);

        PlanGiftLandingPages::dispatchSync(Market::BeNl);
        $this->assertNotNull(GiftLanding::lookup(Market::BeNl, RecipientType::Father, Interest::Cooking));

        $this->travel(1)->days();
        $knives[0]->update(['in_stock' => false]);

        PlanGiftLandingPages::dispatchSync(Market::BeNl);

        $this->assertNull(GiftLanding::lookup(Market::BeNl, RecipientType::Father, Interest::Cooking));
        $this->get('/be-nl/gift-ideas/for/papa/koken')->assertNotFound();
    }

    #[Test]
    public function a_recorded_page_renders_its_products_under_the_searched_phrase(): void
    {
        $this->products(Market::BeNl, 'Koksmes', 9, ['interest:cooking']);
        PlanGiftLandingPages::dispatchSync(Market::BeNl);

        $response = $this->get('/be-nl/gift-ideas/for/papa/koken')->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('GiftIdeas/Landing')
            ->where('heading', 'Cadeaus voor papa die van koken houdt')
            ->where('seoTitle', fn (string $title) => $title === 'Cadeaus voor papa die van koken houdt' && mb_strlen($title) <= 48)
            // `picks` since the page draws the Find-a-gift results' cards (2026-09-26).
            ->where('picks', fn ($picks) => count($picks) >= 8)
            ->where('askUrl', '/be-nl/ask')
            ->where('pageUrl', null)
            ->where('isRecipientPage', false));

        // The recipient's own page links on to this one.
        $this->get('/be-nl/gift-ideas/for/papa')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('heading', 'Cadeaus voor papa')
                ->where('moreFor.links.0.url', '/be-nl/gift-ideas/for/papa/koken'));
    }

    #[Test]
    public function anything_unrecorded_is_a_404(): void
    {
        $this->products(Market::BeNl, 'Koksmes', 8, ['interest:cooking']);
        PlanGiftLandingPages::dispatchSync(Market::BeNl);

        $this->get('/be-nl/gift-ideas/for/papa/tuinieren')->assertNotFound();
        $this->get('/be-nl/gift-ideas/for/papa/onderwatermandvlechten')->assertNotFound();
        $this->get('/be-nl/gift-ideas/for/nonkel/koken')->assertNotFound();
        // Recorded in be-nl only.
        $this->get('/nl-nl/gift-ideas/for/papa/koken')->assertNotFound();
    }

    #[Test]
    public function another_languages_words_redirect_to_this_markets(): void
    {
        $this->products(Market::BeNl, 'Koksmes', 8, ['interest:cooking']);
        PlanGiftLandingPages::dispatchSync(Market::BeNl);

        $this->get('/be-nl/gift-ideas/for/dad/cooking?budget=20-50')
            ->assertStatus(301)
            ->assertRedirect('/be-nl/gift-ideas/for/papa/koken?budget=20-50');
    }

    #[Test]
    public function a_budget_narrows_the_page_and_the_canonical_stays_bare(): void
    {
        $this->products(Market::BeNl, 'Koksmes', 8, ['interest:cooking'], 2500);
        $this->products(Market::BeNl, 'Gietijzeren pan', 2, ['interest:cooking'], 7500);
        PlanGiftLandingPages::dispatchSync(Market::BeNl);

        $response = $this->get('/be-nl/gift-ideas/for/papa/koken?budget=50-100')->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->where('budget.current', '50-100')
            ->where('picks', fn ($picks) => count($picks) === 2
                && collect($picks)->every(fn ($p) => $p['price'] >= 5000 && $p['price'] <= 10000)));

        $response->assertSee('<link rel="canonical" href="'.url('/be-nl/gift-ideas/for/papa/koken').'"', escape: false);
    }

    #[Test]
    public function the_sitemap_lists_exactly_the_recorded_pages_with_hreflang_where_both_markets_have_one(): void
    {
        $this->products(Market::BeNl, 'Koksmes', 8, ['interest:cooking']);
        $this->products(Market::NlNl, 'Koksmes', 8, ['interest:cooking']);
        PlanGiftLandingPages::dispatchSync(Market::BeNl);
        PlanGiftLandingPages::dispatchSync(Market::NlNl);

        $xml = $this->get('/sitemap/be-nl/1.xml')->assertOk()->getContent();

        $recorded = GiftLanding::query()->forMarket(Market::BeNl)->pluck('path');
        preg_match_all('#<loc>[^<]*(/be-nl/gift-ideas/for/[^<]*)</loc>#', $xml, $listed);

        $this->assertEqualsCanonicalizing($recorded->all(), $listed[1]);
        $this->assertStringNotContainsString('/be-nl/gift-ideas/for/papa/tuinieren', $xml);

        // be-nl and nl-nl both have dad and cooking; be-fr does not.
        $this->assertStringContainsString('hreflang="nl-NL" href="'.url('/nl-nl/gift-ideas/for/papa/koken').'"', $xml);
        $this->assertStringNotContainsString('/be-fr/gift-ideas/for/', $xml);

        $this->get('/be-nl/gift-ideas/for/papa/koken')
            ->assertSee('hreflang="nl-NL"', escape: false)
            ->assertDontSee('hreflang="fr-BE"', escape: false);
    }

    #[Test]
    public function persona_addresses_are_untouched(): void
    {
        $route = fn (string $path) => Route::getRoutes()->match(Request::create($path))->getName();

        $this->assertSame('gift-ideas.persona', $route('/be-nl/gift-ideas/de-thuiskok'));
        $this->assertSame('gift-ideas.landing', $route('/be-nl/gift-ideas/for/papa'));
        $this->assertSame('gift-ideas.landing', $route('/be-nl/gift-ideas/for/papa/koken'));
    }

    #[Test]
    public function the_gift_finder_offers_the_nearest_page(): void
    {
        $this->products(Market::BeNl, 'Koksmes', 8, ['interest:cooking']);
        PlanGiftLandingPages::dispatchSync(Market::BeNl);

        // "papa" is free text on a saved person; it is read as a father.
        $this->post('/be-nl/gift', ['relationship' => 'papa', 'interests' => ['gardening', 'cooking']])
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('pageUrl', '/be-nl/gift-ideas/for/papa/koken'));

        // No recipient: no page to offer.
        $this->post('/be-nl/gift', ['interests' => ['cooking']])
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('pageUrl', null));
    }

    #[Test]
    public function recipients_with_the_same_counts_get_different_interests_on_their_own_page(): void
    {
        /*
         * Every interest tied at the page size, as on production on 2026-09-27:
         * by count alone every recipient got the first three interests of the
         * enum, and all ten recipient pages showed the same 24 products.
         */
        $counts = array_fill_keys(['cooking', 'coffee', 'photography', 'gardening', 'diy', 'drinks', 'science', 'boardgames', 'craft'], 24);
        $planner = app(GiftLandingPlanner::class);

        $mother = $planner->hubInterests(RecipientType::Mother, $counts);
        $father = $planner->hubInterests(RecipientType::Father, $counts);
        $child = $planner->hubInterests(RecipientType::Son, $counts);

        $this->assertSame('gardening', $mother[0]);
        $this->assertSame(['diy', 'drinks'], array_slice($father, 0, 2));
        $this->assertSame(['science', 'boardgames', 'craft'], $child);
        $this->assertNotSame($mother, $father);
    }

    #[Test]
    public function a_recipients_page_falls_back_to_the_count_where_their_interests_have_no_page(): void
    {
        // Only cooking has a page for dad, and cooking is not on his list: the
        // page still exists, built from what does.
        $this->assertSame(
            ['cooking'],
            app(GiftLandingPlanner::class)->hubInterests(RecipientType::Father, ['cooking' => 12]),
        );
    }

    #[Test]
    public function a_child_gets_no_page_for_drinks(): void
    {
        config(['giftcoves.gift_landings.excluded_interests' => array_values(array_diff(
            Interest::values(),
            [Interest::Cooking->value, Interest::Drinks->value],
        ))]);

        $this->products(Market::BeNl, 'Wijnglas', 8, ['interest:drinks']);

        PlanGiftLandingPages::dispatchSync(Market::BeNl);

        $this->assertNotNull(GiftLanding::lookup(Market::BeNl, RecipientType::Father, Interest::Drinks));
        $this->assertNull(GiftLanding::lookup(Market::BeNl, RecipientType::Son, Interest::Drinks));
        $this->assertNull(GiftLanding::lookup(Market::BeNl, RecipientType::Daughter, Interest::Drinks));
    }

    #[Test]
    public function recipient_pages_are_linked_from_discover_and_not_from_the_persona_shelf(): void
    {
        $this->products(Market::BeNl, 'Koksmes', 8, ['interest:cooking']);
        PlanGiftLandingPages::dispatchSync(Market::BeNl);

        // Owner, 2026-09-28: the shelf is for personas only.
        $this->get('/be-nl/gift-ideas')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->missing('forWhom'));

        // Still reachable: Discover's "Of per persoon" row.
        $this->get('/be-nl/discover-cove')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where(
                'forWhom',
                fn ($links) => collect($links)->contains(fn ($l) => $l['url'] === '/be-nl/gift-ideas/for/papa' && $l['label'] === 'Cadeaus voor papa'),
            ));
    }

    /**
     * @param  list<string>  $tags
     * @return list<ProductGroup>
     */
    private function products(Market $market, string $title, int $count, array $tags, int $price = 2500): array
    {
        $merchant = Merchant::query()->firstOrCreate(
            ['source' => Source::Awin->value, 'external_id' => 'shop'],
            ['name' => 'Shop'],
        );

        $groups = [];

        for ($i = 1; $i <= $count; $i++) {
            $name = "{$title} {$i} ".bin2hex(random_bytes(2));

            $group = ProductGroup::create([
                'market' => $market,
                'identity_key' => 'k'.bin2hex(random_bytes(6)),
                'identity_kind' => 'ean',
                'title' => $name,
                'slug' => 'p-'.bin2hex(random_bytes(4)),
                'category' => 'Keuken',
                'image_url' => 'https://img.test/x.jpg',
                'min_price' => $price,
                'merchant_count' => 1,
                'in_stock' => true,
                'giftable' => true,
                'gift_tags' => $tags,
            ]);

            Product::create([
                'source' => Source::Awin,
                'market' => $market,
                'merchant_id' => $merchant->id,
                'group_id' => $group->id,
                'external_id' => 'e'.bin2hex(random_bytes(6)),
                'identity_kind' => 'ean',
                'title' => $name,
                'merchant_category' => 'Keuken',
                'price' => $price,
                'currency' => 'EUR',
                'affiliate_url' => 'https://example.test/buy',
                'availability' => Availability::InStock,
                'status' => ProductStatus::Active,
                'identity_key' => $group->identity_key,
            ]);

            $groups[] = $group;
        }

        return $groups;
    }
}

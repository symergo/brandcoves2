<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\ListKind;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The search landing, seeded from what the visitor saved (2026-09-13).
 *
 * `/search` with no term is a catalogue grid, the same for everybody. For a
 * signed-in visitor with things on a list it is led by products whose offers
 * share words with the saved titles (or share a brand), ranked by how much
 * they echo those titles, and what is already on a list is left out. A term,
 * a filter, or no account gives the ordinary grid.
 *
 * Words, not categories: the owner's list held a kids' backpack filed under
 * "Kids" and the landing filled with children's books, while the book on
 * subway art they had saved from Amazon counted for nothing.
 */
class SearchLikeYourListsTest extends TestCase
{
    use RefreshDatabase;

    /** A product with one offer behind it, since the text index lives on offers. */
    private function product(string $title, string $brand, string $category = 'Diversen', Market $market = Market::BeNl, ?string $identity = null, ?string $description = null): ProductGroup
    {
        $group = ProductGroup::factory()->create(array_filter([
            'market' => $market,
            'title' => $title,
            'brand' => $brand,
            'category' => $category,
            'identity_key' => $identity,
        ], fn ($v) => $v !== null));

        $merchant = Merchant::firstOrCreate(
            ['source' => Source::Awin->value, 'external_id' => 'awin-shop'],
            ['name' => 'Shop'],
        );

        Product::create([
            'source' => Source::Awin,
            'market' => $group->market,
            'merchant_id' => $merchant->id,
            'group_id' => $group->id,
            'external_id' => 'x'.bin2hex(random_bytes(4)),
            'title' => $title,
            'description' => $description,
            'price' => 2500,
            'currency' => 'EUR',
            'affiliate_url' => 'https://example.test/buy',
            'availability' => Availability::InStock,
            'status' => ProductStatus::Active,
            'identity_key' => $group->identity_key,
        ]);

        return $group;
    }

    private function list(User $user): Wishlist
    {
        return Wishlist::query()->firstOrCreate(
            ['owner_user_id' => $user->id, 'kind' => ListKind::Mine->value, 'market' => Market::BeNl->value],
            ['title' => 'Mine'],
        );
    }

    private function saved(User $user, ProductGroup $group): void
    {
        WishlistItem::factory()->of($group)->create(['wishlist_id' => $this->list($user)->id]);
    }

    /** A typed or Amazon item: a title, no catalogue product behind it. */
    private function savedByTitle(User $user, string $title): void
    {
        WishlistItem::factory()->create([
            'wishlist_id' => $this->list($user)->id,
            'group_id' => null,
            'source' => Source::Manual,
            'snapshot_title' => $title,
        ]);
    }

    /** @return list<int> */
    private function shown($page): array
    {
        return collect($page->toArray()['props']['results']['items'])->pluck('id')->all();
    }

    #[Test]
    public function a_bare_search_is_led_by_products_that_echo_the_saved_titles(): void
    {
        $user = User::factory()->create();

        $saved = $this->product('Sony WH-1000XM5 draadloze koptelefoon zwart', 'Sony', 'Audio');
        $this->saved($user, $saved);

        $echoes = $this->product('JBL Tune 720BT draadloze koptelefoon', 'JBL', 'Audio');
        $sameBrand = $this->product('Sony Bravia 55 inch televisie', 'Sony', 'TV');
        $inDescription = $this->product('Bose QuietComfort Ultra', 'Bose', 'Audio', description: 'Draadloze koptelefoon met ruisonderdrukking.');
        $this->product('Sennheiser Momentum koptelefoon', 'Sennheiser', 'Audio');
        $sameCategoryOnly = $this->product('Marshall Acton III speaker', 'Marshall', 'Audio');
        $unrelated = $this->product('Lego brandweerkazerne', 'Lego', 'Speelgoed');

        $this->actingAs($user)->get('/be-nl/search')
            ->assertOk()
            ->assertInertia(function ($page) use ($saved, $echoes, $sameBrand, $inDescription, $sameCategoryOnly, $unrelated): void {
                $page->where('seeded', 'lists');
                $shown = $this->shown($page);

                // Titles that echo the saved one lead; the description counts
                // too; a shared brand qualifies; a shared category alone does
                // not, and nothing unrelated appears. The saved product is out.
                $this->assertSame($echoes->id, $shown[0]);
                $this->assertContains($inDescription->id, $shown);
                $this->assertContains($sameBrand->id, $shown);
                $this->assertNotContains($sameCategoryOnly->id, $shown);
                $this->assertNotContains($unrelated->id, $shown);
                $this->assertNotContains($saved->id, $shown);
            });
    }

    #[Test]
    public function a_typed_or_amazon_item_seeds_the_landing_too(): void
    {
        $user = User::factory()->create();
        $this->savedByTitle($user, 'Subway Art: New York graffiti fotoboek');

        $graffiti = $this->product('New York graffiti fotoboek jaren 80', 'Uitgeverij X', 'Boek');
        $this->product('Street art fotoboek Berlijn', 'Uitgeverij Y', 'Boek');
        $this->product('Graffiti stencils en street art', 'Uitgeverij Z', 'Boek');
        $this->product('New York fotoboek metro', 'Uitgeverij X', 'Boek');
        $childrens = $this->product('Dino kinderboek met flapjes', 'Uitgeverij X', 'Boek');

        $this->actingAs($user)->get('/be-nl/search')
            ->assertInertia(function ($page) use ($graffiti, $childrens): void {
                $page->where('seeded', 'lists');
                $shown = $this->shown($page);

                $this->assertContains($graffiti->id, $shown);
                // Same shelf, nothing in common with the title: not shown.
                $this->assertNotContains($childrens->id, $shown);
            });
    }

    #[Test]
    public function a_product_saved_on_another_market_seeds_this_one_and_its_twin_is_left_out(): void
    {
        $user = User::factory()->create();

        $belgian = $this->product('Garmin Venu 3S smartwatch goud', 'Garmin', 'Horloge', Market::BeNl, 'ean:1234567890123');
        $this->saved($user, $belgian);

        $dutchTwin = $this->product('Garmin Venu 3S smartwatch goud', 'Garmin', 'Horloge', Market::NlNl, 'ean:1234567890123');
        $sibling = $this->product('Garmin Vivoactive 5 smartwatch', 'Garmin', 'Horloge', Market::NlNl);
        $this->product('Garmin Forerunner 265 smartwatch', 'Garmin', 'Horloge', Market::NlNl);
        $this->product('Amazfit smartwatch zwart', 'Amazfit', 'Horloge', Market::NlNl);
        $this->product('Fitbit Versa 4 smartwatch', 'Fitbit', 'Horloge', Market::NlNl);

        $this->actingAs($user)->get('/nl-nl/search')
            ->assertInertia(function ($page) use ($dutchTwin, $sibling): void {
                $page->where('seeded', 'lists');
                $shown = $this->shown($page);

                $this->assertContains($sibling->id, $shown);
                $this->assertNotContains($dutchTwin->id, $shown);
            });
    }

    #[Test]
    public function the_ordinary_grid_stays_for_a_term_a_filter_a_stranger_and_too_thin_a_match(): void
    {
        $user = User::factory()->create();
        $this->saved($user, $this->product('Sony koptelefoon', 'Sony', 'Audio'));

        foreach (range(1, 4) as $i) {
            $this->product("Koptelefoon model {$i}", 'Merk', 'Audio');
        }

        // A term or a filter is a question of its own.
        $this->actingAs($user)->get('/be-nl/search?q=lego')
            ->assertInertia(fn ($page) => $page->where('seeded', null));
        $this->actingAs($user)->get('/be-nl/search?min=10')
            ->assertInertia(fn ($page) => $page->where('seeded', null));

        // Nothing saved, nothing to seed from.
        auth()->logout();
        $this->get('/be-nl/search')
            ->assertInertia(fn ($page) => $page->where('seeded', null));

        // Fewer matches than a row is worth: the ordinary grid.
        $thin = User::factory()->create();
        $this->saved($thin, $this->product('Zeldzaam ding', 'Nobody', 'Niets'));
        $this->actingAs($thin)->get('/be-nl/search')
            ->assertInertia(fn ($page) => $page->where('seeded', null));
    }
}

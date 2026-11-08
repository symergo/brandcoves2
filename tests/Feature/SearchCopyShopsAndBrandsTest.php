<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\CoveKind;
use App\Enums\ListKind;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\PublishStatus;
use App\Enums\Source;
use App\Jobs\ReadItemLink;
use App\Models\BrandStat;
use App\Models\DailyPickSet;
use App\Models\Merchant;
use App\Models\PageBlock;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Pages\Context\ResultCategories;
use App\Services\Pages\Context\SearchContext;
use App\Services\Pages\PageCopy;
use App\Services\Pages\Placeholders\CoveLinks;
use App\Services\Pages\Placeholders\GuideLinks;
use App\Services\Pages\Placeholders\OtherBrandLinks;
use App\Services\Pages\Placeholders\SaveSearchLink;
use App\Services\Pages\Placeholders\ShopLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The copy under the search results names the shops it compares and other
 * brands of this kind of product (owner, 2026-11-08).
 */
class SearchCopyShopsAndBrandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        PageCopy::flush();
        // The September copy an earlier migration seeds: these tests write
        // their own.
        PageBlock::query()->where('page', 'search')->delete();
    }

    private function group(string $brand, string $category = 'Koptelefoons'): ProductGroup
    {
        static $n = 0;
        $n++;

        return ProductGroup::create([
            'market' => Market::BeNl->value,
            'identity_key' => 'sc-'.$n,
            'identity_kind' => 'title',
            'title' => "{$brand} koptelefoon {$n}",
            'slug' => 'sc-'.$n,
            'brand' => $brand,
            'category' => $category,
            'min_price' => 1999,
            'merchant_count' => 1,
        ]);
    }

    private function offer(ProductGroup $group, string $shop, int $price, string $url = 'https://example.test/buy'): Product
    {
        $merchant = Merchant::firstOrCreate(
            ['source' => 'awin', 'external_id' => Str::slug($shop)],
            ['name' => $shop, 'enabled' => true],
        );

        return Product::create([
            'source' => Source::Awin,
            'market' => $group->market,
            'merchant_id' => $merchant->id,
            'group_id' => $group->id,
            'external_id' => 'x'.bin2hex(random_bytes(4)),
            'title' => $group->title,
            'price' => $price,
            'currency' => 'EUR',
            'affiliate_url' => $url,
            'availability' => Availability::InStock,
            'status' => ProductStatus::Active,
            'identity_key' => $group->identity_key,
        ]);
    }

    #[Test]
    public function each_shop_links_as_a_sponsored_click_out_to_its_cheapest_product_here(): void
    {
        $a = $this->group('Aurex');
        $b = $this->group('Belka');
        $this->offer($a, 'Coolblue BE', 3000);
        $cheap = $this->offer($b, 'Coolblue BE', 2000);
        $this->offer($a, 'Fnac', 2500);
        // An unsafe address never becomes a link.
        $this->offer($b, 'Sketchy', 100, 'javascript:alert(1)');

        $links = (new ShopLinks)->resolve(new SearchContext(Market::BeNl, [$a, $b], 2, 'koptelefoon'))->items;

        // The most offers first, the name without its country suffix.
        $this->assertSame(['Coolblue', 'Fnac'], array_column($links, 'label'));
        $this->assertSame(route('go', ['market' => 'be-nl', 'offer' => $cheap->id]), $links[0]['url']);
        $this->assertSame('sponsored nofollow', $links[0]['rel']);
    }

    #[Test]
    public function other_brands_are_the_categorys_brands_not_already_in_the_results(): void
    {
        $a = $this->group('Aurex');

        // Ranked by their in-stock products in the category; another
        // category's brand, a sub-brand of a brand here, and a brand with
        // nothing in stock do not appear.
        $this->stock('Bose', 'Koptelefoons', 3);
        $this->stock('Sennheiser', 'Koptelefoons', 2);
        $this->stock('Kettlemax', 'Waterkokers', 5);
        $this->stock('Aurex Pro', 'Koptelefoons', 4);
        $this->stock('Gone', 'Koptelefoons', 6, inStock: false);

        foreach (['Aurex', 'Bose', 'Sennheiser', 'Kettlemax', 'Aurex Pro', 'Gone'] as $brand) {
            $this->brandPage($brand);
        }

        $links = (new OtherBrandLinks)->resolve(new SearchContext(Market::BeNl, [$a], 1, 'koptelefoon'))->items;

        $this->assertSame(['Bose', 'Sennheiser'], array_column($links, 'label'));
        $this->assertStringContainsString('/brand/bose', $links[0]['url']);
    }

    #[Test]
    public function two_spellings_of_one_brand_are_offered_once(): void
    {
        $a = $this->group('Aurex');

        // "BLACK + DECKER" and "BLACK+DECKER" both have a page on production.
        $this->stock('BLACK + DECKER', 'Koptelefoons', 3);
        $this->stock('BLACK+DECKER', 'Koptelefoons', 2);
        $this->brandPage('BLACK + DECKER');
        $this->brandPage('BLACK+DECKER');

        $links = (new OtherBrandLinks)->resolve(new SearchContext(Market::BeNl, [$a], 1, 'koptelefoon'))->items;

        // The commoner spelling, once.
        $this->assertSame(['BLACK + DECKER'], array_column($links, 'label'));
    }

    /** Products of a brand in a category, shown and in stock unless said otherwise. */
    private function stock(string $brand, string $category, int $count, bool $inStock = true): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->group($brand, $category)->forceFill(['worth_showing' => true, 'in_stock' => $inStock])->save();
        }
    }

    private function brandPage(string $brand): void
    {
        BrandStat::create([
            'market' => Market::BeNl->value, 'brand' => $brand, 'slug' => Str::slug($brand),
            'aliases' => [$brand], 'product_count' => 100,
        ]);
    }

    #[Test]
    public function a_heading_whose_section_came_out_empty_is_dropped(): void
    {
        foreach ([[1, 'heading', 'Meer lezen over :term'], [2, 'paragraph', 'Lees ook :guide_links.'], [3, 'heading', 'Andere merken'], [4, 'paragraph', 'Alles over :term.']] as [$position, $kind, $body]) {
            PageBlock::create(['page' => 'search', 'region' => 'below_grid', 'language' => 'nl', 'kind' => $kind, 'position' => $position, 'conditions' => [], 'enabled' => true])
                ->variants()->create(['body' => $body, 'weight' => 1, 'enabled' => true]);
        }

        // No guide matches, so the first paragraph is hidden and its heading
        // goes with it.
        $blocks = (new PageCopy)->forRegion('search', 'below_grid', new SearchContext(Market::BeNl, [], 0, 'koptelefoon'));

        $this->assertSame(['heading', 'paragraph'], array_column($blocks, 'kind'));
        $this->assertSame('Andere merken', $blocks[0]['parts'][0]['v']);
    }

    #[Test]
    public function the_migration_rewrites_unedited_copy_and_leaves_an_edited_language_alone(): void
    {
        // The copy as it shipped on 2026-11-07, read from that migration, so
        // this test cannot drift from what production actually holds.
        $shipped = (new \ReflectionClass(require database_path('migrations/2026_11_07_000300_search_copy_with_links.php')))->getConstant('COPY');

        foreach ($shipped as $language => $blocks) {
            foreach ($blocks as $i => $block) {
                PageBlock::create(['page' => 'search', 'region' => 'below_grid', 'language' => $language, 'kind' => $block['kind'], 'position' => $i + 1, 'conditions' => $block['conditions'], 'enabled' => true])
                    ->variants()->create(['body' => $block['body'], 'weight' => 1, 'enabled' => true]);
            }
        }

        // One French block an editor rewrote: French stays as it is.
        DB::table('page_block_variants')->where('body', 'Acheter :term moins cher')->update(['body' => 'Acheter :term au meilleur prix']);

        $migration = require database_path('migrations/2026_11_08_000100_search_copy_compares_shops_and_brands.php');
        $migration->up();
        // A second run changes nothing.
        $migration->up();

        $bodies = fn (string $language) => DB::table('page_blocks as b')->join('page_block_variants as v', 'v.block_id', '=', 'b.id')
            ->where('b.page', 'search')->where('b.language', $language)->orderBy('b.position')->pluck('v.body')->all();

        $nl = $bodies('nl');
        $this->assertSame(':term prijzen vergelijken', $nl[0]);
        $this->assertStringStartsWith('Je vergelijkt hier prijzen voor :term bij :shop_links', $nl[1]);
        $this->assertContains('Zoek je alternatieve merken voor :top_brand? Bekijk dan de aanbiedingen en offertes van :other_brand_links.', $nl);
        $this->assertContains('Wil je :term als cadeau geven of zelf krijgen? :save_search_link.', $nl);
        $this->assertContains('Vergelijk dan de beste aanbiedingen voor :term_links.', $nl);
        $this->assertCount(11, $nl);
        $this->assertNotContains('Je vindt hier :term van onder meer :brand_links.', $nl);

        // The owner's rule: nothing generic. Every sentence names something found.
        foreach ([...$nl, ...$bodies('en')] as $body) {
            $this->assertDoesNotMatchRegularExpression('/zoekopdracht|producten|this search|products/i', $body, $body);
        }

        $this->assertContains('Acheter :term au meilleur prix', $bodies('fr'));
        $this->assertCount(13, $bodies('fr'));
    }

    #[Test]
    public function the_top_brand_is_the_one_most_results_carry(): void
    {
        $context = new SearchContext(Market::BeNl, [$this->group('Belka'), $this->group('Aurex'), $this->group('aurex')], 3, 'koptelefoon');

        $this->assertSame('Aurex', $context->fact('top_brand'));
    }

    #[Test]
    public function save_this_search_is_a_save_button_named_in_the_pages_language(): void
    {
        $value = (new SaveSearchLink)->resolve(new SearchContext(Market::BeNl, [], 0, 'lenovo'));

        $this->assertSame('save', $value->toPart()['t']);
        $this->assertSame([
            'label' => 'Bewaar lenovo op je cadeaulijst',
            'title' => 'Lenovo aanbiedingen',
            'url' => url('/be-nl/zoek/lenovo'),
        ], $value->items[0]);
    }

    #[Test]
    public function a_list_item_linking_to_our_own_page_is_not_read_like_a_shops(): void
    {
        config(['app.url' => 'https://giftcoves.com', 'giftcoves.legacy_hosts' => ['brandcoves.com']]);

        $this->assertTrue(WishlistItem::isOwnUrl('https://giftcoves.com/be-nl/zoek/lenovo'));
        $this->assertTrue(WishlistItem::isOwnUrl('https://brandcoves.com/be-nl/zoek/lenovo'));
        $this->assertFalse(WishlistItem::isOwnUrl('https://www.bol.com/be/nl/s/?searchtext=lenovo'));

        // http is accepted for our own address only (a development machine).
        config(['app.url' => 'http://localhost:8000']);
        $this->assertTrue(WishlistItem::isSafeExternalUrl('http://localhost:8000/be-nl/zoek/lenovo'));
        $this->assertFalse(WishlistItem::isSafeExternalUrl('http://www.bol.com/x'));
        $this->assertFalse(WishlistItem::isSafeExternalUrl('javascript:alert(1)'));
    }

    #[Test]
    public function saving_the_search_puts_its_results_on_the_list_without_reading_our_own_page(): void
    {
        Queue::fake();
        config(['app.url' => 'http://localhost']);
        $user = User::factory()->create();
        $list = Wishlist::factory()->create(['owner_user_id' => $user->id, 'kind' => ListKind::Mine, 'title' => 'Camping', 'market' => Market::BeNl]);
        $save = (new SaveSearchLink)->resolve(new SearchContext(Market::BeNl, [], 0, 'lenovo'))->items[0];

        // What the button sends: a hand-written item with a title and a link.
        $this->actingAs($user)->postJson('/be-nl/list-items', [
            'source' => 'manual',
            'url' => $save['url'],
            'title' => $save['title'],
            'wishlist_id' => $list->id,
        ])->assertOk();

        $item = WishlistItem::query()->where('wishlist_id', $list->id)->sole();
        $this->assertSame('Lenovo aanbiedingen', $item->snapshot_title);
        $this->assertSame(url('/be-nl/zoek/lenovo'), $item->externalUrl());
        $this->assertNull($item->link_status);
        // Drawn on the list as a saved search, with the search icon.
        $this->assertTrue($item->isSavedSearch());
        $this->assertFalse((new WishlistItem(['source' => 'manual', 'snapshot_url' => 'https://www.bol.com/be/nl/p/x/1/']))->isSavedSearch());
        Queue::assertNotPushed(ReadItemLink::class);
    }

    #[Test]
    public function other_brands_come_in_turns_from_the_top_three_categories(): void
    {
        // Three tablets and two laptops: tablet brands may not take every
        // place. One cuddly toy is not a category of this page, and brings no
        // toy brand (the "stanley" case).
        $items = [
            $this->group('Lenovo', 'Tablet'), $this->group('Lenovo', 'Tablet'), $this->group('Lenovo', 'Tablet'),
            $this->group('Lenovo', 'Laptop'), $this->group('Lenovo', 'Laptop'),
            $this->group('Lenovo', 'Knuffel'),
        ];

        foreach ([['Tabby', 'Tablet', 5], ['Slate', 'Tablet', 4], ['Lappo', 'Laptop', 3], ['Kaloo', 'Knuffel', 9]] as [$brand, $category, $count]) {
            $this->stock($brand, $category, $count);
            $this->brandPage($brand);
        }

        $links = (new OtherBrandLinks)->resolve(new SearchContext(Market::BeNl, $items, 6, 'lenovo'))->items;

        $this->assertSame(['Tabby', 'Lappo', 'Slate'], array_column($links, 'label'));
    }

    #[Test]
    public function only_plain_category_names_are_printed_in_brackets(): void
    {
        $items = [
            $this->group('Samsung', 'Tablet'), $this->group('Samsung', 'Tablet'),
            // A brand in the name, and a feed's code: used for brands, never printed.
            $this->group('Samsung', 'Samsung hoesjes'),
            $this->group('Lego', 'LEGO (R) Bouwsets'),
        ];

        $this->assertSame(['tablet'], ResultCategories::shown($items));

        // One laptop among the tablets is not named; two are.
        $this->assertSame(['tablet'], ResultCategories::shown([...$items, $this->group('Samsung', 'Laptop')]));
        // (A tie, two and two, sorts by name.)
        $this->assertSame(['laptop', 'tablet'], ResultCategories::shown([...$items, $this->group('Samsung', 'Laptop'), $this->group('Samsung', 'Laptop')]));
        $this->assertSame(' (tablet)', (new SearchContext(Market::BeNl, $items, 4, 'samsung'))->fact('result_categories'));

        // None fit to print: empty, and the sentence still reads.
        $this->assertSame('', (new SearchContext(Market::BeNl, [$this->group('Samsung', 'Samsung hoesjes')], 1, 'samsung'))->fact('result_categories'));
    }

    private function cove(string $slug, string $title, CoveKind $kind = CoveKind::Persona): DailyPickSet
    {
        return DailyPickSet::create([
            'market' => Market::BeNl->value,
            'kind' => $kind->value,
            'slug' => $slug,
            'theme_title' => $title,
            'theme_slug' => $slug,
            'theme_source' => 'theme',
            'status' => PublishStatus::Published->value,
            'published_at' => now()->subDay(),
        ]);
    }

    #[Test]
    public function the_coves_holding_these_results_are_linked_most_matches_first(): void
    {
        $a = $this->group('Aurex');
        $b = $this->group('Belka');
        $one = $this->cove('voor-de-student', 'Cadeaus voor de student');
        $two = $this->cove('muziekliefhebber', 'Cadeaus voor de muziekliefhebber');
        $this->cove('niets-hiervan', 'Niets hiervan');

        $one->picks()->create(['group_id' => $a->id, 'rank' => 1, 'slug' => 'p-'.uniqid()]);
        $two->picks()->create(['group_id' => $a->id, 'rank' => 1, 'slug' => 'p-'.uniqid()]);
        $two->picks()->create(['group_id' => $b->id, 'rank' => 2, 'slug' => 'p-'.uniqid()]);

        $links = (new CoveLinks)->resolve(new SearchContext(Market::BeNl, [$a, $b], 2, 'koptelefoon'))->items;

        $this->assertSame(['Cadeaus voor de muziekliefhebber', 'Cadeaus voor de student'], array_column($links, 'label'));
        $this->assertSame('/be-nl/gift-ideas/muziekliefhebber', $links[0]['url']);
    }

    #[Test]
    public function with_no_guide_named_after_the_term_the_results_category_finds_one(): void
    {
        $this->cove('tablets-kiezen', 'Een tablet kiezen', CoveKind::Guide);
        $items = [$this->group('Lenovo', 'Tablet'), $this->group('Lenovo', 'Tablet')];

        $links = (new GuideLinks)->resolve(new SearchContext(Market::BeNl, $items, 2, 'lenovo'))->items;

        $this->assertSame(['Een tablet kiezen'], array_column($links, 'label'));
    }
}

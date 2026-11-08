<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\BrandStat;
use App\Models\Merchant;
use App\Models\PageBlock;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Pages\Context\SearchContext;
use App\Services\Pages\PageCopy;
use App\Services\Pages\Placeholders\OtherBrandLinks;
use App\Services\Pages\Placeholders\ShopLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

        foreach ([['Aurex', 50], ['Bose', 40], ['Sennheiser', 30], ['Kettlemax', 0]] as [$brand, $count]) {
            BrandStat::create([
                'market' => Market::BeNl->value,
                'brand' => $brand,
                'slug' => Str::slug($brand),
                'aliases' => [$brand],
                'product_count' => 100,
                'categories' => $count > 0 ? [['category' => 'Koptelefoons', 'count' => $count]] : [['category' => 'Waterkokers', 'count' => 90]],
            ]);
        }

        $links = (new OtherBrandLinks)->resolve(new SearchContext(Market::BeNl, [$a], 1, 'koptelefoon'))->items;

        $this->assertSame(['Bose', 'Sennheiser'], array_column($links, 'label'));
        $this->assertStringContainsString('/brand/bose', $links[0]['url']);
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
        $this->assertContains('Zoek je merken die vergelijkbaar zijn met :top_brand? Kijk dan ook bij :other_brand_links.', $nl);
        $this->assertContains('Vergelijk dan de beste aanbiedingen voor :term_links.', $nl);
        $this->assertCount(12, $nl);

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
}

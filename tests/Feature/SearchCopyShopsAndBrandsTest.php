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
    public function the_migration_revises_only_the_shipped_wording(): void
    {
        $shipped = [
            ':term vergelijken',
            'Prijzen voor :term',
            'Op deze pagina liggen de prijzen voor :term tussen :low en :high. Het verschil zit meestal in het product zelf: een duurder model heeft vaak meer functies of een groter formaat.',
            ':term het goedkoopst kopen',
            'Deze pagina zet :shops aanbiedingen van webwinkels naast elkaar, de goedkoopste eerst. Je koopt altijd bij de winkel zelf; wij verkopen niets.',
            'Zoek je iets specifiekers?',
            'Kijk dan ook bij :term_links.',
            ':term als cadeau',
        ];

        foreach ($shipped as $i => $body) {
            PageBlock::create(['page' => 'search', 'region' => 'below_grid', 'language' => 'nl', 'kind' => str_contains($body, '.') ? 'paragraph' : 'heading', 'position' => $i + 1, 'conditions' => [], 'enabled' => true])
                ->variants()->create(['body' => $body, 'weight' => 1, 'enabled' => true]);
        }

        // An editor's own French heading is left alone.
        PageBlock::create(['page' => 'search', 'region' => 'below_grid', 'language' => 'fr', 'kind' => 'heading', 'position' => 1, 'conditions' => [], 'enabled' => true])
            ->variants()->create(['body' => 'Acheter :term au meilleur prix', 'weight' => 1, 'enabled' => true]);

        $migration = require database_path('migrations/2026_11_08_000100_search_copy_compares_shops_and_brands.php');
        $migration->up();
        // A second run changes nothing.
        $migration->up();

        $nl = DB::table('page_blocks as b')->join('page_block_variants as v', 'v.block_id', '=', 'b.id')
            ->where('b.page', 'search')->where('b.language', 'nl')->where('b.enabled', true)->orderBy('b.position')->pluck('v.body')->all();

        $this->assertSame([
            ':term vergelijken',
            ':term prijzen vergelijken',
            'Deze pagina zet :shops aanbiedingen van webwinkels naast elkaar, de goedkoopste eerst. Je koopt altijd bij de winkel zelf; wij verkopen niets.',
            'Je vergelijkt hier onder meer de prijzen van :shop_links.',
            'Zoek je iets specifiekers?',
            'Vergelijk dan de beste aanbiedingen voor :term_links.',
            'Andere merken',
            'Dit soort producten vind je ook van :other_brand_links.',
            ':term als cadeau',
        ], $nl);

        $this->assertSame(['Acheter :term au meilleur prix'], DB::table('page_blocks as b')->join('page_block_variants as v', 'v.block_id', '=', 'b.id')
            ->where('b.page', 'search')->where('b.language', 'fr')->pluck('v.body')->all());
    }
}

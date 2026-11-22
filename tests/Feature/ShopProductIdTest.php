<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PageReading\ShopProductId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The shop's product id, read in PHP from a pasted link and in SQL from a stored one
 * (`bc_shop_product_id()`), must come out the same, or a pasted link never finds its
 * product. Link shapes taken from production's stored deep links, 2026-11-22.
 */
class ShopProductIdTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string|null}> */
    public static function links(): array
    {
        return [
            'coolblue be, french' => ['https://www.coolblue.be/fr/produit/970016/lenovo-legion-go-s-8apu1-steamos-512-go.html', '970016'],
            'coolblue be, dutch, parameters' => ['https://www.coolblue.be/nl/product/970016/lenovo-legion-go.html?utm_source=x#reviews', '970016'],
            'coolblue nl' => ['https://www.coolblue.nl/product/967169/quooker-fusion-square-chroom.html', '967169'],
            'coolblue home page' => ['https://www.coolblue.be/nl', null],
            'fnac own stock' => ['https://www.nl.fnac.be/Oxford-Touch-Spiraalblok-A4/a13445918?oref=62b031d8-48dc', 'a13445918'],
            'fnac marketplace' => ['https://www.fr.fnac.be/mp11207345/Enveloppes-GPV-Era-Pure?oref=95608165', 'mp11207345'],
            'action' => ['https://shop.action.com/nl-be/p/8721042581679/kerstboomstandaard-slee-met-cadeautjes', '8721042581679'],
            'dreamland' => ['https://www.dreamland.be/nl/producten/miniverse-real-music/02404332', '02404332'],
            'dreamland variant' => ['https://www.dreamland.be/fr/produits/squishmallows-stitch/02371412_002?x=1', '02371412_002'],
            'large' => ['https://www.large.be/p/Spray-Dye-Henley/277826M.html?sc=affshipsc', '277826m'],
            'vanden borre' => ['https://www.vandenborre.be/fr/casque/endurance-pace-black-lime', 'endurance-pace-black-lime'],
            'krefel number' => ['https://www.krefel.be/nl/p/41012699-asus-chromebook-cm14-flip?utm_campaign=datafeed_Awin-NL', '41012699'],
            'krefel letters, http' => ['http://www.krefel.be/fr/p/B8009341-bain-de-pieds-thalasso-etp8-pedispa', 'b8009341'],
            'a shop without a pattern' => ['https://www.coolshop.example/products/headphones-x', null],
        ];
    }

    #[Test]
    #[DataProvider('links')]
    public function php_and_the_database_read_the_same_id(string $url, ?string $id): void
    {
        $this->assertSame($id, ShopProductId::fromUrl($url), 'PHP');
        $this->assertSame($id, DB::scalar('SELECT bc_shop_product_id(?)', [$url]), 'SQL');
    }
}

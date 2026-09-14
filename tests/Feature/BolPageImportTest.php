<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\Source;
use App\Models\ApiToken;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Connectors\Offer;
use App\Services\Ingestion\OfferUpserter;
use App\Services\Ingestion\ProductGrouper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Importing a page of bol products from the browser extension.
 *
 * Three properties carry this feature:
 *
 * 1. **The page chooses; bol states the facts.** A request carries ids and
 *    titles. Whatever it claims about price, image or link is ignored, and the
 *    affiliate URL — the field where a forged value would silently redirect a
 *    visitor's purchase — is built from bol's own product URL.
 * 2. **A page id is matched exactly or not at all.** Titles are how candidates
 *    are enumerated; `bolProductId` is how one is chosen. A close title must
 *    never substitute a different product.
 * 3. **Nothing goes missing quietly.** Every id sent comes back with a status,
 *    because a curator who exports twenty and gets fourteen needs to know which
 *    six and why.
 */
class BolPageImportTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'giftcoves.connectors.bol.enabled' => true,
            'giftcoves.connectors.bol.client_id' => 'test-id',
            'giftcoves.connectors.bol.client_secret' => 'test-secret',
            'giftcoves.connectors.bol.partner_site_id' => ['BE' => '25421', 'NL' => '1005548'],
        ]);

        Cache::flush();

        // The rate limiter talks to Redis directly rather than through the
        // cache store, on purpose — sharing state across processes is its whole
        // job — so Cache::flush() does not reset it. Without this the bucket
        // drains part-way through the class and later tests make no requests at
        // all, which looks like a broken connector rather than a drained token.
        foreach (['search', 'product'] as $bucket) {
            Redis::del("bc:ratelimit:bol:{$bucket}", "bc:ratelimit:bol:{$bucket}:cooldown");
        }

        $this->token = ApiToken::issue('extension', [ApiToken::READ, ApiToken::WRITE])['token'];
    }

    /**
     * bol's real payload shape, as returned by the live API on 2026-09-14.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function product(array $overrides = []): array
    {
        return array_merge([
            'bolProductId' => '9200000032872507',
            'ean' => '4905524930184',
            'title' => 'Sony MDR-ZX110 - On-ear koptelefoon - Zwart',
            'description' => '<p>Luister lekker een muziekje.</p>',
            'url' => 'https://www.bol.com/nl/nl/p/sony-mdr-zx110-on-ear-koptelefoon-zwart/9200000032872507/',
            'image' => ['url' => 'https://media.s-bol.com/qxxrK46A8Ko7/250x200.jpg'],
            'gpc' => [
                ['level' => 'SEGMENT', 'name' => 'Audio Visual/Photography'],
                ['level' => 'CHUNK', 'name' => 'Koptelefoon'],
            ],
            'offer' => ['price' => 15.0],
        ], $overrides);
    }

    /** @param array<string, mixed> $responses */
    private function fakeBol(array $responses): void
    {
        Http::fake([
            'login.bol.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 300]),
            ...$responses,
        ]);
    }

    /** @param list<array<string, mixed>> $products */
    private function importPage(array $products, string $market = 'nl-nl'): TestResponse
    {
        return $this->withToken($this->token)->postJson('/api/editorial/import/bol', [
            'market' => $market,
            'products' => $products,
        ]);
    }

    // ── The happy path ────────────────────────────────────────────────────

    #[Test]
    public function it_imports_a_product_by_its_barcode(): void
    {
        $this->fakeBol(['api.bol.com/*' => Http::response($this->product())]);

        $response = $this->importPage([[
            'productId' => '9200000032872507',
            'ean' => '4905524930184',
            'title' => 'Sony MDR-ZX110',
        ]]);

        $response->assertOk()
            ->assertJsonPath('imported', 1)
            ->assertJsonPath('results.0.status', 'imported');

        $product = Product::query()->where('external_id', '9200000032872507')->sole();

        // bol's title, not the page's. The scraped one was a truncated heading.
        $this->assertSame('Sony MDR-ZX110 - On-ear koptelefoon - Zwart', $product->title);
        // Integer cents. 15.0 must not become 15 or 1500.0.
        $this->assertSame(1500, $product->price);
        $this->assertSame(Market::NlNl->value, $product->market->value);

        // The group is what a visitor sees, and it is what the extension shows
        // back. An import that writes an offer nobody can reach is not an
        // import.
        $this->assertNotNull($product->group_id);
        $response->assertJsonPath('results.0.group.id', $product->group_id);
    }

    #[Test]
    public function a_barcode_lookup_asks_bol_for_the_offer_and_the_image(): void
    {
        /*
         * Without include-offer and include-image bol returns the catalogue
         * entry alone — no price, no picture — and the row is stored unbuyable
         * and unrenderable. Without country-code the request is a 400 outright.
         *
         * This is not hypothetical: the product endpoint was called with no
         * parameters at all until 2026-09-14, so every wishlist price refresh
         * on a bol product silently did nothing. Asserted on the wire rather
         * than on the result, because a missing flag produces a plausible
         * response rather than an error.
         */
        $this->fakeBol(['api.bol.com/*' => Http::response($this->product())]);

        $this->importPage([['productId' => '9200000032872507', 'ean' => '4905524930184']])->assertOk();

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/products/4905524930184')) {
                return false;
            }

            return $request['country-code'] === 'NL'
                && $request['include-offer'] === 'true'
                && $request['include-image'] === 'true';
        });
    }

    #[Test]
    public function without_a_barcode_it_resolves_the_page_id_through_a_title_search(): void
    {
        // A listing page carries no barcode — only the id in the link and the
        // words in the heading. bol's search is the only way back to the record.
        $this->fakeBol(['api.bol.com/*' => Http::response(['results' => [$this->product()]])]);

        $this->importPage([[
            'productId' => '9200000032872507',
            'title' => 'Sony MDR-ZX110 - On-ear koptelefoon - Zwart',
        ]])->assertOk()->assertJsonPath('results.0.status', 'imported');

        $this->assertDatabaseHas('products', [
            'external_id' => '9200000032872507',
            'market' => Market::NlNl->value,
        ]);
    }

    // ── The page is never believed ────────────────────────────────────────

    #[Test]
    public function a_title_search_that_returns_a_different_product_imports_nothing(): void
    {
        /*
         * The load-bearing test for the title route.
         *
         * On bol one product name routinely covers six colourways, so a title
         * search returning "a product with this name" is not the same as
         * returning "the product somebody pointed at". The id comparison is
         * exact, and a near-miss must produce NOTHING rather than a plausible
         * substitute — a curator would never spot the difference, and the
         * product in the article would not be the one in the picture.
         */
        $this->fakeBol(['api.bol.com/*' => Http::response(['results' => [
            $this->product(['bolProductId' => '9200000099999999', 'ean' => '1111111111116']),
        ]])]);

        $this->importPage([[
            'productId' => '9200000032872507',
            'title' => 'Sony MDR-ZX110 - On-ear koptelefoon - Zwart',
        ]])->assertOk()
            ->assertJsonPath('imported', 0)
            ->assertJsonPath('results.0.status', 'unresolved');

        $this->assertDatabaseCount('products', 0);
    }

    #[Test]
    public function the_stored_link_is_bols_own_tracked_url_not_anything_the_client_sent(): void
    {
        /*
         * The field where a forged value costs real money, and silently: an
         * untracked or redirected affiliate URL works perfectly for the
         * visitor, and the commission goes to somebody else. Nothing about the
         * link may originate in the request.
         */
        $this->fakeBol(['api.bol.com/*' => Http::response($this->product())]);

        $this->withToken($this->token)->postJson('/api/editorial/import/bol', [
            'market' => 'nl-nl',
            'products' => [[
                'productId' => '9200000032872507',
                'ean' => '4905524930184',
                // All ignored — the endpoint does not even accept these.
                'affiliateUrl' => 'https://evil.example/steal',
                'price' => 1,
                'imageUrl' => 'https://evil.example/pixel.png',
            ]],
        ])->assertOk();

        $product = Product::query()->sole();

        $this->assertStringStartsWith('https://partner.bol.com/click/click?', $product->affiliate_url);
        $this->assertStringNotContainsString('evil.example', $product->affiliate_url);
        $this->assertStringNotContainsString('evil.example', (string) $product->image_url);
        $this->assertSame(1500, $product->price);
    }

    // ── Nothing goes missing quietly ──────────────────────────────────────

    #[Test]
    public function a_product_bol_is_not_selling_is_reported_rather_than_stored(): void
    {
        // There is no availability flag in bol's payload — the presence of an
        // offer block is the signal — so this is the only shape "out of stock"
        // takes, and it is worth telling apart from "we could not find it".
        $this->fakeBol(['api.bol.com/*' => Http::response($this->product(['offer' => null]))]);

        $this->importPage([['productId' => '9200000032872507', 'ean' => '4905524930184']])
            ->assertOk()
            ->assertJsonPath('imported', 0)
            ->assertJsonPath('results.0.status', 'unavailable');

        $this->assertDatabaseCount('products', 0);
    }

    #[Test]
    public function every_id_sent_comes_back_with_a_status(): void
    {
        // A shelf where one product resolves and one does not. The shortfall
        // has to be attributable, or the curator is left counting cards.
        $this->fakeBol(['api.bol.com/*' => Http::response(['results' => [$this->product()]])]);

        $response = $this->importPage([
            ['productId' => '9200000032872507', 'title' => 'Sony MDR-ZX110 - On-ear koptelefoon - Zwart'],
            ['productId' => '9200000000000001', 'title' => 'Iets wat bol niet kent'],
        ]);

        $response->assertOk()
            ->assertJsonPath('requested', 2)
            ->assertJsonPath('imported', 1)
            ->assertJsonPath('results.0.productId', '9200000032872507')
            ->assertJsonPath('results.0.status', 'imported')
            ->assertJsonPath('results.1.productId', '9200000000000001')
            ->assertJsonPath('results.1.status', 'unresolved');
    }

    #[Test]
    public function the_same_product_linked_three_times_on_a_page_is_imported_once(): void
    {
        // A bol listing page links each product from its image, its title and
        // its compare control, so the raw scrape repeats itself.
        $this->fakeBol(['api.bol.com/*' => Http::response($this->product())]);

        $this->importPage([
            ['productId' => '9200000032872507', 'ean' => '4905524930184'],
            ['productId' => '9200000032872507', 'ean' => '4905524930184'],
            ['productId' => '9200000032872507', 'title' => 'Sony MDR-ZX110'],
        ])->assertOk()->assertJsonPath('requested', 1)->assertJsonPath('imported', 1);

        $this->assertDatabaseCount('products', 1);
    }

    // ── Grouping ──────────────────────────────────────────────────────────

    #[Test]
    public function an_imported_product_joins_an_existing_card_rather_than_making_a_second_one(): void
    {
        /*
         * The reason this goes through the ordinary ingestion path at all.
         *
         * Another shop already sells this barcode, so the import must land as a
         * second offer on one card — that is offer comparison, the thing the
         * site is for — rather than as a near-duplicate card beside it.
         */
        $this->seedRivalOffer('4905524930184');

        $before = ProductGroup::query()->count();

        $this->fakeBol(['api.bol.com/*' => Http::response($this->product())]);

        $response = $this->importPage([['productId' => '9200000032872507', 'ean' => '4905524930184']]);

        $response->assertOk()->assertJsonPath('results.0.status', 'imported');

        $this->assertSame($before, ProductGroup::query()->count(), 'the import created a second card for one product');

        $group = ProductGroup::query()->sole();
        $this->assertSame(2, $group->offer_count);
        // The cheaper of the two. Offer comparison is the aggregate being tested.
        $this->assertSame(1500, $group->min_price);
    }

    /** An Awin offer for the same barcode, already grouped. */
    private function seedRivalOffer(string $ean): void
    {
        $merchant = Merchant::create([
            'source' => Source::Awin->value,
            'external_id' => 'rival',
            'name' => 'Rival Shop',
        ]);

        app(OfferUpserter::class)->upsert([
            new Offer(
                source: Source::Awin,
                externalId: 'rival-1',
                market: Market::NlNl,
                title: 'Sony MDR-ZX110 koptelefoon',
                affiliateUrl: 'https://www.awin1.com/pclick.php?p=1',
                price: 1999,
                merchantName: $merchant->name,
                merchantExternalId: 'rival',
                merchantDeepLink: 'https://rival.example/p/1',
                imageUrl: 'https://rival.example/1.jpg',
                ean: $ean,
                availability: Availability::InStock,
            ),
        ]);

        app(ProductGrouper::class)->run(Market::NlNl);
    }

    // ── Guards ────────────────────────────────────────────────────────────

    #[Test]
    public function a_market_bol_does_not_serve_is_refused_with_a_reason(): void
    {
        // bol does not operate in Spain, and `en` was cut off from it because
        // bol has no English catalogue. An extension pointed at the wrong
        // market must be told so, not handed an empty result.
        $this->fakeBol(['api.bol.com/*' => Http::response($this->product())]);

        $this->importPage([['productId' => '9200000032872507']], 'es')
            ->assertStatus(422)
            ->assertJsonPath('errors.market.0', 'bol is available for be-nl, be-fr and nl-nl.');
    }

    #[Test]
    public function a_read_only_key_cannot_import(): void
    {
        $readOnly = ApiToken::issue('reader', [ApiToken::READ])['token'];

        $this->withToken($readOnly)->postJson('/api/editorial/import/bol', [
            'market' => 'nl-nl',
            'products' => [['productId' => '9200000032872507']],
        ])->assertForbidden();
    }

    #[Test]
    public function it_requires_a_token(): void
    {
        $this->postJson('/api/editorial/import/bol', [
            'market' => 'nl-nl',
            'products' => [['productId' => '9200000032872507']],
        ])->assertUnauthorized();
    }

    #[Test]
    public function a_product_id_that_is_not_a_bol_id_is_rejected(): void
    {
        $this->importPage([['productId' => '../../etc/passwd']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('products.0.productId');
    }

    #[Test]
    public function more_products_than_a_page_can_hold_are_refused(): void
    {
        // The ceiling is what stops one request becoming hundreds of upstream
        // calls; title resolution is one bol search per product.
        $products = array_map(
            fn (int $i) => ['productId' => (string) (9200000000000000 + $i)],
            range(1, 61),
        );

        $this->importPage($products)->assertStatus(422)->assertJsonValidationErrors('products');
    }
}

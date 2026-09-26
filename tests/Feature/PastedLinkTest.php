<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\Market;
use App\Enums\Source;
use App\Jobs\ReadItemLink;
use App\Models\AmazonProduct;
use App\Models\Merchant;
use App\Models\ProductGroup;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\PageReading\FetchRefused;
use App\Services\PageReading\HostResolver;
use App\Services\PageReading\PageReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A link pasted into a list is looked up: in our catalogue and through the
 * connectors first, and only then by reading the shop's page.
 *
 * The owner's rule these tests hold in place: a site we have in the feed
 * database or reach through a connector is never fetched.
 */
class PastedLinkTest extends TestCase
{
    use RefreshDatabase;

    private const EAN = '4006381333931';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Storage::fake('media');

        // Every shop in these tests is on the public internet.
        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function addresses(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
    }

    #[Test]
    public function saving_a_link_without_a_title_queues_a_lookup_and_shows_the_shop(): void
    {
        Queue::fake();
        [$owner, $list] = $this->list();

        $this->actingAs($owner)->post('/be-nl/list-items', [
            'wishlist_id' => $list->id,
            'source' => 'manual',
            'url' => 'https://www.small-shop.example/products/mug',
        ])->assertRedirect();

        $item = WishlistItem::query()->sole();

        $this->assertSame('small-shop.example', $item->snapshot_title, 'Called after the shop until the page is read.');
        $this->assertSame('pending', $item->link_status);
        Queue::assertPushed(ReadItemLink::class, fn (ReadItemLink $job) => $job->itemId === $item->id);
    }

    #[Test]
    public function a_title_or_a_link_is_still_required(): void
    {
        [$owner, $list] = $this->list();

        $this->actingAs($owner)->post('/be-nl/list-items', [
            'wishlist_id' => $list->id,
            'source' => 'manual',
        ])->assertSessionHasErrors('title');
    }

    #[Test]
    public function an_unknown_shops_page_fills_in_the_item_and_its_picture_is_copied(): void
    {
        [$owner, $list] = $this->list();

        Http::fake([
            'https://small-shop.example/products/mug' => Http::response($this->productPage([
                'name' => 'Stoneware mug, sage',
                'image' => 'https://small-shop.example/mug.png',
                'offers' => ['price' => '24.50', 'priceCurrency' => 'EUR'],
            ]), 200, ['Content-Type' => 'text/html']),
            'https://small-shop.example/mug.png' => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
        ]);

        $this->actingAs($owner)->post('/be-nl/list-items', [
            'wishlist_id' => $list->id,
            'source' => 'manual',
            'url' => 'https://small-shop.example/products/mug',
        ]);

        $item = WishlistItem::query()->sole();

        $this->assertSame('read', $item->link_status);
        $this->assertSame('Stoneware mug, sage', $item->snapshot_title);
        $this->assertSame(2450, $item->snapshot_price);
        $this->assertTrue($item->isManual(), 'Still the person\'s own item: we hold no product for it.');

        // Ours, not the shop's: a shared list must not load a picture from a
        // host the owner chose (a tracking pixel).
        $this->assertMatchesRegularExpression('#^/media/items/[0-9a-f-]{36}\.webp$#', (string) $item->snapshot_image_url);
        Storage::disk('media')->assertExists(substr((string) $item->snapshot_image_url, strlen('/media/')));
    }

    #[Test]
    public function a_page_the_shop_will_not_show_still_gives_the_name_in_its_link(): void
    {
        // A 404 does not pass (a 403 is retried first; the sync queue in
        // tests does not retry): the item keeps the link, and the host as a
        // title is replaced by the product's name from the address.
        [$owner, $list] = $this->list();

        Http::fake(['https://www.debijenkorf.be/*' => Http::response('gone', 404, ['Content-Type' => 'text/html'])]);

        $this->actingAs($owner)->post('/be-nl/list-items', [
            'wishlist_id' => $list->id,
            'source' => 'manual',
            'url' => 'https://www.debijenkorf.be/d/bialetti-moka-express-percolator-6-kops-8834090013-883409001300000',
        ]);

        $item = WishlistItem::query()->sole();

        $this->assertSame('failed', $item->link_status);
        $this->assertSame('Bialetti moka express percolator 6 kops', $item->snapshot_title);
    }

    #[Test]
    public function a_shop_that_refuses_us_is_read_through_iframely_picture_and_all(): void
    {
        config(['giftcoves.page_reading.iframely_key' => 'test-key']);
        [$owner, $list] = $this->list();

        $page = 'https://www.debijenkorf.be/d/bialetti-moka-express-percolator-6-kops-8834090013-883409001300000';

        Http::fake([
            // The shop and its picture server both refuse us, as de Bijenkorf's did.
            'https://www.debijenkorf.be/*' => Http::response('no', 403, ['Content-Type' => 'text/html']),
            'https://cdn-1.debijenkorf.be/*' => Http::response('no', 403, ['Content-Type' => 'text/html']),
            'https://iframe.ly/api/iframely*' => Http::response([
                'meta' => [
                    'title' => 'Bialetti Moka Express percolator 6-kops - Zwart',
                    'brand' => 'Bialetti',
                    'price' => 33.95,
                    'currency' => 'EUR',
                    'availability' => 'https://schema.org/InStock',
                ],
                'links' => ['thumbnail' => [['href' => 'https://cdn-1.debijenkorf.be/default/moka.jpg']]],
            ]),
            'https://iframe.ly/api/thumbnail*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
        ]);

        $this->actingAs($owner)->post('/be-nl/list-items', [
            'wishlist_id' => $list->id,
            'source' => 'manual',
            'url' => $page,
        ]);

        $item = WishlistItem::query()->sole();

        $this->assertSame('read', $item->link_status);
        $this->assertSame('Bialetti Moka Express percolator 6-kops - Zwart', $item->snapshot_title);
        $this->assertSame(3395, $item->snapshot_price);
        $this->assertMatchesRegularExpression('#^/media/items/[0-9a-f-]{36}\.webp$#', (string) $item->snapshot_image_url);

        // Only the link goes to Iframely: nothing about who pasted it.
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://iframe.ly/api/iframely')
            && $request['url'] === $page
            && array_keys($request->data()) === ['url', 'key']);
    }

    #[Test]
    public function iframely_is_not_asked_past_its_daily_cap(): void
    {
        config(['giftcoves.page_reading.iframely_key' => 'test-key', 'giftcoves.page_reading.iframely_per_day' => 0]);
        [$owner, $list] = $this->list();

        // A 404 would not ask Iframely anyway; a 403 would, and the cap stops it.
        Http::fake(['https://www.debijenkorf.be/*' => Http::response('no', 403, ['Content-Type' => 'text/html'])]);

        $this->actingAs($owner)->post('/be-nl/list-items', [
            'wishlist_id' => $list->id,
            'source' => 'manual',
            'url' => 'https://www.debijenkorf.be/d/bialetti-moka-express-percolator-6-kops-8834090013-883409001300000',
        ]);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'iframe.ly'));
    }

    #[Test]
    public function a_refusal_that_may_pass_is_not_remembered(): void
    {
        [$owner, $list] = $this->list();

        // Bot protection refuses at random: the first try is refused, the
        // next one is let through and must actually be made.
        Http::fake(['https://shop.example/*' => Http::sequence()
            ->push('no', 403, ['Content-Type' => 'text/html'])
            ->push($this->productPage(['name' => 'Linnen schort']), 200, ['Content-Type' => 'text/html'])]);

        $reader = app(PageReader::class);

        try {
            $reader->read('https://shop.example/schort');
            $this->fail('The 403 was not thrown.');
        } catch (FetchRefused $e) {
            $this->assertTrue(PageReader::isPassing($e));
        }

        $this->assertSame('Linnen schort', $reader->read('https://shop.example/schort')?->title);
    }

    #[Test]
    public function a_typed_title_and_price_are_never_overwritten_by_the_page(): void
    {
        [$owner, $list] = $this->list();

        Http::fake(['https://small-shop.example/*' => Http::response($this->productPage([
            'name' => 'SHOP TITLE IN CAPITALS',
            'offers' => ['price' => '99.00', 'priceCurrency' => 'EUR'],
        ]), 200, ['Content-Type' => 'text/html'])]);

        $this->actingAs($owner)->post('/be-nl/list-items', [
            'wishlist_id' => $list->id,
            'source' => 'manual',
            'title' => 'The green mug',
            'price' => 2000,
            'url' => 'https://small-shop.example/products/mug',
        ]);

        $item = WishlistItem::query()->sole();

        $this->assertSame('The green mug', $item->snapshot_title);
        $this->assertSame(2000, $item->snapshot_price);
    }

    #[Test]
    public function a_price_in_another_currency_is_not_shown_as_a_price_here(): void
    {
        [$owner, $list] = $this->list();

        Http::fake(['https://uk-shop.example/*' => Http::response($this->productPage([
            'name' => 'Tea towel',
            'offers' => ['price' => '12.00', 'priceCurrency' => 'GBP'],
        ]), 200, ['Content-Type' => 'text/html'])]);

        $this->actingAs($owner)->post('/be-nl/list-items', [
            'wishlist_id' => $list->id, 'source' => 'manual', 'url' => 'https://uk-shop.example/towel',
        ]);

        $this->assertNull(WishlistItem::query()->sole()->snapshot_price);
    }

    #[Test]
    public function a_page_whose_barcode_we_know_becomes_that_product(): void
    {
        [$owner, $list] = $this->list();
        $group = ProductGroup::factory()->create(['market' => Market::BeNl, 'identity_key' => self::EAN, 'min_price' => 1999]);

        Http::fake(['https://small-shop.example/*' => Http::response($this->productPage([
            'name' => 'Whatever the shop calls it',
            'gtin13' => self::EAN,
        ]), 200, ['Content-Type' => 'text/html'])]);

        $this->actingAs($owner)->post('/be-nl/list-items', [
            'wishlist_id' => $list->id, 'source' => 'manual', 'url' => 'https://small-shop.example/p/1',
        ]);

        $item = WishlistItem::query()->sole();

        $this->assertSame($group->id, $item->group_id);
        $this->assertSame('linked', $item->link_status);
        $this->assertFalse($item->isManual(), 'A catalogue item now: offers, current price, comparison.');
        $this->assertSame($group->path(), $item->snapshot_url);
    }

    #[Test]
    public function an_amazon_link_is_never_fetched_and_resolves_through_what_we_know_of_the_asin(): void
    {
        [$owner, $list] = $this->list();
        $group = ProductGroup::factory()->create(['market' => Market::BeNl, 'identity_key' => self::EAN]);
        AmazonProduct::query()->create($this->amazonRow('B0TESTASIN', self::EAN));

        $this->actingAs($owner)->post('/be-nl/list-items', [
            'wishlist_id' => $list->id, 'source' => 'manual', 'url' => 'https://www.amazon.nl/dp/B0TESTASIN',
        ]);

        Http::assertNothingSent();
        $this->assertSame($group->id, WishlistItem::query()->sole()->group_id);
    }

    #[Test]
    public function an_amazon_link_we_know_nothing_about_stays_as_typed(): void
    {
        [$owner, $list] = $this->list();

        $this->actingAs($owner)->post('/be-nl/list-items', [
            'wishlist_id' => $list->id, 'source' => 'manual', 'title' => 'Book', 'url' => 'https://www.amazon.nl/dp/B0UNKNOWN1',
        ]);

        Http::assertNothingSent();

        $item = WishlistItem::query()->sole();
        $this->assertSame('skipped', $item->link_status);
        $this->assertSame('Book', $item->snapshot_title);
        $this->assertNull($item->snapshot_image_url, 'Nothing from Amazon is stored (invariant 6).');
    }

    #[Test]
    public function a_bol_link_is_answered_from_the_catalogue_not_the_page(): void
    {
        [$owner, $list] = $this->list();
        $group = ProductGroup::factory()->create(['market' => Market::BeNl]);
        $this->offer(Source::Bol, '9300000012345678', $group);

        $this->actingAs($owner)->post('/be-nl/list-items', [
            'wishlist_id' => $list->id, 'source' => 'manual',
            'url' => 'https://www.bol.com/be/nl/p/lego-technic-ferrari/9300000012345678/?bltgh=abc',
        ]);

        Http::assertNothingSent();
        $this->assertSame($group->id, WishlistItem::query()->sole()->group_id);
    }

    #[Test]
    public function a_feed_shops_link_is_found_by_its_deep_link(): void
    {
        [$owner, $list] = $this->list();
        $group = ProductGroup::factory()->create(['market' => Market::BeNl]);
        $merchant = Merchant::query()->create([
            'source' => Source::Awin->value, 'external_id' => '1234', 'name' => 'Coolshop', 'domain' => 'coolshop.example',
        ]);
        $this->offer(Source::Awin, 'aw-1', $group, [
            'merchant_id' => $merchant->id,
            'merchant_deep_link' => 'https://www.coolshop.example/products/headphones-x?utm_source=awin',
        ]);

        $this->actingAs($owner)->post('/be-nl/list-items', [
            'wishlist_id' => $list->id, 'source' => 'manual',
            'url' => 'https://coolshop.example/products/headphones-x?ref=mail',
        ]);

        Http::assertNothingSent();
        $this->assertSame($group->id, WishlistItem::query()->sole()->group_id);
    }

    #[Test]
    public function the_in_list_search_answers_a_pasted_link_without_fetching_it(): void
    {
        [$owner] = $this->list();
        $group = ProductGroup::factory()->create(['market' => Market::BeNl]);
        $this->offer(Source::Bol, '9300000099999999', $group);

        $this->actingAs($owner)
            ->getJson('/be-nl/list-search?q='.urlencode('https://www.bol.com/be/nl/p/x/9300000099999999/'))
            ->assertOk()
            ->assertJsonPath('groups.0.id', $group->id);

        $this->actingAs($owner)
            ->getJson('/be-nl/list-search?q='.urlencode('https://small-shop.example/mug'))
            ->assertOk()
            ->assertJsonPath('groups', [])
            ->assertJsonPath('link.host', 'small-shop.example');

        $this->actingAs($owner)
            ->getJson('/be-nl/list-search?q='.urlencode('http://small-shop.example/mug'))
            ->assertJsonPath('linkRefused', true)
            ->assertJsonPath('link', null);

        Http::assertNothingSent();
    }

    #[Test]
    public function changing_the_link_looks_the_new_one_up(): void
    {
        Queue::fake();
        [$owner, $list] = $this->list();

        $item = WishlistItem::query()->create([
            'wishlist_id' => $list->id, 'source' => Source::Manual, 'snapshot_title' => 'Mug', 'accepted_at' => now(),
        ]);

        $this->actingAs($owner)->patch("/be-nl/list-items/{$item->id}", ['url' => 'https://small-shop.example/mug']);

        $this->assertSame('pending', $item->fresh()->link_status);
        Queue::assertPushed(ReadItemLink::class);
    }

    #[Test]
    public function the_list_page_says_an_item_is_being_read(): void
    {
        [$owner, $list] = $this->list();

        WishlistItem::query()->create([
            'wishlist_id' => $list->id, 'source' => Source::Manual, 'snapshot_title' => 'shop.example',
            'snapshot_url' => 'https://shop.example/x', 'link_status' => 'pending', 'accepted_at' => now(),
        ]);

        $this->actingAs($owner)->get("/be-nl/lists/{$list->id}")
            ->assertInertia(fn ($page) => $page->where('items.0.reading', true));
    }

    /** @param  array<string, mixed>  $product */
    private function productPage(array $product): string
    {
        $product = ['@context' => 'https://schema.org', '@type' => 'Product'] + $product;

        if (isset($product['offers'])) {
            $product['offers'] = ['@type' => 'Offer'] + $product['offers'];
        }

        return '<html><head><title>Shop</title><script type="application/ld+json">'
            .json_encode($product, JSON_UNESCAPED_SLASHES).'</script></head></html>';
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(20, 10);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** @param  array<string, mixed>  $extra */
    private function offer(Source $source, string $externalId, ProductGroup $group, array $extra = []): void
    {
        DB::table('products')->insert($extra + [
            'source' => $source->value,
            'external_id' => $externalId,
            'market' => $group->market->value,
            'group_id' => $group->id,
            'title' => $group->title,
            'affiliate_url' => 'https://track.example/'.$externalId,
            'price' => 1999,
            'status' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function amazonRow(string $asin, string $identityKey): array
    {
        return ['asin' => $asin, 'identity_key' => $identityKey];
    }

    /** @return array{0: User, 1: Wishlist} */
    private function list(): array
    {
        $owner = User::factory()->create();

        $list = Wishlist::create([
            'owner_user_id' => $owner->id,
            'title' => 'Mine',
            'market' => Market::BeNl,
            'kind' => ListKind::Mine,
            'visibility' => 'private',
        ]);

        return [$owner, $list];
    }
}

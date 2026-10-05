<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Images\ImageProxy;
use App\Services\Images\ImageStore;
use App\Services\Images\ProxiedImages;
use App\Services\Images\SourceVariants;
use App\Services\PageReading\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `/img/{width}/{signature}/{source}`: our resized WebP copy of a shop's
 * picture, and the ways it must refuse to be anything more than that
 * (docs/features/image-proxy.md).
 *
 * DNS is answered by the test and every outbound request is faked, so nothing
 * here touches the network.
 */
class ImageProxyTest extends TestCase
{
    use RefreshDatabase;

    private const BOL = 'https://media.s-bol.com/Yn6yADAlR9RO/3MRRqM/250x200.jpg';

    protected function setUp(): void
    {
        parent::setUp();

        config(['giftcoves.image_proxy.enabled' => true]);
        Storage::fake(ImageStore::DISK);
        Http::preventStrayRequests();

        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function addresses(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
    }

    #[Test]
    public function a_listed_picture_is_resized_to_webp_and_cached_for_a_year(): void
    {
        Http::fake([self::BOL => Http::response($this->jpeg(800, 600), 200, ['Content-Type' => 'image/jpeg'])]);

        $response = $this->get($this->address(self::BOL, 320))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
        $this->assertSame([], $response->headers->getCookies(), 'a picture starts no session and sets no cookie');

        $bytes = (string) file_get_contents($response->baseResponse->getFile()->getPathname());
        $this->assertSame('RIFF', substr($bytes, 0, 4));
        $this->assertSame('WEBP', substr($bytes, 8, 4));

        // Shrunk so the shorter side fills a 320 slot: 800x600 becomes 427x320.
        [$width, $height] = getimagesizefromstring($bytes);
        $this->assertSame(320, $height);
        $this->assertSame(427, $width);
    }

    /**
     * Both kinds of transparent PNG: full colour with an alpha channel, and a
     * palette whose transparency is one entry, which is what Coolblue serves.
     *
     * @return array<string, array{bool}>
     */
    public static function transparentPngs(): array
    {
        return ['full colour with alpha' => [false], 'palette, as Coolblue serves' => [true]];
    }

    #[Test]
    #[DataProvider('transparentPngs')]
    public function a_transparent_picture_comes_out_on_white_not_black(bool $palette): void
    {
        /*
         * Found 2026-10-05: Coolblue's product photos are PNGs with a
         * transparent ground, and the resize dropped the alpha, so every
         * AEG appliance sat on black in the search results. Shrunk, as the
         * real ones are, because the resize is where the alpha went. The
         * first fix covered full-colour PNGs only; Coolblue's are palette
         * PNGs, and they stayed black until the palette case was tested too.
         */
        if ($palette) {
            $png = imagecreate(800, 800);
            $clear = (int) imagecolorallocate($png, 71, 112, 76);
            imagecolortransparent($png, $clear);
            imagefill($png, 0, 0, $clear);
        } else {
            $png = imagecreatetruecolor(800, 800);
            imagealphablending($png, false);
            imagesavealpha($png, true);
            imagefill($png, 0, 0, (int) imagecolorallocatealpha($png, 0, 0, 0, 127));
        }
        imagefilledrectangle($png, 300, 300, 500, 500, (int) imagecolorallocate($png, 200, 30, 30));
        ob_start();
        imagepng($png);
        $bytes = (string) ob_get_clean();

        Http::fake([self::BOL => Http::response($bytes, 200, ['Content-Type' => 'image/png'])]);

        $response = $this->get($this->address(self::BOL, 320))->assertOk();
        $copy = imagecreatefromwebp($response->baseResponse->getFile()->getPathname());

        // A corner, which was transparent: white, give or take the encoder.
        $corner = imagecolorsforindex($copy, imagecolorat($copy, 2, 2));
        $this->assertGreaterThan(245, $corner['red']);
        $this->assertGreaterThan(245, $corner['green']);
        $this->assertGreaterThan(245, $corner['blue']);

        // And the product itself is untouched: still red in the middle.
        $middle = imagecolorsforindex($copy, imagecolorat($copy, 160, 160));
        $this->assertGreaterThan(150, $middle['red']);
        $this->assertLessThan(80, $middle['green']);
    }

    #[Test]
    public function the_second_view_is_served_from_disk_without_asking_the_shop(): void
    {
        Http::fake([self::BOL => Http::response($this->jpeg(400, 400), 200, ['Content-Type' => 'image/jpeg'])]);

        $this->get($this->address(self::BOL, 160))->assertOk();
        $this->get($this->address(self::BOL, 160))->assertOk();

        Http::assertSentCount(1);
        Storage::disk(ImageStore::DISK)->assertExists(ProxiedImages::path(self::BOL, 160));
    }

    #[Test]
    public function a_small_picture_is_never_enlarged(): void
    {
        Http::fake([self::BOL => Http::response($this->jpeg(250, 200), 200, ['Content-Type' => 'image/jpeg'])]);

        $response = $this->get($this->address(self::BOL, 960))->assertOk();

        [$width] = getimagesize($response->baseResponse->getFile()->getPathname());
        $this->assertSame(250, $width);
    }

    #[Test]
    public function an_unsigned_or_tampered_address_is_refused_without_a_fetch(): void
    {
        Http::fake();
        $encoded = ImageProxy::encode(self::BOL);
        $other = ImageProxy::encode('https://media.s-bol.com/other/250x200.jpg');
        [$signature] = explode('/', (string) app(ImageProxy::class)->token(self::BOL));

        $this->get('/img/320/'.str_repeat('0', 32).'/'.$encoded)->assertNotFound();
        $this->get("/img/320/{$signature}/{$other}")->assertNotFound();
        $this->get('/img/320/'.$encoded)->assertNotFound();

        Http::assertNothingSent();
    }

    #[Test]
    public function a_width_off_the_list_is_refused(): void
    {
        Http::fake();

        $this->get($this->address(self::BOL, 321))->assertNotFound();
        $this->get($this->address(self::BOL, 5000))->assertNotFound();

        Http::assertNothingSent();
    }

    #[Test]
    public function a_host_off_the_list_is_neither_signed_nor_served(): void
    {
        Http::fake();
        $url = 'https://evil.example/picture.jpg';

        $this->assertNull(app(ImageProxy::class)->token($url));

        // Signed while the host was listed, then taken off the list: refused.
        config(['giftcoves.image_proxy.hosts' => ['evil.example']]);
        $address = $this->address($url, 320);
        config(['giftcoves.image_proxy.hosts' => ['media.s-bol.com']]);

        $this->get($address)->assertNotFound();
        Http::assertNothingSent();
    }

    #[Test]
    public function http_is_never_signed(): void
    {
        $this->assertNull(app(ImageProxy::class)->token('http://media.s-bol.com/x/250x200.jpg'));
        $this->assertNull(app(ImageProxy::class)->token('https://media.s-bol.com:8443/x/250x200.jpg'));
    }

    /** Invariant 6: an Amazon picture is never copied, cached or served, whatever the list says. */
    #[Test]
    public function amazon_is_refused_even_when_listed(): void
    {
        Http::fake();
        config(['giftcoves.image_proxy.hosts' => ['media-amazon.com', 'm.media-amazon.com', 'amazon.nl', 'ssl-images-amazon.com']]);

        foreach ([
            'https://m.media-amazon.com/images/I/71e6EQRCsL._AC_SY879_.jpg',
            'https://images-eu.ssl-images-amazon.com/images/I/71e6EQRCsL.jpg',
            'https://www.amazon.nl/picture.jpg',
        ] as $url) {
            $this->assertNull(app(ImageProxy::class)->token($url), $url);

            $signature = substr(hash_hmac('sha256', $url, hash_hmac('sha256', 'giftcoves-image-proxy-v1', (string) config('app.key'))), 0, 32);
            $this->get("/img/320/{$signature}/".ImageProxy::encode($url))->assertNotFound();
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function a_redirect_to_amazon_is_not_kept(): void
    {
        Http::fake([
            self::BOL => Http::response('', 302, ['Location' => 'https://m.media-amazon.com/images/I/x.jpg']),
            'https://m.media-amazon.com/*' => Http::response($this->jpeg(300, 300), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $this->get($this->address(self::BOL, 320))->assertRedirect(self::BOL);

        Storage::disk(ImageStore::DISK)->assertMissing(ProxiedImages::path(self::BOL, 320));
    }

    #[Test]
    public function a_picture_that_cannot_be_had_sends_the_browser_to_the_original_and_is_not_asked_again(): void
    {
        Http::fake([self::BOL => Http::response('gone', 404, ['Content-Type' => 'text/plain'])]);

        $this->get($this->address(self::BOL, 320))
            ->assertRedirect(self::BOL)
            ->assertHeader('Cache-Control', 'max-age=300, public');
        $this->get($this->address(self::BOL, 480))->assertRedirect(self::BOL);

        Http::assertSentCount(1);
    }

    #[Test]
    public function something_that_is_not_a_picture_is_not_served(): void
    {
        Http::fake([self::BOL => Http::response('<html>hi</html>', 200, ['Content-Type' => 'image/jpeg'])]);

        $this->get($this->address(self::BOL, 320))->assertRedirect(self::BOL);
        Storage::disk(ImageStore::DISK)->assertMissing(ProxiedImages::path(self::BOL, 320));
    }

    #[Test]
    public function a_visitor_over_the_fetch_limit_gets_the_original_and_stored_copies_still_serve(): void
    {
        config(['giftcoves.image_proxy.fetches_per_minute' => 1]);
        $second = 'https://media.s-bol.com/second/250x200.jpg';
        Http::fake(['https://media.s-bol.com/*' => Http::response($this->jpeg(300, 300), 200, ['Content-Type' => 'image/jpeg'])]);

        $this->get($this->address(self::BOL, 320))->assertOk();
        $this->get($this->address($second, 320))->assertRedirect($second);
        $this->get($this->address(self::BOL, 320))->assertOk();

        Http::assertSentCount(1);
    }

    #[Test]
    public function a_larger_ebay_rendition_is_asked_for_first(): void
    {
        $url = 'https://i.ebayimg.com/images/g/abc/s-l225.jpg';
        Http::fake([
            'https://i.ebayimg.com/images/g/abc/s-l500.jpg' => Http::response($this->jpeg(500, 500), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $this->get($this->address($url, 480))->assertOk();

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://i.ebayimg.com/images/g/abc/s-l500.jpg');
        $this->assertSame([$url], SourceVariants::for($url, 160), 'never a smaller rendition than the feed had');
        $this->assertSame([self::BOL], SourceVariants::for(self::BOL, 960), 'bol has no size in its URL to change');
    }

    #[Test]
    public function the_product_page_hands_out_a_token_only_while_the_proxy_is_on(): void
    {
        $group = ProductGroup::factory()->create(['market' => Market::BeNl, 'image_url' => self::BOL]);
        $this->offer($group);

        $this->get("/be-nl/p/{$group->id}/{$group->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('product.image', self::BOL)
                ->where('product.imageToken', app(ImageProxy::class)->token(self::BOL)));

        $address = $this->address(self::BOL, 640);
        config(['giftcoves.image_proxy.enabled' => false]);

        // An address handed out before the switch leads to the shop's picture.
        Http::fake();
        $this->get($address)->assertRedirect(self::BOL);
        Http::assertNothingSent();

        $this->get("/be-nl/p/{$group->id}/{$group->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('product.image', self::BOL)
                ->where('product.imageToken', null));
    }

    #[Test]
    public function the_prune_removes_old_copies_only(): void
    {
        $disk = Storage::disk(ImageStore::DISK);
        $disk->put('proxy/320/aa/old.webp', 'x');
        $disk->put('proxy/320/bb/new.webp', 'x');
        $disk->put('items/00000000-0000-0000-0000-000000000000.webp', 'x');
        touch($disk->path('proxy/320/aa/old.webp'), now()->subDays(40)->getTimestamp());
        touch($disk->path('items/00000000-0000-0000-0000-000000000000.webp'), now()->subDays(400)->getTimestamp());

        $this->artisan('bc:prune-image-cache')->assertSuccessful();

        $disk->assertMissing('proxy/320/aa/old.webp');
        $disk->assertExists('proxy/320/bb/new.webp');
        $disk->assertExists('items/00000000-0000-0000-0000-000000000000.webp');
    }

    private function address(string $url, int $width): string
    {
        $token = app(ImageProxy::class)->token($url);
        $this->assertNotNull($token, "{$url} should be signable");

        return "/img/{$width}/{$token}";
    }

    private function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 120, 40));
        ob_start();
        imagejpeg($image, null, 85);

        return (string) ob_get_clean();
    }

    private function offer(ProductGroup $group): Product
    {
        $merchant = Merchant::firstOrCreate(['source' => Source::Bol->value, 'external_id' => 'bol'], ['name' => 'bol']);

        return Product::create([
            'source' => Source::Bol,
            'market' => $group->market,
            'merchant_id' => $merchant->id,
            'group_id' => $group->id,
            'external_id' => 'x'.bin2hex(random_bytes(4)),
            'title' => $group->title,
            'price' => 12900,
            'currency' => 'EUR',
            'affiliate_url' => 'https://example.test/buy',
            'availability' => Availability::InStock,
            'status' => ProductStatus::Active,
            'identity_key' => $group->identity_key,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Services\Images\ImageStore;
use App\Services\PageReading\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `POST /api/editorial/images`: an article's picture, kept on our own server
 * (App\Services\Images\EditorialImages). DNS is answered by the test and every
 * outbound request is faked, so nothing here touches the network.
 */
class EditorialImagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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
    public function an_uploaded_picture_is_kept_as_webp_and_served_from_our_address(): void
    {
        $response = $this->withToken($this->key())
            ->post('/api/editorial/images', [
                'file' => UploadedFile::fake()->createWithContent('logo.png', $this->png(2400, 1200)),
                'alt' => 'The Amazon Prime logo',
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $path = (string) $response->json('data.path');
        $this->assertMatchesRegularExpression('#^/media/editorial/[0-9a-f]{40}\.webp$#', $path);

        // Shrunk to 1600 on the long side, and the token is ready to paste.
        $this->assertSame(1600, $response->json('data.width'));
        $this->assertSame(800, $response->json('data.height'));
        $this->assertSame('[[image:'.$response->json('data.url').'|The Amazon Prime logo]]', $response->json('data.token'));

        Storage::disk(ImageStore::DISK)->assertExists(substr($path, strlen('/media/')));

        $this->get($path)->assertOk()->assertHeader('Content-Type', 'image/webp');
    }

    #[Test]
    public function the_same_picture_twice_is_one_file_at_one_address(): void
    {
        // A retry after a timeout must not leave a second copy behind.
        $first = $this->upload($this->png(300, 200));
        $second = $this->upload($this->png(300, 200));

        $this->assertSame($first, $second);
        $this->assertCount(1, Storage::disk(ImageStore::DISK)->files('editorial'));
    }

    #[Test]
    public function a_picture_is_fetched_from_an_address(): void
    {
        Http::fake(['https://press.example.com/*' => Http::response($this->png(640, 480), 200, ['Content-Type' => 'image/png'])]);

        $this->withToken($this->key())
            ->postJson('/api/editorial/images', ['url' => 'https://press.example.com/banner.png', 'alt' => 'A banner'])
            ->assertCreated()
            ->assertJsonPath('data.width', 640);
    }

    #[Test]
    public function an_amazon_picture_is_refused_without_being_fetched(): void
    {
        // Invariant 6: nothing from Amazon is copied to this site.
        $this->withToken($this->key())
            ->postJson('/api/editorial/images', ['url' => 'https://m.media-amazon.com/images/I/logo.png'])
            ->assertStatus(422)
            ->assertJsonPath('errors.image.0', 'Amazon pictures are never copied to this site.');

        Http::assertNothingSent();
    }

    #[Test]
    public function an_svg_is_not_a_picture_here(): void
    {
        // An SVG is code that runs in the reader's browser; only re-encoded bitmaps are kept.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

        $this->withToken($this->key())
            ->post('/api/editorial/images', ['file' => UploadedFile::fake()->createWithContent('x.svg', $svg)], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertSame([], Storage::disk(ImageStore::DISK)->files('editorial'));
    }

    #[Test]
    public function a_reading_key_cannot_store_pictures(): void
    {
        $this->withToken($this->key([ApiToken::READ]))
            ->post('/api/editorial/images', ['file' => UploadedFile::fake()->createWithContent('a.png', $this->png(10, 10))], ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    #[Test]
    public function the_nightly_prune_leaves_article_pictures_alone(): void
    {
        // bc:prune-personal-data deletes every picture in items/ that no list item
        // refers to; an article picture is referred to by no list item at all.
        $path = substr($this->upload($this->png(50, 50)), strlen('/media/'));
        touch(Storage::disk(ImageStore::DISK)->path($path), now()->subDays(10)->getTimestamp());

        $this->artisan('bc:prune-personal-data')->assertSuccessful();

        Storage::disk(ImageStore::DISK)->assertExists($path);
    }

    private function upload(string $bytes): string
    {
        return (string) $this->withToken($this->key())
            ->post('/api/editorial/images', ['file' => UploadedFile::fake()->createWithContent('p.png', $bytes)], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.path');
    }

    /** @param  list<string>  $abilities */
    private function key(array $abilities = [ApiToken::READ, ApiToken::WRITE]): string
    {
        return ApiToken::issue('claude', $abilities)['token'];
    }

    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 40, 120, 200));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\Market;
use App\Enums\Source;
use App\Models\ProductGroup;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A photo of your own on something you typed.
 *
 * The rule these hold: a stored picture is ours, re-encoded, and carries
 * nothing the phone wrote into the file, least of all where it was taken.
 */
class ItemPhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
    }

    #[Test]
    public function a_phone_photo_is_stored_as_webp_without_its_location(): void
    {
        [$owner, $item] = $this->manualItem();

        $jpeg = $this->jpegWithGps();
        $this->assertStringContainsString('GPSSECRET', $jpeg, 'The fixture carries the metadata it claims to.');

        $this->actingAs($owner)->post("/be-nl/list-items/{$item->id}/photo", [
            'photo' => UploadedFile::fake()->createWithContent('holiday.jpg', $jpeg),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $path = (string) $item->fresh()->snapshot_image_url;
        $this->assertMatchesRegularExpression('#^/media/items/[0-9a-f-]{36}\.webp$#', $path);

        $stored = Storage::disk('media')->get(substr($path, strlen('/media/')));
        $this->assertStringStartsWith('RIFF', $stored, 'Re-encoded as WebP.');
        $this->assertStringNotContainsString('GPSSECRET', $stored);
    }

    #[Test]
    public function a_new_photo_replaces_the_old_file_and_removing_it_deletes_it(): void
    {
        [$owner, $item] = $this->manualItem();

        $this->actingAs($owner)->post("/be-nl/list-items/{$item->id}/photo", [
            'photo' => UploadedFile::fake()->image('a.png', 40, 40),
        ]);
        $first = substr((string) $item->fresh()->snapshot_image_url, strlen('/media/'));

        $this->actingAs($owner)->post("/be-nl/list-items/{$item->id}/photo", [
            'photo' => UploadedFile::fake()->image('b.png', 40, 40),
        ]);
        $second = substr((string) $item->fresh()->snapshot_image_url, strlen('/media/'));

        Storage::disk('media')->assertMissing($first);
        Storage::disk('media')->assertExists($second);

        $this->actingAs($owner)->delete("/be-nl/list-items/{$item->id}/photo");

        Storage::disk('media')->assertMissing($second);
        $this->assertNull($item->fresh()->snapshot_image_url);
    }

    #[Test]
    public function something_that_is_not_a_picture_is_refused(): void
    {
        [$owner, $item] = $this->manualItem();

        $this->actingAs($owner)->post("/be-nl/list-items/{$item->id}/photo", [
            'photo' => UploadedFile::fake()->createWithContent('x.jpg', '<svg onload="alert(1)"></svg>'),
        ])->assertSessionHasErrors('photo');

        $this->assertNull($item->fresh()->snapshot_image_url);
    }

    #[Test]
    public function a_catalogue_item_keeps_its_products_picture(): void
    {
        [$owner, $item] = $this->manualItem();
        $item->update(['source' => null, 'group_id' => ProductGroup::factory()->create()->id]);

        $this->actingAs($owner)->post("/be-nl/list-items/{$item->id}/photo", [
            'photo' => UploadedFile::fake()->image('a.png'),
        ])->assertStatus(422);
    }

    #[Test]
    public function somebody_else_cannot_put_a_photo_on_your_item(): void
    {
        [, $item] = $this->manualItem();

        $this->actingAs(User::factory()->create())->post("/be-nl/list-items/{$item->id}/photo", [
            'photo' => UploadedFile::fake()->image('a.png'),
        ])->assertNotFound();
    }

    #[Test]
    public function deleting_the_item_deletes_its_photo_and_the_sweep_catches_cascades(): void
    {
        [$owner, $item] = $this->manualItem();

        $this->actingAs($owner)->post("/be-nl/list-items/{$item->id}/photo", ['photo' => UploadedFile::fake()->image('a.png')]);
        $file = substr((string) $item->fresh()->snapshot_image_url, strlen('/media/'));

        $item->fresh()->delete();
        Storage::disk('media')->assertMissing($file);

        // A list deleted as a whole removes its items in the database, where
        // no model event fires. The nightly sweep is what catches those.
        Storage::disk('media')->put('items/00000000-0000-0000-0000-000000000000.webp', 'x');
        touch(Storage::disk('media')->path('items/00000000-0000-0000-0000-000000000000.webp'), now()->subDays(2)->getTimestamp());
        Storage::disk('media')->put('items/11111111-1111-1111-1111-111111111111.webp', 'fresh');

        $this->artisan('bc:prune-personal-data')->assertSuccessful();

        Storage::disk('media')->assertMissing('items/00000000-0000-0000-0000-000000000000.webp');
        // Under a day old: it may belong to an item being saved right now.
        Storage::disk('media')->assertExists('items/11111111-1111-1111-1111-111111111111.webp');
    }

    #[Test]
    public function a_stored_picture_is_served_as_webp_and_nothing_else(): void
    {
        Storage::disk('media')->put('items/22222222-2222-2222-2222-222222222222.webp', 'RIFF....WEBP');

        $this->get('/media/items/22222222-2222-2222-2222-222222222222.webp')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->get('/media/items/../../.env')->assertNotFound();
        $this->get('/media/items/33333333-3333-3333-3333-333333333333.webp')->assertNotFound();
    }

    /** A real JPEG with an APP1 segment spliced in after SOI, holding a marker string. */
    private function jpegWithGps(): string
    {
        $image = imagecreatetruecolor(30, 20);
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();

        $payload = "Exif\0\0GPSSECRET-51.2194N-4.4025E";
        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }

    #[Test]
    public function an_offline_item_can_be_added_with_its_photo_in_one_go(): void
    {
        [$owner, $item] = $this->manualItem();
        $list = $item->wishlist;

        $this->actingAs($owner)->post('/be-nl/list-items', [
            'wishlist_id' => $list->id,
            'source' => 'manual',
            'title' => 'Bowl from the pottery market',
            'photo' => UploadedFile::fake()->image('bowl.png', 40, 40),
        ])->assertSessionHasNoErrors();

        $added = WishlistItem::query()->where('snapshot_title', 'Bowl from the pottery market')->sole();

        $this->assertMatchesRegularExpression('#^/media/items/[0-9a-f-]{36}\.webp$#', (string) $added->snapshot_image_url);
        Storage::disk('media')->assertExists(substr((string) $added->snapshot_image_url, strlen('/media/')));
    }

    #[Test]
    public function a_bad_file_refuses_the_add_rather_than_saving_it_without_a_photo(): void
    {
        [$owner, $item] = $this->manualItem();

        $this->actingAs($owner)->post('/be-nl/list-items', [
            'wishlist_id' => $item->wishlist_id,
            'source' => 'manual',
            'title' => 'Mystery',
            'photo' => UploadedFile::fake()->createWithContent('x.jpg', '<svg onload="alert(1)"></svg>'),
        ])->assertSessionHasErrors('photo');

        $this->assertFalse(WishlistItem::query()->where('snapshot_title', 'Mystery')->exists());
    }

    /** @return array{0: User, 1: WishlistItem} */
    private function manualItem(): array
    {
        $owner = User::factory()->create();

        $list = Wishlist::create([
            'owner_user_id' => $owner->id,
            'title' => 'Mine',
            'market' => Market::BeNl,
            'kind' => ListKind::Mine,
            'visibility' => 'private',
        ]);

        $item = WishlistItem::query()->create([
            'wishlist_id' => $list->id,
            'source' => Source::Manual,
            'snapshot_title' => 'The green mug',
            'accepted_at' => now(),
        ]);

        return [$owner, $item];
    }
}

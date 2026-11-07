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
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Correcting an item you typed yourself.
 *
 * The whole feature is one distinction, and these tests are that distinction:
 * on a hand-written item the snapshot columns *are* the item, and on a
 * catalogue item they are a record of what a feed said — which is what "you
 * saved it at €329, it is €279 now" is measured against.
 */
class ManualItemTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_hand_written_item_can_be_corrected(): void
    {
        /*
         * Before this, a typo in something you typed was permanent: the only
         * fix was to delete the row and type it again, which loses the note,
         * the position and any claim already made on it.
         */
        [$owner, $list] = $this->list();

        $item = WishlistItem::create([
            'wishlist_id' => $list->id,
            'source' => Source::Manual,
            'snapshot_title' => 'Baking tin',
            'snapshot_price' => 1500,
            'snapshot_url' => 'https://shop.example/tin',
            'note' => 'the springform one',
            'accepted_at' => now(),
        ]);

        $this->actingAs($owner)->patch("/be-nl/list-items/{$item->id}", [
            'title' => '  Springform baking tin  ',
            'price' => 1750,
            'url' => 'https://shop.example/springform',
        ])->assertRedirect();

        $item->refresh();

        $this->assertSame('Springform baking tin', $item->snapshot_title, 'Trimmed on the way in.');
        $this->assertSame(1750, $item->snapshot_price);
        $this->assertSame('https://shop.example/springform', $item->snapshot_url);
        $this->assertSame('the springform one', $item->note, 'Untouched by a change that did not name it.');
    }

    #[Test]
    public function the_price_and_the_link_can_be_cleared(): void
    {
        // "Free" and "no price" are different things, and a list holds plenty
        // of the second. Same for a link nobody has found yet.
        [$owner, $list] = $this->list();

        $item = WishlistItem::create([
            'wishlist_id' => $list->id,
            'source' => Source::Manual,
            'snapshot_title' => 'Something',
            'snapshot_price' => 1500,
            'snapshot_url' => 'https://shop.example/x',
            'accepted_at' => now(),
        ]);

        $this->actingAs($owner)->patch("/be-nl/list-items/{$item->id}", [
            'price' => null,
            'url' => null,
        ])->assertRedirect();

        $item->refresh();

        $this->assertNull($item->snapshot_price);
        $this->assertNull($item->snapshot_url);
    }

    #[Test]
    public function a_catalogue_item_keeps_the_snapshot_the_feed_gave_it(): void
    {
        /*
         * The rule this feature is built around. Those columns are what a price
         * comparison is drawn from — rewriting them turns a history into a
         * free-text field.
         *
         * Dropped rather than refused: the form does not offer the fields, so a
         * request carrying them was not sent by the page, and a validation error
         * would only describe a control that does not exist.
         */
        [$owner, $list] = $this->list();

        $group = ProductGroup::create([
            'market' => Market::BeNl,
            'identity_key' => 'k'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => 'Sony WH-1000XM5',
            'slug' => 'sony-wh-1000xm5',
            'min_price' => 32999,
            'merchant_count' => 1,
            'in_stock' => true,
        ]);

        $item = WishlistItem::create([
            'wishlist_id' => $list->id,
            'group_id' => $group->id,
            'source' => Source::Awin,
            'snapshot_title' => 'Sony WH-1000XM5',
            'snapshot_price' => 32999,
            'accepted_at' => now(),
        ]);

        $this->actingAs($owner)->patch("/be-nl/list-items/{$item->id}", [
            'title' => 'Free headphones',
            'price' => 1,
            'note' => 'still mine to write',
        ])->assertRedirect();

        $item->refresh();

        $this->assertSame('Sony WH-1000XM5', $item->snapshot_title);
        $this->assertSame(32999, $item->snapshot_price);
        $this->assertSame('still mine to write', $item->note, 'The note is yours on any item.');

        // The name, though, may be yours (owner, 2026-11-07), kept apart from
        // the record above and shown wherever the item is.
        $this->assertSame('Free headphones', $item->custom_title);
        $this->assertSame('Free headphones', $item->displayTitle());

        // Blank, or the product's own name, goes back to the product's.
        $this->actingAs($owner)->patch("/be-nl/list-items/{$item->id}", ['title' => ''])->assertRedirect();
        $this->assertNull($item->refresh()->custom_title);
        $this->assertSame('Sony WH-1000XM5', $item->displayTitle());

        $this->actingAs($owner)->patch("/be-nl/list-items/{$item->id}", ['title' => 'For the train'])->assertRedirect();
        $this->actingAs($owner)->patch("/be-nl/list-items/{$item->id}", ['title' => 'Sony WH-1000XM5'])->assertRedirect();
        $this->assertNull($item->refresh()->custom_title);

        // The note goes with it, from the same form: one press adds a product
        // without the step that used to ask for one.
        $this->actingAs($owner)->patch("/be-nl/list-items/{$item->id}", ['title' => 'Sony WH-1000XM5', 'note' => 'in black'])->assertRedirect();
        $this->assertSame('in black', $item->refresh()->note);
    }

    #[Test]
    public function an_offline_item_can_be_saved_with_its_picture(): void
    {
        // The gift-card guides (2026-11-07): a card saved from an article keeps
        // the article's picture, its name and the shop's address. https only.
        [$owner, $list] = $this->list();

        $this->actingAs($owner)->postJson('/be-nl/list-items', [
            'wishlist_id' => $list->id,
            'source' => 'manual',
            'title' => 'Bongo-cadeaubon',
            'url' => 'https://www.bongo.be/nl/bon',
            'image_url' => 'https://media.smartbox.com/kaart.jpg',
        ])->assertSuccessful();

        $item = WishlistItem::query()->where('wishlist_id', $list->id)->latest('id')->firstOrFail();
        $this->assertSame('Bongo-cadeaubon', $item->snapshot_title);
        $this->assertSame('https://www.bongo.be/nl/bon', $item->snapshot_url);
        $this->assertSame('https://media.smartbox.com/kaart.jpg', $item->snapshot_image_url);

        // Not over plain http.
        $this->actingAs($owner)->postJson('/be-nl/list-items', [
            'wishlist_id' => $list->id,
            'source' => 'manual',
            'title' => 'Iets anders',
            'image_url' => 'http://example.com/x.jpg',
        ])->assertSuccessful();
        $this->assertNull(WishlistItem::query()->where('snapshot_title', 'Iets anders')->value('snapshot_image_url'));
    }

    #[Test]
    public function a_dangerous_link_is_refused(): void
    {
        // Affiliate and hand-typed URLs are hostile input, invariant #5. The
        // rule runs on the way in and the model checks again on the way out.
        [$owner, $list] = $this->list();

        $item = WishlistItem::create([
            'wishlist_id' => $list->id,
            'source' => Source::Manual,
            'snapshot_title' => 'Something',
            'accepted_at' => now(),
        ]);

        $this->actingAs($owner)
            ->patch("/be-nl/list-items/{$item->id}", ['url' => 'javascript:alert(1)'])
            ->assertSessionHasErrors('url');

        $this->assertNull($item->fresh()->snapshot_url);
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

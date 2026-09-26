<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\Market;
use App\Enums\Source;
use App\Jobs\LinkBarcodeItems;
use App\Models\ProductGroup;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Wishlist\ItemLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Something scanned before any shop sold it, joined to its product once one
 * does, in the list's own market only (invariant 2).
 */
class LinkBarcodeItemsTest extends TestCase
{
    use RefreshDatabase;

    private const EAN = '4006381333931';

    #[Test]
    public function a_scanned_barcode_is_kept_on_the_item(): void
    {
        [$owner, $list] = $this->list(Market::BeNl);

        $this->actingAs($owner)->post('/be-nl/list-items', [
            'wishlist_id' => $list->id,
            'source' => 'manual',
            'title' => 'The tea from the market stall',
            // As a scanner may hand it over, with spaces.
            'gtin' => '4 006381 333931',
        ]);

        $this->assertSame(self::EAN, WishlistItem::query()->sole()->gtin);
    }

    #[Test]
    public function an_invalid_barcode_is_dropped_but_the_item_is_saved(): void
    {
        [$owner, $list] = $this->list(Market::BeNl);

        $this->actingAs($owner)->post('/be-nl/list-items', [
            'wishlist_id' => $list->id, 'source' => 'manual', 'title' => 'Tea', 'gtin' => '1234567890123',
        ]);

        $item = WishlistItem::query()->sole();
        $this->assertSame('Tea', $item->snapshot_title);
        $this->assertNull($item->gtin);
    }

    #[Test]
    public function the_item_becomes_the_product_once_a_shop_in_its_market_sells_it(): void
    {
        [, $list] = $this->list(Market::BeNl);
        $item = $this->item($list);

        // The same barcode in another market is a different offer set.
        ProductGroup::factory()->create(['market' => Market::NlNl, 'identity_key' => self::EAN]);

        (new LinkBarcodeItems)->handle(app(ItemLinker::class));
        $this->assertNull($item->fresh()->group_id, 'Not joined to another market\'s product.');

        $group = ProductGroup::factory()->create(['market' => Market::BeNl, 'identity_key' => self::EAN]);

        (new LinkBarcodeItems)->handle(app(ItemLinker::class));

        $item->refresh();
        $this->assertSame($group->id, $item->group_id);
        $this->assertSame('Tea from the stall', $item->snapshot_title, 'The person\'s own words stay.');
        $this->assertFalse($item->isManual());
    }

    private function item(Wishlist $list): WishlistItem
    {
        return WishlistItem::query()->create([
            'wishlist_id' => $list->id,
            'source' => Source::Manual,
            'snapshot_title' => 'Tea from the stall',
            'gtin' => self::EAN,
            'accepted_at' => now(),
        ]);
    }

    /** @return array{0: User, 1: Wishlist} */
    private function list(Market $market): array
    {
        $owner = User::factory()->create();

        return [$owner, Wishlist::create([
            'owner_user_id' => $owner->id,
            'title' => 'Mine',
            'market' => $market,
            'kind' => ListKind::Mine,
            'visibility' => 'private',
        ])];
    }
}

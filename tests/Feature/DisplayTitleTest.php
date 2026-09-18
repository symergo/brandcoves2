<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Jobs\GroupProducts;
use App\Models\ApiToken;
use App\Models\DailyPick;
use App\Models\DailyPickSet;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Catalogue\ProductTitle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The title a visitor reads is not the title the feed sent.
 *
 * `product_groups.display_title` is hand-written and sits beside the feed's
 * `title`, which search, grouping and the slug keep depending on. These pin
 * the two halves of that split: the visitor-facing surfaces prefer the
 * written title, and the machinery underneath never sees it.
 */
class DisplayTitleTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::create([
            'source' => Source::Awin->value,
            'external_id' => 'shop',
            'name' => 'Shop',
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function group(string $title, ?string $brand = null, array $extra = []): ProductGroup
    {
        $group = ProductGroup::create([
            'market' => Market::BeNl,
            'identity_key' => 'k'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => $title,
            'slug' => 'p-'.bin2hex(random_bytes(3)),
            'brand' => $brand,
            'category' => 'Audio',
            'image_url' => 'https://img.test/x.jpg',
            'min_price' => 4900,
            'merchant_count' => 1,
            'in_stock' => true,
            'giftable' => true,
            'worth_showing' => true,
            ...$extra,
        ]);

        Product::create([
            'source' => Source::Awin,
            'market' => Market::BeNl,
            'merchant_id' => $this->merchant->id,
            'group_id' => $group->id,
            'external_id' => 'e'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => $title,
            'brand' => $brand,
            'merchant_category' => 'Audio',
            'price' => 4900,
            'currency' => 'EUR',
            'affiliate_url' => 'https://example.test/buy',
            'availability' => Availability::InStock,
            'status' => ProductStatus::Active,
            'identity_key' => $group->identity_key,
        ]);

        return $group;
    }

    #[Test]
    public function the_display_title_is_the_cleaned_feed_title_until_somebody_writes_one(): void
    {
        $group = $this->group('HYPER X ORIGINS 2 PRO 65 AZERTY FR', 'HyperX');

        // Null means "not written yet", and the mechanical cleaner answers:
        // the shouting undone on a card, the brand added where it stands alone.
        $this->assertSame('Hyper X Origins 2 Pro 65 Azerty Fr', $group->displayTitle());
        $this->assertSame(ProductTitle::heading($group), $group->heading());
        $this->assertStringStartsWith('HyperX ', $group->heading());

        $group->update(['display_title' => 'HyperX Origins 2 Pro: compact gaming keyboard']);

        $this->assertSame('HyperX Origins 2 Pro: compact gaming keyboard', $group->fresh()->displayTitle());
        $this->assertSame('HyperX Origins 2 Pro: compact gaming keyboard', $group->fresh()->heading());
    }

    #[Test]
    public function a_regrouping_run_leaves_the_written_title_alone(): void
    {
        /*
         * The grouper rewrites `title` from the best offer on every run. That
         * is why the written title is a sibling column: a run that took it
         * back would undo an editor's work twice a day.
         */
        $group = $this->group('Koffiemolen handmatig MD-4400', 'Hario');
        $group->update(['display_title' => 'Hario handmolen: verse koffie, elke ochtend']);

        GroupProducts::dispatchSync(Market::BeNl);

        $group->refresh();

        $this->assertSame('Koffiemolen handmatig MD-4400', $group->title);
        $this->assertSame('Hario handmolen: verse koffie, elke ochtend', $group->display_title);
    }

    #[Test]
    public function search_shows_the_written_title_and_still_matches_the_feed_title(): void
    {
        // The feed title carries the model code people search for; the
        // written title carries none of it. Search has to find the product on
        // the code and show it under the written title.
        $group = $this->group('Koffiemolen handmatig XQ-9 keramisch', 'Hario');
        $group->update(['display_title' => 'Hario handmolen voor verse koffie']);

        $this->get('/be-nl/search?q=xq-9')
            ->assertOk()
            ->assertSee('Hario handmolen voor verse koffie', false);
    }

    #[Test]
    public function search_finds_a_product_on_its_written_title_too(): void
    {
        // Owner's call: a visitor who reads the written title on a card and
        // types it into the box must find the product, though the feed title
        // shares no word with it. Stemmed, so the plural finds it, and
        // trigram, so a typo does.
        $group = $this->group('KOFFIEMOLEN HANDMATIG MD-4400', 'Hario');
        $group->update(['display_title' => 'Hario handmolen voor verse koffie']);
        $this->group('Waterkoker 1,7 liter', 'Bosch');

        foreach (['handmolen', 'handmolens', 'handmoln'] as $term) {
            $props = $this->get('/be-nl/search?q='.$term)->assertOk()->viewData('page')['props'];
            $found = array_column($props['results']['items'], 'id');

            $this->assertContains($group->id, $found, "'{$term}' did not find the product on its written title");
        }
    }

    #[Test]
    public function todays_cove_shows_the_written_title(): void
    {
        $group = $this->group('PETCUBE PET MONITORING CAMERA', 'Petcube');
        $group->update(['display_title' => 'Petcube camera: kijk mee met je huisdier']);

        $edition = DailyPickSet::create([
            'market' => Market::BeNl,
            'kind' => CoveKind::Daily,
            'slug' => now()->toDateString(),
            'theme_title' => 'Programmeursdag',
            'theme_slug' => 'programmeursdag',
            'status' => 'published',
            'published_at' => now()->subHour(),
            'drop_date' => now()->toDateString(),
        ]);
        DailyPick::create(['set_id' => $edition->id, 'group_id' => $group->id, 'rank' => 1, 'slug' => 'petcube']);

        $props = $this->get('/be-nl')->assertOk()->viewData('page')['props'];

        $this->assertSame('Petcube camera: kijk mee met je huisdier', $props['today']['finds'][0]['title']);
    }

    #[Test]
    public function the_surprise_band_shows_the_written_title(): void
    {
        $group = $this->group('KENSINGTON VERIMARK NFC+SECURITY KEY', 'Kensington', ['surprise_score' => 90]);
        $group->update(['display_title' => 'Kensington VeriMark: een sleutel voor je wachtwoorden']);

        $props = $this->get('/be-nl/surprise')->assertOk()->viewData('page')['props'];

        $this->assertSame('Kensington VeriMark: een sleutel voor je wachtwoorden', $props['finds'][0]['title']);
    }

    #[Test]
    public function the_product_page_heading_is_the_written_title(): void
    {
        $group = $this->group('ERAZER SPECTATOR P10 MD20124', 'Erazer');
        $group->update(['display_title' => 'Erazer Spectator P10 gaming monitor']);

        $props = $this->get("/be-nl/p/{$group->id}/{$group->slug}")->assertOk()->viewData('page')['props'];

        $this->assertSame('Erazer Spectator P10 gaming monitor', $props['product']['title']);
    }

    #[Test]
    public function a_saved_item_snapshots_the_written_title(): void
    {
        /*
         * The snapshot is still taken at the save. It is no longer what the
         * list renders — see the three tests below — but it is what a list
         * falls back to once the product is deleted, so it has to be the
         * written title rather than the feed's.
         */
        $user = User::factory()->create();
        $list = Wishlist::factory()->create(['owner_user_id' => $user->id, 'market' => Market::BeNl]);
        $group = $this->group('SONY WH-1000XM5 DRAADLOZE KOPTELEFOON', 'Sony');
        $group->update(['display_title' => 'Sony WH-1000XM5: stilte om je heen']);

        $this->actingAs($user)->post('/be-nl/list-items', [
            'wishlist_id' => $list->id,
            'group_id' => $group->id,
        ])->assertRedirect();

        $this->assertSame(
            'Sony WH-1000XM5: stilte om je heen',
            WishlistItem::query()->where('group_id', $group->id)->firstOrFail()->snapshot_title,
        );
    }

    #[Test]
    public function a_list_shows_the_products_title_as_it_is_now(): void
    {
        /*
         * The owner's report, 2026-09-18: their own list still read like a
         * feed after the titles were written. It would have, for ever — the
         * snapshot is taken once, at the save, so nothing written afterwards
         * could reach an item somebody had already kept.
         */
        $user = User::factory()->create();
        $list = Wishlist::factory()->create(['owner_user_id' => $user->id, 'market' => Market::BeNl]);
        $group = $this->group('SONY WH-1000XM5 DRAADLOZE KOPTELEFOON MET RUISONDERDRUKKING', 'Sony');

        $item = WishlistItem::factory()->of($group)->create([
            'wishlist_id' => $list->id,
            'snapshot_title' => 'SONY WH-1000XM5 DRAADLOZE KOPTELEFOON MET RUISONDERDRUKKING',
        ]);

        // Written after the item was saved, which is the whole point.
        $group->update(['display_title' => 'Sony WH-1000XM5: stilte om je heen']);

        $this->actingAs($user)
            ->get("/be-nl/lists/{$list->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('items.0.title', 'Sony WH-1000XM5: stilte om je heen'));

        // The stored copy is untouched: it is a fallback, not the render.
        $this->assertSame(
            'SONY WH-1000XM5 DRAADLOZE KOPTELEFOON MET RUISONDERDRUKKING',
            $item->fresh()->snapshot_title,
        );
    }

    #[Test]
    public function a_deleted_product_leaves_the_item_reading_its_snapshot(): void
    {
        /*
         * `group_id` is `nullOnDelete`, so losing the product does not lose the
         * entry — and this is the case the snapshot exists for. A feed drops a
         * product and the list still shows what the person chose.
         */
        $user = User::factory()->create();
        $list = Wishlist::factory()->create(['owner_user_id' => $user->id, 'market' => Market::BeNl]);
        $group = $this->group('SONY WH-1000XM5', 'Sony');

        WishlistItem::factory()->of($group)->create([
            'wishlist_id' => $list->id,
            'snapshot_title' => 'Sony WH-1000XM5 koptelefoon',
        ]);

        $group->delete();

        $this->actingAs($user)
            ->get("/be-nl/lists/{$list->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('items.0.title', 'Sony WH-1000XM5 koptelefoon'));
    }

    #[Test]
    public function a_hand_written_item_keeps_the_title_its_owner_typed(): void
    {
        // The one kind of title a person may edit, and the one kind with no
        // product behind it. Nothing may overwrite it.
        $user = User::factory()->create();
        $list = Wishlist::factory()->create(['owner_user_id' => $user->id, 'market' => Market::BeNl]);

        WishlistItem::create([
            'wishlist_id' => $list->id,
            'group_id' => null,
            'source' => Source::Manual->value,
            'snapshot_title' => 'Een dag zeilen op de Schelde',
            'accepted_at' => now(),
        ]);

        $this->actingAs($user)
            ->get("/be-nl/lists/{$list->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('items.0.title', 'Een dag zeilen op de Schelde'));
    }

    #[Test]
    public function an_editor_sees_both_titles(): void
    {
        // Matching a product to a feed means reading the feed's words; a
        // lookup that hid them would send authors guessing at ids.
        $group = $this->group('SONY WH-1000XM5 DRAADLOZE KOPTELEFOON', 'Sony');
        $group->update(['display_title' => 'Sony WH-1000XM5: stilte om je heen']);

        $this->withToken(ApiToken::issue('test', [ApiToken::READ])['token'])
            ->getJson("/api/editorial/products/{$group->id}")
            ->assertOk()
            ->assertJsonPath('data.title', 'SONY WH-1000XM5 DRAADLOZE KOPTELEFOON')
            ->assertJsonPath('data.displayTitle', 'Sony WH-1000XM5: stilte om je heen');
    }
}

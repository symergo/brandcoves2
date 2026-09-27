<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\Market;
use App\Filament\Pages\ProductTagging;
use App\Models\ApiToken;
use App\Models\ProductGroup;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The tagging queue: what the admin's Product tagging page counts, what
 * `GET /products/to-tag` lists, and a write taking a product out of it.
 */
class TaggingQueueTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $extra */
    private function group(string $title, array $extra = []): ProductGroup
    {
        return ProductGroup::create([
            'market' => Market::BeNl,
            'identity_key' => 'k'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => $title,
            'slug' => 'p-'.bin2hex(random_bytes(3)),
            'min_price' => 2900,
            'merchant_count' => 1,
            'in_stock' => true,
            'giftable' => true,
            'first_seen_at' => now()->subYear(),
            ...$extra,
        ]);
    }

    private function saveToList(ProductGroup $group, int $daysAgo = 1): void
    {
        $owner = User::create(['name' => 'Eigenaar', 'email' => 'o'.bin2hex(random_bytes(4)).'@example.test', 'password' => 'password-for-testing']);
        $list = Wishlist::create(['owner_user_id' => $owner->id, 'title' => 'Verjaardag', 'market' => Market::BeNl, 'kind' => ListKind::Mine]);

        $item = WishlistItem::create(['wishlist_id' => $list->id, 'group_id' => $group->id, 'snapshot_title' => $group->title]);
        $item->forceFill(['created_at' => now()->subDays($daysAgo)])->save();
    }

    private function key(): string
    {
        return ApiToken::issue('test', ApiToken::abilities())['token'];
    }

    #[Test]
    public function the_lists_queue_holds_recently_saved_products_nobody_has_judged(): void
    {
        $saved = $this->group('Koffiemolen');
        // The rules' verdict is not asked: somebody chose it, so it is judged.
        $savedButRejected = $this->group('Waterfilter', ['giftable' => false]);
        $savedLongAgo = $this->group('Oude lamp');
        $judged = $this->group('Theepot', ['gift_tags_at' => now()->subDay()]);
        $this->group('Nooit bewaard');

        $this->saveToList($saved);
        $this->saveToList($savedButRejected);
        $this->saveToList($savedLongAgo, daysAgo: 40);
        $this->saveToList($judged);

        $this->withToken($this->key())
            ->getJson('/api/editorial/products/to-tag?market=be-nl&source=lists&days=7')
            ->assertOk()
            ->assertJsonPath('waiting', 2)
            ->assertJsonPath('data.0.id', $saved->id)
            ->assertJsonPath('data.1.id', $savedButRejected->id)
            ->assertJsonPath('data.1.giftableByRules', false)
            // Only the product: nothing about the list or who saved it.
            ->assertJsonMissingPath('data.0.wishlistId');
    }

    #[Test]
    public function the_new_queue_holds_recent_giftable_products(): void
    {
        $new = $this->group('Nieuwe speaker', ['first_seen_at' => now()->subDays(2)]);
        $this->group('Nieuwe cartridge', ['first_seen_at' => now()->subDays(2), 'giftable' => false]);
        $this->group('Oude speaker');

        $this->withToken($this->key())
            ->getJson('/api/editorial/products/to-tag?market=be-nl&source=new&days=7')
            ->assertOk()
            ->assertJsonPath('waiting', 1)
            ->assertJsonPath('data.0.id', $new->id);
    }

    #[Test]
    public function any_tag_write_takes_a_product_out_of_the_queue_even_with_no_tags(): void
    {
        $group = $this->group('Iets zonder passende tag');
        $this->saveToList($group);

        // "A gift, no tag fits" leaves the tags empty; the timestamp is what
        // says it was judged.
        $this->withToken($this->key())
            ->postJson('/api/editorial/products/tags', [
                'market' => 'be-nl',
                'tags' => [['id' => $group->id, 'tags' => []]],
            ])
            ->assertOk();

        $this->assertNotNull($group->fresh()->gift_tags_at);

        $this->withToken($this->key())
            ->getJson('/api/editorial/products/to-tag?market=be-nl&source=lists')
            ->assertOk()
            ->assertJsonPath('waiting', 0);
    }

    #[Test]
    public function the_admin_page_counts_the_queue_and_writes_a_prompt(): void
    {
        $this->saveToList($this->group('Koffiemolen'));

        $admin = User::create(['name' => 'Root', 'email' => 'root@example.test', 'password' => 'password-for-testing']);
        $admin->forceFill(['is_admin' => true])->save();

        $this->actingAs($admin)->get('/admin/product-tagging')->assertOk();

        Livewire::actingAs($admin)
            ->test(ProductTagging::class)
            ->assertSee('giftcoves-tag-products')
            ->assertSee('be-nl: 1 waiting')
            // Nothing new in the catalogue this week: no prompt that fetches nothing.
            ->set('source', 'new')
            ->assertSee('Nothing waiting in this queue');
    }
}

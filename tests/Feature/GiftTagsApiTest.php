<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\Preference;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\ApiToken;
use App\Models\DailyPick;
use App\Models\DailyPickSet;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Gift\GiftTags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Gift tags over the editorial API: what wants tagging, and writing tags.
 */
class GiftTagsApiTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    private int $editions = 0;

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
    private function group(string $title, Market $market = Market::BeNl, array $extra = []): ProductGroup
    {
        $group = ProductGroup::create([
            'market' => $market,
            'identity_key' => 'k'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => $title,
            'slug' => 'p-'.bin2hex(random_bytes(3)),
            'brand' => 'Merk',
            'category' => 'audio',
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
            'market' => $market,
            'merchant_id' => $this->merchant->id,
            'group_id' => $group->id,
            'external_id' => 'e'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => $title,
            'merchant_category' => 'audio',
            'price' => 4900,
            'currency' => 'EUR',
            'affiliate_url' => 'https://example.test/buy',
            'availability' => Availability::InStock,
            'status' => ProductStatus::Active,
            'identity_key' => $group->identity_key,
        ]);

        return $group;
    }

    private function onDaily(ProductGroup $group): void
    {
        $edition = DailyPickSet::create([
            'market' => $group->market,
            'kind' => CoveKind::Daily,
            'slug' => 'ed-'.bin2hex(random_bytes(3)),
            'theme_title' => 'Editie',
            'theme_slug' => 'editie-'.bin2hex(random_bytes(3)),
            'status' => 'published',
            'published_at' => now()->subHour(),
            'drop_date' => now()->subDays($this->editions++)->toDateString(),
        ]);

        DailyPick::create(['set_id' => $edition->id, 'group_id' => $group->id, 'rank' => 1, 'slug' => 'pick']);
    }

    /** @param list<string> $abilities */
    private function key(array $abilities): string
    {
        return ApiToken::issue('test', $abilities)['token'];
    }

    #[Test]
    public function the_vocabulary_is_the_wizards_own_words(): void
    {
        $vocabulary = GiftTags::vocabulary();

        $this->assertSame(['interest', 'occasion', 'recipient', 'age', 'vibe', 'preference', 'values'], array_keys($vocabulary));
        $this->assertContains('coffee', $vocabulary['interest']);
        $this->assertContains('christmas', $vocabulary['occasion']);
        $this->assertContains('sinterklaas', $vocabulary['occasion']);
        $this->assertContains('easter', $vocabulary['occasion']);
        $this->assertContains('cycling', $vocabulary['interest']);
        $this->assertNotContains('other', $vocabulary['occasion']);
        $this->assertContains('mother', $vocabulary['recipient']);
        $this->assertContains('13-17', $vocabulary['age']);
        $this->assertNotContains('teen', $vocabulary['recipient']);
        $this->assertSame(['0-2', '3-5', '6-9', '10-12', '13-17', '18-29', '30-49', '50-64', '65+'], $vocabulary['age']);
        $this->assertContains('playful', $vocabulary['vibe']);
        // Which way their taste goes, which the vibe cannot say. Pairs of
        // opposites, so every pole has its other end in the list.
        $this->assertContains('vintage', $vocabulary['preference']);
        $this->assertContains('preference:vintage', GiftTags::all());

        foreach (Preference::cases() as $pole) {
            $this->assertNotSame($pole, $pole->opposite(), $pole->value.' sits on no axis');
            $this->assertSame($pole, $pole->opposite()->opposite());
            $this->assertSame($pole->axis(), $pole->opposite()->axis());
        }
        $this->assertContains('handmade', $vocabulary['values']);
        $this->assertContains('interest:coffee', GiftTags::all());
    }

    #[Test]
    public function the_untagged_list_carries_the_vocabulary_and_skips_the_tagged(): void
    {
        $wanted = $this->group('Nog niet getagd');
        $this->onDaily($wanted);
        $done = $this->group('Al getagd', extra: ['gift_tags' => ['interest:coffee']]);
        $this->onDaily($done);

        $this->withToken($this->key([ApiToken::READ]))
            ->getJson('/api/editorial/products/untagged?market=be-nl')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.id', $wanted->id)
            ->assertJsonPath('data.0.tags', [])
            ->assertJsonPath('data.0.surfaces', ['daily'])
            ->assertJsonPath('vocabulary.recipient', GiftTags::vocabulary()['recipient']);
    }

    #[Test]
    public function a_publish_key_writes_tags_normalised_and_replaces_them(): void
    {
        $group = $this->group('Koffiemolen');
        $key = $this->key(ApiToken::abilities());

        $this->withToken($key)
            ->postJson('/api/editorial/products/tags', [
                'market' => 'be-nl',
                'tags' => [['id' => $group->id, 'tags' => ['Recipient:Mother', 'interest:coffee', 'interest:coffee ', 'vibe:practical']]],
            ])
            ->assertOk()
            ->assertJsonPath('count', 1)
            // Vocabulary order, lower case, no duplicates.
            ->assertJsonPath('data.0.tags', ['interest:coffee', 'recipient:mother', 'vibe:practical']);

        $this->assertSame(['interest:coffee', 'recipient:mother', 'vibe:practical'], $group->fresh()->giftTags());

        // A second write replaces: the wrong tag is taken off by leaving it out.
        $this->withToken($key)
            ->postJson('/api/editorial/products/tags', [
                'market' => 'be-nl',
                'tags' => [['id' => $group->id, 'tags' => ['interest:coffee']]],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.tags', ['interest:coffee']);

        // An empty set clears.
        $this->withToken($key)
            ->postJson('/api/editorial/products/tags', [
                'market' => 'be-nl',
                'tags' => [['id' => $group->id, 'tags' => []]],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.tags', []);

        $this->withToken($key)
            ->getJson("/api/editorial/products/{$group->id}")
            ->assertOk()
            ->assertJsonPath('data.tags', []);
    }

    #[Test]
    public function a_tag_outside_the_vocabulary_refuses_the_batch(): void
    {
        $group = $this->group('Koffiemolen');

        $this->withToken($this->key(ApiToken::abilities()))
            ->postJson('/api/editorial/products/tags', [
                'market' => 'be-nl',
                'tags' => [
                    ['id' => $group->id, 'tags' => ['interest:coffee', 'interest:espresso', 'mood:cosy']],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tags');

        $this->assertSame([], $group->fresh()->giftTags());
    }

    #[Test]
    public function a_foreign_id_refuses_the_batch(): void
    {
        $group = $this->group('Echt product');
        $other = $this->group('Ander land', Market::NlNl);

        $this->withToken($this->key(ApiToken::abilities()))
            ->postJson('/api/editorial/products/tags', [
                'market' => 'be-nl',
                'tags' => [
                    ['id' => $group->id, 'tags' => ['interest:coffee']],
                    ['id' => $other->id, 'tags' => ['interest:coffee']],
                ],
            ])
            ->assertStatus(422);

        $this->assertSame([], $group->fresh()->giftTags());
    }

    #[Test]
    public function writing_tags_takes_the_publish_ability(): void
    {
        $group = $this->group('Echt product');
        $body = ['market' => 'be-nl', 'tags' => [['id' => $group->id, 'tags' => ['interest:coffee']]]];

        $this->withToken($this->key([ApiToken::READ, ApiToken::WRITE]))
            ->postJson('/api/editorial/products/tags', $body)
            ->assertStatus(403);

        $this->withToken($this->key([ApiToken::PUBLISH]))
            ->postJson('/api/editorial/products/tags', $body)
            ->assertOk();
    }
}

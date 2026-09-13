<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\ApiToken;
use App\Models\CovePlan;
use App\Models\DailyPick;
use App\Models\DailyPickSet;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The two endpoints that let display titles be written from outside:
 * which products want one, and writing them in batch.
 */
class ProductTitlesApiTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    /** One edition or plan per day and market; each helper call takes the next day back. */
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

    private function onDaily(ProductGroup $group, string $status = 'published'): void
    {
        $edition = DailyPickSet::create([
            'market' => $group->market,
            'kind' => CoveKind::Daily,
            'slug' => 'ed-'.bin2hex(random_bytes(3)),
            'theme_title' => 'Editie',
            'theme_slug' => 'editie-'.bin2hex(random_bytes(3)),
            'status' => $status,
            'published_at' => now()->subHour(),
            'drop_date' => now()->subDays($this->editions++)->toDateString(),
        ]);

        DailyPick::create(['set_id' => $edition->id, 'group_id' => $group->id, 'rank' => 1, 'slug' => 'pick']);
    }

    private function onPlan(ProductGroup $group, string $status = 'draft'): void
    {
        $plan = CovePlan::create([
            'market' => $group->market->value,
            'drop_date' => now()->subDays($this->editions++)->toDateString(),
            'title' => 'Plan',
            'status' => $status,
        ]);

        $plan->items()->create(['group_id' => $group->id, 'rank' => 1]);
    }

    private function onChart(ProductGroup $group): void
    {
        DB::table('popular_ranks')->insert([
            'source' => 'bol',
            'market' => $group->market->value,
            'category_external_id' => '*',
            'external_id' => 'x'.bin2hex(random_bytes(3)),
            'rank' => 1,
            'captured_on' => now()->toDateString(),
            'captured_at' => now(),
            'group_id' => $group->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param list<string> $abilities */
    private function key(array $abilities): string
    {
        return ApiToken::issue('test', $abilities)['token'];
    }

    #[Test]
    public function the_untitled_list_names_every_editorial_surface(): void
    {
        $daily = $this->group('Op de editie');
        $this->onDaily($daily);
        $plan = $this->group('Op een plan');
        $this->onPlan($plan);
        $chart = $this->group('In de hitlijst');
        $this->onChart($chart);
        $surprise = $this->group('Verrassend', extra: ['surprise_score' => 80]);

        // Not listed: already written, on a plan that ran, another market,
        // and a product on no surface at all.
        $written = $this->group('Al geschreven', extra: ['display_title' => 'Klaar']);
        $this->onDaily($written);
        $used = $this->group('Gebruikt plan');
        $this->onPlan($used, 'used');
        $elsewhere = $this->group('Elders', Market::NlNl);
        $this->onDaily($elsewhere);
        $this->group('Gewoon in de catalogus');

        $response = $this->withToken($this->key([ApiToken::READ]))
            ->getJson('/api/editorial/products/untitled?market=be-nl')
            ->assertOk()
            ->assertJsonPath('market', 'be-nl')
            ->assertJsonPath('count', 4);

        $rows = collect($response->json('data'))->keyBy('id');

        $this->assertSame(['daily'], $rows[$daily->id]['surfaces']);
        $this->assertSame(['plan'], $rows[$plan->id]['surfaces']);
        $this->assertSame(['chart'], $rows[$chart->id]['surfaces']);
        $this->assertSame(['surprise'], $rows[$surprise->id]['surfaces']);
        $this->assertSame('Op de editie', $rows[$daily->id]['title']);
        $this->assertSame($daily->path(), $rows[$daily->id]['url']);
    }

    #[Test]
    public function the_list_pages_by_id(): void
    {
        $groups = [];

        foreach (['Een', 'Twee', 'Drie'] as $title) {
            $groups[] = $g = $this->group($title);
            $this->onDaily($g);
        }

        $key = $this->key([ApiToken::READ]);

        $first = $this->withToken($key)
            ->getJson('/api/editorial/products/untitled?market=be-nl&limit=2')
            ->assertOk()
            ->assertJsonPath('count', 2)
            ->json('data');

        $last = end($first)['id'];

        $this->withToken($key)
            ->getJson("/api/editorial/products/untitled?market=be-nl&limit=2&after={$last}")
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.id', $groups[2]->id);
    }

    #[Test]
    public function a_publish_key_writes_titles_in_house_style(): void
    {
        $group = $this->group('KOFFIEMOLEN HANDMATIG XQ-9');
        $this->onDaily($group);
        $key = $this->key(ApiToken::abilities());

        $this->withToken($key)
            ->postJson('/api/editorial/products/titles', [
                'market' => 'be-nl',
                'titles' => [['id' => $group->id, 'title' => '**Hario** handmolen — verse koffie, elke ochtend']],
            ])
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.title', 'KOFFIEMOLEN HANDMATIG XQ-9')
            // Bold markers off, the em dash a spaced hyphen: a title is a text node.
            ->assertJsonPath('data.0.displayTitle', 'Hario handmolen - verse koffie, elke ochtend')
            ->assertJsonPath('data.0.written', true);

        // Written, so no longer wanted.
        $this->withToken($key)
            ->getJson('/api/editorial/products/untitled?market=be-nl')
            ->assertOk()
            ->assertJsonPath('count', 0);

        // Null withdraws it, and the product is wanted again.
        $this->withToken($key)
            ->postJson('/api/editorial/products/titles', [
                'market' => 'be-nl',
                'titles' => [['id' => $group->id, 'title' => null]],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.written', false);

        $this->assertNull($group->fresh()->display_title);
        $this->withToken($key)
            ->getJson('/api/editorial/products/untitled?market=be-nl')
            ->assertJsonPath('count', 1);
    }

    #[Test]
    public function a_foreign_id_refuses_the_whole_batch(): void
    {
        // All or nothing: a batch that half lands is a batch whose author
        // does not know what landed.
        $group = $this->group('Echt product');
        $other = $this->group('Ander land', Market::NlNl);

        $this->withToken($this->key(ApiToken::abilities()))
            ->postJson('/api/editorial/products/titles', [
                'market' => 'be-nl',
                'titles' => [
                    ['id' => $group->id, 'title' => 'Een goede titel'],
                    ['id' => $other->id, 'title' => 'Hoort hier niet'],
                    ['id' => 999999, 'title' => 'Bestaat niet'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('titles');

        $this->assertNull($group->fresh()->display_title);
    }

    #[Test]
    public function a_title_too_short_or_too_long_is_refused(): void
    {
        $group = $this->group('Echt product');
        $key = $this->key(ApiToken::abilities());

        $this->withToken($key)
            ->postJson('/api/editorial/products/titles', [
                'market' => 'be-nl',
                'titles' => [['id' => $group->id, 'title' => 'ab']],
            ])
            ->assertStatus(422);

        $this->withToken($key)
            ->postJson('/api/editorial/products/titles', [
                'market' => 'be-nl',
                'titles' => [['id' => $group->id, 'title' => str_repeat('lang ', 20)]],
            ])
            ->assertStatus(422);

        $this->assertNull($group->fresh()->display_title);
    }

    #[Test]
    public function writing_a_title_takes_the_publish_ability(): void
    {
        // A title reaches every reader on the next request, which is what
        // the publish ability is for; write is for drafts nobody sees.
        $group = $this->group('Echt product');
        $body = ['market' => 'be-nl', 'titles' => [['id' => $group->id, 'title' => 'Een goede titel']]];

        $this->withToken($this->key([ApiToken::READ]))
            ->postJson('/api/editorial/products/titles', $body)
            ->assertStatus(403);

        $this->withToken($this->key([ApiToken::READ, ApiToken::WRITE]))
            ->postJson('/api/editorial/products/titles', $body)
            ->assertStatus(403);

        $this->withToken($this->key([ApiToken::PUBLISH]))
            ->postJson('/api/editorial/products/titles', $body)
            ->assertOk();

        $this->assertSame('Een goede titel', $group->fresh()->display_title);
    }
}

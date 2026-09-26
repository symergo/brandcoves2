<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\ApiToken;
use App\Models\CovePlan;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Cove\EditionBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A Cove plan chosen by a gift brief (roadmap step 4, part 4):
 * `cove_plans.brief` over the editorial API, and the builder filling the open
 * slots from the suggestion engine with it, without ever asking a model.
 *
 * "Brief" here is the gift brief (who it is for, what they love), not the
 * writing brief `GET /coves/{id}/brief` returns.
 */
class CoveGiftBriefTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::today()->setTime(12, 0));
    }

    #[Test]
    public function the_api_stores_a_brief_and_reads_it_back(): void
    {
        $response = $this->withToken($this->key())
            ->postJson('/api/editorial/coves', [
                'market' => 'be-nl',
                'kind' => 'persona',
                'slug' => 'de-thuiskok',
                'title' => 'De thuiskok',
                'brief' => ['relationship' => 'Father', 'interests' => ['cooking'], 'budgetMax' => 5000],
            ])
            ->assertCreated()
            ->assertJsonPath('data.brief.relationship', 'father')
            ->assertJsonPath('data.brief.interests', ['cooking'])
            ->assertJsonPath('data.brief.budgetMax', 5000);

        $plan = CovePlan::findOrFail($response->json('data.id'));
        $this->assertEquals(['relationship' => 'father', 'interests' => ['cooking'], 'budgetMax' => 5000], $plan->brief);
    }

    #[Test]
    public function a_whole_plan_write_without_a_brief_resets_it(): void
    {
        $key = $this->key();
        $body = ['market' => 'be-nl', 'kind' => 'persona', 'slug' => 'de-thuiskok', 'title' => 'De thuiskok'];

        $this->withToken($key)->postJson('/api/editorial/coves', [...$body, 'brief' => ['interests' => ['cooking']]])->assertCreated();

        // POST /coves is a whole-plan write: what it is not sent, it resets.
        $this->withToken($key)->postJson('/api/editorial/coves', $body)
            ->assertOk()
            ->assertJsonPath('data.brief', null);
    }

    #[Test]
    public function patch_changes_the_brief_alone_and_null_clears_it(): void
    {
        $key = $this->key();

        $id = $this->withToken($key)->postJson('/api/editorial/coves', [
            'market' => 'be-nl', 'kind' => 'persona', 'slug' => 'de-thuiskok', 'title' => 'De thuiskok',
            'queries' => ['koksmes'],
        ])->json('data.id');

        $this->withToken($key)->patchJson("/api/editorial/coves/{$id}", ['brief' => ['interests' => ['coffee']]])
            ->assertOk()
            ->assertJsonPath('data.brief.interests', ['coffee'])
            ->assertJsonPath('data.queries', ['koksmes'])
            ->assertJsonPath('data.title', 'De thuiskok');

        // Untouched when left out.
        $this->withToken($key)->patchJson("/api/editorial/coves/{$id}", ['title' => 'De koffieliefhebber'])
            ->assertOk()
            ->assertJsonPath('data.brief.interests', ['coffee']);

        $this->withToken($key)->patchJson("/api/editorial/coves/{$id}", ['brief' => null])
            ->assertOk()
            ->assertJsonPath('data.brief', null);
    }

    #[Test]
    public function values_outside_the_vocabulary_are_refused_by_name(): void
    {
        $this->withToken($this->key())
            ->postJson('/api/editorial/coves', [
                'market' => 'be-nl', 'kind' => 'persona', 'slug' => 'de-oom', 'title' => 'De oom',
                'brief' => ['relationship' => 'uncle', 'interests' => ['cooking', 'underwater basket weaving']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['brief.relationship', 'brief.interests']);

        $this->assertDatabaseMissing('cove_plans', ['slug' => 'de-oom']);
    }

    #[Test]
    public function a_guide_refuses_a_brief_it_would_never_read(): void
    {
        $this->withToken($this->key())
            ->postJson('/api/editorial/coves', [
                'market' => 'be-nl', 'kind' => 'guide', 'slug' => 'beste-koksmessen', 'title' => 'Beste koksmessen',
                'brief' => ['interests' => ['cooking']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['brief']);
    }

    #[Test]
    public function the_builder_fills_open_slots_from_the_brief_and_never_calls_a_model(): void
    {
        /*
         * A client that would answer if asked. The brief path must not ask:
         * choosing products is retrieval and arithmetic, and the prose here is
         * authored. Invariant 1 is about web requests; this is about spend.
         */
        config([
            'giftcoves.ai.enabled' => true,
            'giftcoves.ai.api_key' => 'sk-ant-test',
            'giftcoves.ai.model' => 'claude-sonnet-5',
        ]);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => '{}']]])]);

        $curated = $this->group('Kookboek van de chef', ['interest:reading']);
        $knives = collect(range(1, 8))->map(fn (int $i) => $this->group("Koksmes nummer {$i}", ['interest:cooking']));
        $this->group('Bijzonder apparaat', []);

        $plan = CovePlan::create([
            'market' => Market::BeNl->value,
            'kind' => 'persona',
            'slug' => 'de-thuiskok',
            'title' => 'De thuiskok',
            'status' => 'approved',
            'writer' => 'authored',
            'editorial' => 'Voor wie het liefst in de keuken staat.',
            'brief' => ['relationship' => 'father', 'interests' => ['cooking']],
        ]);
        $plan->items()->create(['group_id' => $curated->id, 'rank' => 1]);

        $edition = app(EditionBuilder::class)->buildPersona($plan);

        $this->assertNotNull($edition);

        $picked = $edition->picks()->orderBy('rank')->pluck('group_id')->all();

        // The curator's pick first, then only products that answer the brief.
        $this->assertSame($curated->id, $picked[0]);
        $this->assertNotEmpty(array_slice($picked, 1));
        $this->assertSame([], array_diff(array_slice($picked, 1), $knives->pluck('id')->all()));

        Http::assertNothingSent();
    }

    private function key(): string
    {
        return ApiToken::issue('test key', [ApiToken::READ, ApiToken::WRITE])['token'];
    }

    /** @param list<string> $tags */
    private function group(string $title, array $tags): ProductGroup
    {
        $merchant = Merchant::query()->firstOrCreate(
            ['source' => Source::Awin->value, 'external_id' => 'shop'],
            ['name' => 'Shop'],
        );

        $group = ProductGroup::create([
            'market' => Market::BeNl,
            'identity_key' => 'k'.bin2hex(random_bytes(6)),
            'identity_kind' => 'ean',
            'title' => $title,
            'slug' => 'p-'.bin2hex(random_bytes(4)),
            'category' => 'Keuken',
            'image_url' => 'https://img.test/x.jpg',
            'min_price' => 2500,
            'merchant_count' => 1,
            'in_stock' => true,
            'giftable' => true,
            'gift_tags' => $tags,
        ]);

        Product::create([
            'source' => Source::Awin,
            'market' => Market::BeNl,
            'merchant_id' => $merchant->id,
            'group_id' => $group->id,
            'external_id' => 'e'.bin2hex(random_bytes(6)),
            'identity_kind' => 'ean',
            'title' => $title,
            'merchant_category' => 'Keuken',
            'price' => 2500,
            'currency' => 'EUR',
            'affiliate_url' => 'https://example.test/buy',
            'availability' => Availability::InStock,
            'status' => ProductStatus::Active,
            'identity_key' => $group->identity_key,
        ]);

        return $group;
    }
}

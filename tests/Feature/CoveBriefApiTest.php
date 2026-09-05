<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PickMode;
use App\Enums\PlanWriter;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\ApiToken;
use App\Models\CovePlan;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\PromptTemplate;
use App\Services\Ai\AiClient;
use App\Services\Cove\EditionBuilder;
use ArrayObject;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `GET /coves/{id}/brief` — the prompt, handed to whoever is writing.
 *
 * The endpoint that makes the editable prompt bank mean something for an author
 * who is not the built-in model. Before it, the writing contract reached an
 * external writer as four hand-maintained copies in the API root, two docs and a
 * skill — and they had already drifted: the root omitted the
 * one-paragraph-per-product rule that `ProseCards` exists to make undroppable.
 *
 * The first test is the one that matters. Everything else here is a convenience;
 * that one is the property.
 */
class CoveBriefApiTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::today()->setTime(12, 0));

        $this->merchant = Merchant::create([
            'source' => Source::Awin->value,
            'external_id' => 'shop',
            'name' => 'Shop',
        ]);
    }

    #[Test]
    public function the_brief_is_byte_identical_to_what_the_builder_sends(): void
    {
        /*
         * The whole point, and the only thing that stops the contract drifting
         * again. Two writers, one prompt: if these two strings can differ then
         * an author is being told something the builder does not do, which is
         * the state this endpoint was built to end.
         *
         * Locked, because a locked shortlist *is* the edition — for an open plan
         * the engine tops the list up on the day and the prompt is necessarily
         * about a page that does not exist yet.
         */
        $plan = $this->lockedPlan();

        $sent = new ArrayObject;

        $this->mock(AiClient::class, function ($mock) use ($sent) {
            $mock->shouldReceive('isEnabled')->andReturn(true);
            $mock->shouldReceive('json')->andReturnUsing(
                function (string $feature, string $system, string $prompt) use ($sent) {
                    $sent['system'] = $system;
                    $sent['user'] = $prompt;

                    return ['title' => 'x', 'intro' => 'x', 'body' => 'x', 'items' => []];
                }
            );
        });

        app(EditionBuilder::class)->buildArticle($plan);

        $served = $this->withToken($this->key())
            ->getJson("/api/editorial/coves/{$plan->id}/brief")
            ->assertOk()
            ->json('data.prompt');

        $this->assertSame($sent['system'], $served['system']);
        $this->assertSame($sent['user'], $served['user']);
    }

    #[Test]
    public function an_edited_prompt_reaches_the_author_too(): void
    {
        /*
         * The reason the bank is worth exposing rather than describing. An
         * editor changes the voice at Operations → Prompts and it governs Claude
         * the same afternoon — otherwise the panel edits one writer and a file
         * somewhere edits the other.
         */
        PromptTemplate::create([
            'slot' => 'cove.guide',
            'system' => 'Schrijf alsof je haast hebt.',
            'enabled' => true,
        ]);

        $plan = $this->lockedPlan();

        $served = $this->withToken($this->key())
            ->getJson("/api/editorial/coves/{$plan->id}/brief")
            ->assertOk()
            ->json('data.prompt.system');

        $this->assertStringContainsString('Schrijf alsof je haast hebt.', $served);
    }

    #[Test]
    public function the_rules_a_prompt_edit_may_not_drop_are_still_there(): void
    {
        // A prompt-bank edit may change how a Cove sounds. It may not remove the
        // rule that decides whether its cards render, or the link contract.
        PromptTemplate::create([
            'slot' => 'cove.guide',
            'system' => 'Alleen dit.',
            'enabled' => true,
        ]);

        $plan = $this->lockedPlan();

        $served = $this->withToken($this->key())
            ->getJson("/api/editorial/coves/{$plan->id}/brief")
            ->assertOk()
            ->json('data.prompt.system');

        $this->assertStringContainsString('EVERY product listed below', $served);
        $this->assertStringContainsString('[[product:', $served);
    }

    #[Test]
    public function the_brief_carries_the_revision_and_the_floor(): void
    {
        $plan = $this->lockedPlan();

        $response = $this->withToken($this->key())
            ->getJson("/api/editorial/coves/{$plan->id}/brief")
            ->assertOk();

        // Quotable straight back to POST /coves/{id}/editorial — the queue
        // endpoint cannot supply one for a plan that already has prose.
        $this->assertNotEmpty($response->json('data.revision'));

        // And what the page has to clear, before anybody writes a word for it.
        $this->assertSame(5, $response->json('data.floor.minimum'));
        $this->assertSame(5, $response->json('data.floor.curated'));
        $this->assertTrue($response->json('data.floor.buildable'));
    }

    #[Test]
    public function a_read_key_is_enough_and_no_model_is_called(): void
    {
        $plan = $this->lockedPlan();

        // Nothing in a request handler may reach a model. Invariant 1, and this
        // endpoint is the most tempting place in the API to break it.
        $this->mock(AiClient::class, fn ($mock) => $mock->shouldNotReceive('json'));

        $this->withToken(ApiToken::issue('reader', [ApiToken::READ])['token'])
            ->getJson("/api/editorial/coves/{$plan->id}/brief")
            ->assertOk();
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function key(): string
    {
        return ApiToken::issue('test key', [ApiToken::READ])['token'];
    }

    /** A guide whose curated shortlist is the whole edition. */
    private function lockedPlan(): CovePlan
    {
        $plan = CovePlan::create([
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Guide->value,
            'slug' => 'beste-koptelefoons',
            'title' => 'Beste koptelefoons',
            'status' => 'approved',
            'writer' => PlanWriter::Builder->value,
            'pick_mode' => PickMode::Locked->value,
            'build_instructions' => 'Kort houden.',
        ]);

        foreach (['Sony', 'Sennheiser', 'JBL', 'Philips', 'Marshall'] as $i => $brand) {
            $group = $this->product("{$brand} koptelefoon", 5000 + $i * 4000, $brand);

            $plan->items()->create([
                'group_id' => $group->id,
                'rank' => $i + 1,
                'note' => "Waarom {$brand} erbij hoort.",
            ]);
        }

        return $plan->refresh();
    }

    private function product(string $title, int $price, string $brand): ProductGroup
    {
        $group = ProductGroup::create([
            'market' => Market::BeNl,
            'identity_key' => 'k'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => $title,
            'slug' => 'p-'.bin2hex(random_bytes(3)),
            'brand' => $brand,
            'category' => 'audio',
            'image_url' => 'https://img.test/x.jpg',
            'min_price' => $price,
            'merchant_count' => 1,
            'in_stock' => true,
            'giftable' => true,
            'surprise_score' => 60,
        ]);

        Product::create([
            'source' => Source::Awin,
            'market' => Market::BeNl,
            'merchant_id' => $this->merchant->id,
            'group_id' => $group->id,
            'external_id' => 'e'.bin2hex(random_bytes(5)),
            'title' => $title,
            'brand' => $brand,
            'price' => $price,
            'currency' => 'EUR',
            'affiliate_url' => 'https://example.test/buy',
            'availability' => Availability::InStock,
            'status' => ProductStatus::Active,
            'identity_key' => $group->identity_key,
        ]);

        return $group;
    }
}

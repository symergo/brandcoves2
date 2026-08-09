<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\PublishStatus;
use App\Enums\Source;
use App\Jobs\BuildDailyEdition;
use App\Models\ApiToken;
use App\Models\CovePlan;
use App\Models\Guide;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Ai\AiClient;
use App\Services\Cove\EditionBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The editorial API.
 *
 * Three properties carry this feature and each is tested directly:
 *
 * 1. **A write-capable key cannot reach a reader.** Drafting and publishing are
 *    separate abilities, and the separation has to hold on every path into a
 *    published state — including the sideways one, editing a plan after a human
 *    approved it.
 * 2. **Nothing an author writes can name a product that does not exist.** Ids
 *    are validated against the market, and a wrong one fails the whole write
 *    rather than vanishing from the middle of an article.
 * 3. **Authored prose survives.** A rebuild must reproduce the article, not
 *    quietly replace it with a generated one — and must not spend on AI to do
 *    it.
 */
class EditorialApiTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        // Same reason as DailyCoveTest: an edition publishes at 09:00 and a
        // suite that passes in the evening and fails in the morning blames
        // whoever ran it.
        $this->travelTo(CarbonImmutable::today()->setTime(12, 0));

        $this->merchant = Merchant::create([
            'source' => Source::Awin->value,
            'external_id' => 'shop',
            'name' => 'Shop',
        ]);
    }

    // ── Authentication ────────────────────────────────────────────────────

    #[Test]
    public function an_unauthenticated_call_is_a_json_401_not_a_redirect(): void
    {
        $response = $this->getJson('/api/editorial');

        $response->assertStatus(401);
        // A redirect to an HTML login page is the least useful answer to give
        // an automated client, and it is what the framework does by default.
        $this->assertSame('application/json', explode(';', (string) $response->headers->get('Content-Type'))[0]);
    }

    #[Test]
    public function a_revoked_key_stops_working_and_says_nothing_useful(): void
    {
        ['token' => $plaintext, 'model' => $token] = ApiToken::issue('claude', [ApiToken::READ]);

        $token->forceFill(['revoked_at' => now()])->save();

        $this->withToken($plaintext)->getJson('/api/editorial')
            ->assertStatus(401)
            // Revoked, expired and never-existed are one message: telling a
            // caller "that key expired" confirms the guess was a real key.
            ->assertJsonPath('message', 'Invalid or expired token.');
    }

    #[Test]
    public function an_expired_key_stops_working(): void
    {
        ['token' => $plaintext] = ApiToken::issue('claude', [ApiToken::READ], now()->subMinute());

        $this->withToken($plaintext)->getJson('/api/editorial')->assertStatus(401);
    }

    #[Test]
    public function only_the_hash_is_stored(): void
    {
        ['token' => $plaintext, 'model' => $token] = ApiToken::issue('claude', [ApiToken::READ]);

        // A database leak must not hand over working keys.
        $this->assertNotSame($plaintext, $token->token_hash);
        $this->assertSame(hash('sha256', $plaintext), $token->token_hash);
        $this->assertDatabaseMissing('api_tokens', ['token_hash' => $plaintext]);
    }

    #[Test]
    public function the_root_reports_what_the_key_may_do(): void
    {
        $this->withToken($this->key([ApiToken::READ]))
            ->getJson('/api/editorial')
            ->assertOk()
            ->assertJsonPath('token.abilities', [ApiToken::READ])
            // A machine client cannot read the docs; the contract travels with
            // the response.
            ->assertJsonStructure(['writing' => ['links', 'products', 'prices'], 'endpoints']);
    }

    // ── Abilities ─────────────────────────────────────────────────────────

    #[Test]
    public function a_read_only_key_cannot_write(): void
    {
        $this->withToken($this->key([ApiToken::READ]))
            ->postJson('/api/editorial/coves', [
                'market' => Market::BeNl->value,
                'title' => 'Iets bijzonders',
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function a_write_key_cannot_approve(): void
    {
        $plan = CovePlan::create([
            'market' => Market::BeNl->value,
            'drop_date' => CarbonImmutable::today()->toDateString(),
            'title' => 'Draft',
            'status' => 'draft',
        ]);

        $this->withToken($this->key([ApiToken::READ, ApiToken::WRITE]))
            ->postJson("/api/editorial/coves/{$plan->id}/approve")
            ->assertStatus(403);

        $this->assertSame('draft', $plan->refresh()->status);
    }

    #[Test]
    public function a_write_key_cannot_rewrite_a_plan_someone_already_approved(): void
    {
        /*
         * The sideways route to publication, and the one worth a test: draft a
         * plan, wait for a human to approve it, then change what it says. If
         * this were allowed the whole draft/approve split would be decoration.
         */
        $date = CarbonImmutable::today()->addDays(3)->toDateString();

        CovePlan::create([
            'market' => Market::BeNl->value,
            'drop_date' => $date,
            'title' => 'Reviewed and approved',
            'status' => 'approved',
        ]);

        $this->withToken($this->key([ApiToken::READ, ApiToken::WRITE]))
            ->postJson('/api/editorial/coves', [
                'market' => Market::BeNl->value,
                'date' => $date,
                'title' => 'Something else entirely',
            ])
            ->assertStatus(403);

        $this->assertDatabaseHas('cove_plans', ['title' => 'Reviewed and approved']);
    }

    // ── Grounding ─────────────────────────────────────────────────────────

    #[Test]
    public function product_lookup_is_scoped_to_the_market(): void
    {
        $this->find('Draadloze koptelefoon', 9900, market: Market::BeNl);
        $this->find('Wireless headphones', 9900, market: Market::En);

        $response = $this->withToken($this->key([ApiToken::READ]))
            ->getJson('/api/editorial/products?market='.Market::BeNl->value)
            ->assertOk();

        $this->assertSame(1, $response->json('count'));
        $this->assertSame('Draadloze koptelefoon', $response->json('data.0.title'));
        // Cents, never a formatted string: these get compared and aggregated.
        $this->assertSame(9900, $response->json('data.0.minPriceCents'));
    }

    #[Test]
    public function an_out_of_stock_product_is_not_offered_to_write_about(): void
    {
        $group = $this->find('Uitverkocht', 4000);
        $group->update(['in_stock' => false]);

        $this->withToken($this->key([ApiToken::READ]))
            ->getJson('/api/editorial/products?market='.Market::BeNl->value)
            ->assertOk()
            ->assertJsonPath('count', 0);
    }

    #[Test]
    public function pinning_a_product_from_another_market_fails_the_whole_write(): void
    {
        $foreign = $this->find('Wireless headphones', 9900, market: Market::En);

        $this->withToken($this->key([ApiToken::READ, ApiToken::WRITE]))
            ->postJson('/api/editorial/coves', [
                'market' => Market::BeNl->value,
                'title' => 'Koptelefoons',
                'pinnedGroupIds' => [$foreign->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pinnedGroupIds');

        // All or nothing: an article whose second pick silently vanished is an
        // article with a dangling sentence.
        $this->assertDatabaseCount('cove_plans', 0);
    }

    // ── Writing a Cove ────────────────────────────────────────────────────

    #[Test]
    public function a_written_cove_lands_as_a_draft_and_reports_its_broken_links(): void
    {
        $pinned = $this->find('Espressomachine', 45000);

        $response = $this->withToken($this->key([ApiToken::READ, ApiToken::WRITE]))
            ->postJson('/api/editorial/coves', [
                'market' => Market::BeNl->value,
                'date' => CarbonImmutable::today()->addDay()->toDateString(),
                'title' => 'De trage ochtend',
                'blurb' => 'Dingen die tijd kosten.',
                'editorial' => "Er is de [[product:{$pinned->id}|espressomachine]], en er is [[product:999999|iets wat niet bestaat]].",
                'queries' => ['espressomachine'],
                'pinnedGroupIds' => [$pinned->id],
            ])
            ->assertStatus(201);

        // A draft. The builder ignores drafts, so nothing here can reach a
        // reader on its own.
        $response->assertJsonPath('data.status', 'draft');

        // The link report is the whole reason a writer can work blind-free: a
        // token outside the allowlist renders as plain text, which is invisible
        // unless someone says so.
        $this->assertSame(1, $response->json('linkCheck.links'));
        $this->assertSame(['product:999999'], $response->json('linkCheck.unresolved'));
    }

    #[Test]
    public function rewriting_the_same_date_updates_rather_than_colliding(): void
    {
        $token = $this->key([ApiToken::READ, ApiToken::WRITE]);
        $date = CarbonImmutable::today()->addDays(2)->toDateString();

        $body = ['market' => Market::BeNl->value, 'date' => $date, 'title' => 'Eerste poging'];

        $this->withToken($token)->postJson('/api/editorial/coves', $body)->assertStatus(201);

        // A retry after a timeout must not hit the one-plan-per-day unique
        // index for work it already did.
        $this->withToken($token)
            ->postJson('/api/editorial/coves', [...$body, 'title' => 'Tweede poging'])
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'Tweede poging');

        $this->assertDatabaseCount('cove_plans', 1);
    }

    #[Test]
    public function approving_can_queue_the_build_for_that_plans_date_not_today(): void
    {
        Queue::fake();

        $date = CarbonImmutable::today()->addDays(5)->toDateString();

        $plan = CovePlan::create([
            'market' => Market::BeNl->value,
            'drop_date' => $date,
            'title' => 'Volgende week',
            'status' => 'draft',
        ]);

        $this->withToken($this->key(ApiToken::abilities()))
            ->postJson("/api/editorial/coves/{$plan->id}/approve", ['build' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('buildQueued', true);

        // The plan's date. Dispatching without one built *today* from a plan
        // written for next week, so the button appeared to do nothing.
        Queue::assertPushed(
            BuildDailyEdition::class,
            fn (BuildDailyEdition $job) => $job->date === $date && $job->market === Market::BeNl,
        );
    }

    #[Test]
    public function an_unapproved_plan_is_not_built(): void
    {
        Queue::fake();

        $plan = CovePlan::create([
            'market' => Market::BeNl->value,
            'drop_date' => CarbonImmutable::today()->toDateString(),
            'title' => 'Nog niet klaar',
            'status' => 'draft',
        ]);

        $this->withToken($this->key(ApiToken::abilities()))
            ->postJson("/api/editorial/coves/{$plan->id}/build")
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    // ── The prose survives the builder ────────────────────────────────────

    #[Test]
    public function authored_prose_is_used_verbatim_and_costs_no_ai(): void
    {
        /*
         * The property that makes writing through the API worth doing. A
         * rebuild is routine — the scheduler retries, a redeploy interrupts, an
         * editor presses the button — so an article that a rebuild replaced
         * with generated copy would be an article you could not rely on.
         */
        $this->seedFinds();
        $pinned = $this->find('Gepinde vondst', 12000, 'gepind', 95);

        $prose = "Eerst dit.\n\nEn dan [[product:{$pinned->id}|de gepinde vondst]].";

        CovePlan::create([
            'market' => Market::BeNl->value,
            'drop_date' => CarbonImmutable::today()->toDateString(),
            'title' => 'Geschreven door een mens',
            'editorial' => $prose,
            'pinned_group_ids' => [$pinned->id],
            'status' => 'approved',
        ]);

        // Fail loudly if anything reaches for a model: an authored Cove must
        // cost nothing, and the AI invariant is the one this feature is most
        // able to erode.
        $this->mock(AiClient::class, function ($mock) {
            $mock->shouldNotReceive('json');
            $mock->shouldReceive('isEnabled')->andReturn(true);
        });

        $edition = app(EditionBuilder::class)->build(Market::BeNl);

        $this->assertNotNull($edition);
        $this->assertSame($prose, $edition->editorial);
        $this->assertSame('planned', $edition->editorial_source);
        $this->assertSame('Geschreven door een mens', $edition->theme_title);

        // Rebuilding the same day updates in place and keeps the prose.
        $again = app(EditionBuilder::class)->build(Market::BeNl);
        $this->assertSame($edition->id, $again->id);
        $this->assertSame($prose, $again->editorial);
    }

    #[Test]
    public function the_edition_read_back_names_the_links_a_reader_will_not_see(): void
    {
        $this->seedFinds();
        $pinned = $this->find('Gepinde vondst', 12000, 'gepind', 95);

        CovePlan::create([
            'market' => Market::BeNl->value,
            'drop_date' => CarbonImmutable::today()->toDateString(),
            'title' => 'Met een kapotte link',
            'editorial' => "Zie [[product:{$pinned->id}|dit]] en [[brand:Verzonnen BV]].",
            'pinned_group_ids' => [$pinned->id],
            'status' => 'approved',
        ]);

        app(EditionBuilder::class)->build(Market::BeNl);

        $date = CarbonImmutable::today()->toDateString();

        $response = $this->withToken($this->key([ApiToken::READ]))
            ->getJson('/api/editorial/editions/'.Market::BeNl->value."/{$date}")
            ->assertOk()
            ->assertJsonPath('data.theme.source', 'planned')
            ->assertJsonPath('data.editorial.source', 'planned');

        // A hallucinated brand degrades to plain text rather than a 404. The
        // reader is fine; the author needs to be told.
        $this->assertContains('brand:Verzonnen BV', $response->json('data.editorial.links.unresolved'));
    }

    // ── Guides ────────────────────────────────────────────────────────────

    #[Test]
    public function a_written_guide_is_a_draft_until_published(): void
    {
        $items = collect(range(1, 4))->map(fn (int $i) => $this->find("Koptelefoon {$i}", 5000 + $i * 1000));

        $response = $this->withToken($this->key([ApiToken::READ, ApiToken::WRITE]))
            ->postJson('/api/editorial/guides', [
                'market' => Market::BeNl->value,
                'title' => 'De beste draadloze koptelefoons',
                'intro' => 'Vier die het waard zijn.',
                'sourceQueries' => ['draadloze koptelefoon'],
                'faq' => [['question' => 'Welke is het stilst?', 'answer' => 'De duurste, meestal.']],
                'items' => $items->map(fn (ProductGroup $g, int $i) => [
                    'groupId' => $g->id,
                    'verdict' => "Beste voor {$i}",
                    'copy' => 'Een zin erover.',
                ])->all(),
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.status', PublishStatus::Draft->value);

        $slug = $response->json('data.slug');

        // Not on the site yet: the public route filters on published.
        $this->get('/'.Market::BeNl->value.'/guides/'.$slug)->assertNotFound();

        $guide = Guide::query()->where('slug', $slug)->firstOrFail();

        // Ranks are array order — position is the argument a "best of" makes.
        $this->assertSame([1, 2, 3, 4], $guide->items()->pluck('rank')->all());
        // Stored as q/a, which is what the FAQ structured data reads.
        $this->assertSame('Welke is het stilst?', $guide->faq[0]['q']);

        $this->withToken($this->key(ApiToken::abilities()))
            ->postJson("/api/editorial/guides/{$guide->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', PublishStatus::Published->value);

        $this->get('/'.Market::BeNl->value.'/guides/'.$slug)->assertOk();
    }

    #[Test]
    public function link_tokens_are_refused_in_guide_copy(): void
    {
        $items = collect(range(1, 3))->map(fn (int $i) => $this->find("Ding {$i}", 5000));

        // Guides render as plain text, so a token would be *printed* to the
        // reader. Refused rather than stripped: the author meant to link, and a
        // silently deleted link is a hole nobody notices until it is indexed.
        $this->withToken($this->key([ApiToken::READ, ApiToken::WRITE]))
            ->postJson('/api/editorial/guides', [
                'market' => Market::BeNl->value,
                'title' => 'Met tokens',
                'intro' => 'Zie [[brand:Sony]].',
                'items' => $items->map(fn (ProductGroup $g) => ['groupId' => $g->id])->all(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');
    }

    #[Test]
    public function a_guide_cannot_list_the_same_product_twice(): void
    {
        $group = $this->find('Eén ding', 5000);
        $other = $this->find('Ander ding', 6000);

        $this->withToken($this->key([ApiToken::READ, ApiToken::WRITE]))
            ->postJson('/api/editorial/guides', [
                'market' => Market::BeNl->value,
                'title' => 'Dubbel',
                'items' => [
                    ['groupId' => $group->id],
                    ['groupId' => $other->id],
                    ['groupId' => $group->id],
                ],
            ])
            ->assertStatus(422);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** @param list<string> $abilities */
    private function key(array $abilities): string
    {
        return ApiToken::issue('test key', $abilities)['token'];
    }

    private function find(
        string $title,
        int $price,
        ?string $category = 'audio',
        float $score = 60,
        Market $market = Market::BeNl,
    ): ProductGroup {
        $group = ProductGroup::create([
            'market' => $market,
            'identity_key' => 'k'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => $title,
            'slug' => 'p-'.bin2hex(random_bytes(3)),
            'category' => $category,
            'image_url' => 'https://img.test/x.jpg',
            'min_price' => $price,
            'merchant_count' => 1,
            'in_stock' => true,
            'giftable' => true,
            'surprise_score' => $score,
        ]);

        Product::create([
            'source' => Source::Awin,
            'market' => $market,
            'merchant_id' => $this->merchant->id,
            'group_id' => $group->id,
            'external_id' => 'e'.bin2hex(random_bytes(5)),
            'title' => $title,
            'price' => $price,
            'currency' => 'EUR',
            'affiliate_url' => 'https://example.test/buy',
            'availability' => Availability::InStock,
            'status' => ProductStatus::Active,
            'identity_key' => $group->identity_key,
        ]);

        return $group;
    }

    private function seedFinds(int $count = 8): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->find("Bijzonder apparaat {$i}", 3000 + $i * 1500, "Categorie{$i}", 90 - $i);
        }
    }
}

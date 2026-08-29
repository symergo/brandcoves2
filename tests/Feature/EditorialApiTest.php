<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\PublishStatus;
use App\Enums\Source;
use App\Jobs\BuildDailyEdition;
use App\Models\ApiToken;
use App\Models\BrandStat;
use App\Models\CovePlan;
use App\Models\DailyPickSet;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Ai\AiClient;
use App\Services\Cove\EditionBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
    public function a_live_bol_product_becomes_writable_through_the_lookup(): void
    {
        /*
         * The catalogue is Awin feeds. bol is queried live and never crawled, so
         * without this an author writing "the four best koptelefoons" was
         * silently writing "the four best koptelefoons that happen to be in an
         * Awin feed" — a limit the article would never admit to.
         *
         * `includeLive` runs the same path a shopper's search does: the offer is
         * ingested and grouped, so what comes back is an ordinary group with an
         * ordinary id, an affiliate URL and a `/go/` redirect.
         */
        /*
         * The suite blanks bol's credentials globally so no test can reach the
         * real API by accident. Turned on here, and only here, against a faked
         * transport — the alternative is a test that proves the connector is
         * disabled.
         */
        config()->set('giftcoves.connectors.bol.enabled', true);
        config()->set('giftcoves.connectors.bol.client_id', 'test-id');
        config()->set('giftcoves.connectors.bol.client_secret', 'test-secret');

        Http::fake([
            'login.bol.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 300]),
            'api.bol.com/*' => Http::response(['results' => [[
                'bolProductId' => '9200000123456',
                'ean' => '4006381333931',
                'title' => 'Sony WH-1000XM5 Koptelefoon',
                'url' => 'https://www.bol.com/nl/p/sony/9200000123456/',
                'image' => ['url' => 'https://media.bol.com/1.jpg'],
                'offer' => ['price' => 329.99],
            ]]]),
        ]);

        $token = $this->key([ApiToken::READ, ApiToken::WRITE]);

        // Without it, the catalogue has nothing to say.
        $this->withToken($token)
            ->getJson('/api/editorial/products?market='.Market::BeNl->value.'&q=koptelefoon')
            ->assertOk()
            ->assertJsonPath('count', 0);

        $response = $this->withToken($token)
            ->getJson('/api/editorial/products?market='.Market::BeNl->value.'&q=koptelefoon&includeLive=1')
            ->assertOk();

        $this->assertSame(1, $response->json('count'));
        $this->assertSame('Sony WH-1000XM5 Koptelefoon', $response->json('data.0.title'));

        // The part that matters: a real id, so it can be pinned and linked like
        // anything else.
        $id = $response->json('data.0.id');
        $this->assertIsInt($id);

        $this->withToken($token)
            ->postJson('/api/editorial/coves', [
                'market' => Market::BeNl->value,
                'title' => 'Koptelefoons',
                'editorial' => "Zie [[product:{$id}|deze]].",
                'pinnedGroupIds' => [$id],
            ])
            ->assertStatus(201)
            ->assertJsonPath('linkCheck.links', 1);

        /*
         * And the stored offer carries the *affiliate* URL, not the plain
         * product one — the connector wraps it in the partner click tracker on
         * the way in.
         *
         * This is the whole reason pulling a live product into the catalogue is
         * worth doing: an author linking to it earns on the click, through the
         * same `/go/` redirector as every Awin offer, without knowing any of
         * that.
         */
        $offer = Product::query()
            ->where('source', Source::Bol->value)
            ->where('external_id', '9200000123456')
            ->firstOrFail();

        $this->assertStringContainsString('partner.bol.com', $offer->affiliate_url);
        $this->assertStringContainsString('9200000123456', $offer->affiliate_url);
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

        $plan = CovePlan::create([
            'market' => Market::BeNl->value,
            'drop_date' => CarbonImmutable::today()->toDateString(),
            'title' => 'Geschreven door een mens',
            'editorial' => $prose,
            'status' => 'approved',
        ]);

        $plan->items()->create(['group_id' => $pinned->id, 'rank' => 1]);

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

        $plan = CovePlan::create([
            'market' => Market::BeNl->value,
            'drop_date' => CarbonImmutable::today()->toDateString(),
            'title' => 'Met een kapotte link',
            'editorial' => "Zie [[product:{$pinned->id}|dit]] en [[brand:Verzonnen BV]].",
            'status' => 'approved',
        ]);

        // Curated, so the product the prose links to is certainly in the
        // edition — a token for something the engine did not pick renders as
        // plain text, which is a different assertion from the one below.
        $plan->items()->create(['group_id' => $pinned->id, 'rank' => 1]);

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

        $guide = DailyPickSet::query()->articles()->where('slug', $slug)->firstOrFail();

        // Ranks are array order — position is the argument a "best of" makes.
        $this->assertSame([1, 2, 3, 4], $guide->picks()->pluck('rank')->all());
        // Stored as q/a, which is what the FAQ structured data reads.
        $this->assertSame('Welke is het stilst?', $guide->faq[0]['q']);

        $this->withToken($this->key(ApiToken::abilities()))
            ->postJson("/api/editorial/guides/{$guide->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', PublishStatus::Published->value);

        $this->get('/'.Market::BeNl->value.'/guides/'.$slug)->assertOk();
    }

    #[Test]
    public function guide_copy_carries_links_and_reports_the_ones_that_will_not_resolve(): void
    {
        $items = collect(range(1, 3))->map(fn (int $i) => $this->find("Ding {$i}", 5000));
        $first = $items->first();

        $existing = $this->publishedGuide('beste-koptelefoons', 'De beste koptelefoons');

        $response = $this->withToken($this->key([ApiToken::READ, ApiToken::WRITE]))
            ->postJson('/api/editorial/guides', [
                'market' => Market::BeNl->value,
                'title' => 'Met links',
                'intro' => "Zie ook [[guide:{$existing->slug}|onze koptelefoongids]] en [[page:search]].",
                'bodyMd' => "Begin bij [[product:{$first->id}|dit ding]].\n\n".
                    'Niet bij [[guide:bestaat-niet]] of [[page:verzonnen]].',
                'items' => $items->map(fn (ProductGroup $g) => ['groupId' => $g->id])->all(),
            ])
            ->assertStatus(201);

        // Three real destinations: another guide, a site page, and a product.
        // The pair that make an article part of a site rather than a leaf are
        // the first two.
        $this->assertSame(3, $response->json('linkCheck.links'));

        $unresolved = $response->json('linkCheck.unresolved');
        $this->assertContains('guide:bestaat-niet', $unresolved);
        $this->assertContains('page:verzonnen', $unresolved);
    }

    #[Test]
    public function an_advice_article_needs_no_products_at_all(): void
    {
        $token = $this->key(ApiToken::abilities());

        $response = $this->withToken($token)
            ->postJson('/api/editorial/guides', [
                'market' => Market::BeNl->value,
                'kind' => 'advice',
                'title' => 'Veilig kopen op Amazon',
                'intro' => 'Waar je op let voordat je klikt.',
                'bodyMd' => "Kijk eerst wie de verkoper is.\n\nEn vergelijk via [[page:search]].",
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.kind', 'advice')
            ->assertJsonPath('data.items', []);

        $slug = $response->json('data.slug');

        $this->withToken($token)
            ->postJson('/api/editorial/guides/'.$response->json('data.id').'/publish')
            ->assertOk();

        // And it is a real page. An advice article is the most indexable thing
        // the site publishes, so "no products" must not mean "no page".
        $this->get('/'.Market::BeNl->value.'/guides/'.$slug)->assertOk();
    }

    #[Test]
    public function an_advice_article_with_no_products_can_still_link_to_a_brand_and_a_search(): void
    {
        /*
         * The allowlist used to be derived from the article's own products, so
         * an advice piece — which has none — could link to nothing at all. "How
         * to shop for headphones" that cannot point at the headphone search or
         * at Sony is an article with nowhere to send anyone, which is the one
         * job advice has.
         */
        BrandStat::create([
            'market' => Market::BeNl->value,
            'brand' => 'Sony',
            'slug' => 'sony',
            'aliases' => ['Sony'],
            'product_count' => 12,
        ]);

        $response = $this->withToken($this->key([ApiToken::READ, ApiToken::WRITE]))
            ->postJson('/api/editorial/guides', [
                'market' => Market::BeNl->value,
                'kind' => 'advice',
                'title' => 'Hoe je een koptelefoon kiest',
                // The queries that say what the piece is about are also the
                // searches it may link to — no extra field to learn.
                'sourceQueries' => ['koptelefoon'],
                'bodyMd' => 'Begin bij [[search:koptelefoon|onze koptelefoons]] en kijk naar '.
                    '[[brand:Sony]]. Niet naar [[brand:Verzonnen BV]].',
            ])
            ->assertStatus(201);

        $this->assertSame(2, $response->json('linkCheck.links'));
        // A brand with no page in this market is still refused — the allowlist
        // widened to real brand pages, not to every string.
        $this->assertSame(['brand:Verzonnen BV'], $response->json('linkCheck.unresolved'));
    }

    #[Test]
    public function a_buying_guide_still_needs_a_shortlist(): void
    {
        // The floor is per kind, not global. Dropping it for everything would
        // let a two-item "best of" through, which is the thin page the rule
        // exists to prevent.
        $this->withToken($this->key([ApiToken::READ, ApiToken::WRITE]))
            ->postJson('/api/editorial/guides', [
                'market' => Market::BeNl->value,
                'kind' => 'buying',
                'title' => 'De beste van niets',
                'items' => [],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');
    }

    #[Test]
    public function a_published_guide_renders_its_links_as_anchors(): void
    {
        $items = collect(range(1, 3))->map(fn (int $i) => $this->find("Ding {$i}", 5000));

        $target = $this->publishedGuide('doelgids', 'De doelgids');

        $token = $this->key(ApiToken::abilities());

        $created = $this->withToken($token)
            ->postJson('/api/editorial/guides', [
                'market' => Market::BeNl->value,
                'title' => 'Met echte ankers',
                'intro' => "Lees ook [[guide:{$target->slug}|de doelgids]], of ga naar [[page:gift-whisperer|de cadeauzoeker]].",
                'items' => $items->map(fn (ProductGroup $g) => ['groupId' => $g->id])->all(),
            ])
            ->assertStatus(201);

        $this->withToken($token)
            ->postJson('/api/editorial/guides/'.$created->json('data.id').'/publish')
            ->assertOk();

        // The end of the loop the write-time linkCheck only predicts: the
        // anchors have to exist in what a reader is actually served.
        $this->get('/'.Market::BeNl->value.'/guides/'.$created->json('data.slug'))
            ->assertOk()
            ->assertInertia(function ($page) {
                $intro = implode(' ', $page->toArray()['props']['guide']['intro']);

                $this->assertStringContainsString('<a href="/be-nl/guides/doelgids">de doelgids</a>', $intro);
                // From config('giftcoves.linkable_pages'), never a path the
                // writer invented.
                $this->assertStringContainsString('<a href="/be-nl/gift">de cadeauzoeker</a>', $intro);
            });
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

    /**
     * A published guide at a known slug, for a `[[guide:…]]` token to resolve to.
     *
     * An edition since the fold — the whole `/guides` space is `daily_pick_sets`
     * rows now, and the link allowlist reads them there.
     */
    private function publishedGuide(string $slug, string $title): DailyPickSet
    {
        return DailyPickSet::create([
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Guide->value,
            'slug' => $slug,
            'theme_title' => $title,
            'theme_slug' => $slug,
            'status' => PublishStatus::Published->value,
            'published_at' => now(),
        ]);
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

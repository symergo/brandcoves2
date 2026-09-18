<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PlanWriter;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\AiUsage;
use App\Models\ApiToken;
use App\Models\CovePlan;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Cove\EditionBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The writing queue: ask what needs writing, write it, post it back.
 *
 * Built for a scheduled agent, so the properties that matter are the ones that
 * hold when nobody is watching: it never hands out the same Cove twice, it
 * never hands out one the built-in writer is already writing, it cannot be used
 * to empty a curated shortlist, two overlapping runs cannot overwrite each
 * other, and nothing it writes reaches a reader without a person approving it.
 */
class CoveQueueApiTest extends TestCase
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
    public function the_queue_hands_out_what_needs_writing(): void
    {
        $plan = $this->plan();
        $plan->items()->create([
            'group_id' => $this->find('Reiskoptelefoon')->id,
            'rank' => 1,
            'note' => 'Vouwt plat in een rugzak.',
        ]);

        $response = $this->withToken($this->key([ApiToken::READ]))
            ->getJson('/api/editorial/coves/queue?market='.Market::BeNl->value)
            ->assertOk()
            ->assertJsonPath('count', 1);

        $brief = $response->json('data.0');

        $this->assertSame('nl', $brief['language']);
        $this->assertSame('Nadruk op reizen.', $brief['buildInstructions']);

        // The curator's note is the reason the product is on the list, and the
        // one sentence a search result could never have supplied.
        $this->assertSame('Vouwt plat in een rugzak.', $brief['items'][0]['note']);

        /*
         * And the allowlist, without a second call. A writer that has to guess
         * its tokens fails linkCheck and burns a round trip on every Cove.
         */
        $this->assertArrayHasKey('products', $brief['allowlist']);
        $this->assertNotEmpty($brief['revision']);
    }

    #[Test]
    public function a_cove_that_already_has_prose_is_not_offered_again(): void
    {
        $this->plan()->update(['editorial' => 'Dit is al geschreven.']);

        /*
         * What stops the same Cove being handed out on every run — without a
         * "claimed" status that a crashed agent would leave set forever.
         */
        $this->withToken($this->key([ApiToken::READ]))
            ->getJson('/api/editorial/coves/queue')
            ->assertOk()
            ->assertJsonPath('count', 0);
    }

    #[Test]
    public function a_plan_the_builder_is_meant_to_write_is_not_offered(): void
    {
        /*
         * Two writers, one plan. The automation's write stage claims every
         * `builder` plan, so offering one here as well put an outside agent and
         * the built-in writer on the same article: both produce something
         * plausible, whoever finishes last wins, and nothing anywhere reports
         * that the other one's work was thrown away.
         */
        $this->plan(['writer' => PlanWriter::Builder->value]);

        $this->withToken($this->key([ApiToken::READ]))
            ->getJson('/api/editorial/coves/queue')
            ->assertOk()
            ->assertJsonPath('count', 0);
    }

    #[Test]
    public function a_body_writing_kind_whose_body_is_written_is_not_offered_again(): void
    {
        /*
         * A guide, seasonal, advice, shop or brand Cove carries its prose in
         * `body`; only a Daily and a persona write an `editorial`. Asking about
         * `editorial` alone therefore handed a finished guide back on every
         * run, for as long as it sat waiting to be approved.
         */
        $this->plan([
            'kind' => CoveKind::Guide->value,
            'drop_date' => null,
            'slug' => 'beste-koptelefoons',
            'body' => 'Het stuk is af.',
        ]);

        $this->withToken($this->key([ApiToken::READ]))
            ->getJson('/api/editorial/coves/queue')
            ->assertOk()
            ->assertJsonPath('count', 0);
    }

    #[Test]
    public function a_plan_marked_for_an_outside_writer_with_no_prose_is_offered(): void
    {
        // The queue's whole job, and the reason the two tests above are not
        // enough on their own: narrowing it to what is really waiting must not
        // narrow it to nothing.
        $this->plan([
            'kind' => CoveKind::Guide->value,
            'drop_date' => null,
            'slug' => 'nog-te-schrijven',
        ]);

        $this->withToken($this->key([ApiToken::READ]))
            ->getJson('/api/editorial/coves/queue')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.slug', 'nog-te-schrijven');
    }

    #[Test]
    public function prose_comes_back_and_the_shortlist_is_untouched(): void
    {
        $plan = $this->plan();
        $first = $plan->items()->create(['group_id' => $this->find('Een')->id, 'rank' => 1, 'note' => 'Waarom.']);
        $plan->items()->create(['group_id' => $this->find('Twee')->id, 'rank' => 2]);

        $before = $plan->items()->orderBy('rank')->pluck('group_id')->all();
        $revision = $this->revision($plan);

        $this->withToken($this->key([ApiToken::READ, ApiToken::WRITE]))
            ->postJson("/api/editorial/coves/{$plan->id}/editorial", [
                'revision' => $revision,
                'editorial' => 'Twee korte alinea. En nog een.',
                'items' => [['id' => $first->id, 'verdict' => 'Beste voor de trein']],
            ])
            ->assertOk();

        $plan->refresh();

        $this->assertSame('Twee korte alinea. En nog een.', $plan->editorial);
        $this->assertSame('Beste voor de trein', $first->fresh()->verdict);

        /*
         * The reason this endpoint exists instead of POST /coves: that one
         * replaces the item list wholesale, so an agent sending only words would
         * empty a shortlist somebody spent an afternoon on.
         */
        $this->assertSame($before, $plan->items()->orderBy('rank')->pluck('group_id')->all());
    }

    #[Test]
    public function an_item_from_another_plan_is_refused(): void
    {
        $plan = $this->plan();
        $other = CovePlan::create([
            'market' => Market::BeNl->value,
            'drop_date' => CarbonImmutable::tomorrow()->toDateString(),
            'title' => 'Ergens anders',
            'status' => 'draft',
        ]);
        $stranger = $other->items()->create(['group_id' => $this->find('Een')->id, 'rank' => 1]);
        $revision = $this->revision($plan);

        // A 422 rather than a quiet skip: an id from another plan means the
        // writer is working from a stale brief, and the rest is suspect too.
        $this->withToken($this->key([ApiToken::READ, ApiToken::WRITE]))
            ->postJson("/api/editorial/coves/{$plan->id}/editorial", [
                'revision' => $revision,
                'items' => [['id' => $stranger->id, 'verdict' => 'Nee']],
            ])
            ->assertStatus(422);

        $this->assertNull($stranger->fresh()->verdict);
    }

    #[Test]
    public function a_stale_revision_is_a_conflict_and_changes_nothing(): void
    {
        $plan = $this->plan();
        $stale = $this->revision($plan);

        // Somebody curated while the agent was writing.
        $this->travel(1)->minute();
        $plan->update(['title' => 'Iemand heeft dit herschreven']);

        $this->withToken($this->key([ApiToken::READ, ApiToken::WRITE]))
            ->postJson("/api/editorial/coves/{$plan->id}/editorial", [
                'revision' => $stale,
                'editorial' => 'Geschreven tegen de oude briefing.',
            ])
            ->assertStatus(409)
            // The current state comes back, so the agent can start that Cove
            // again rather than guess what changed.
            ->assertJsonPath('data.title', 'Iemand heeft dit herschreven');

        $this->assertNull($plan->fresh()->editorial);
    }

    #[Test]
    public function a_written_cove_stays_a_draft(): void
    {
        $plan = $this->plan();
        $revision = $this->revision($plan);

        $this->withToken($this->key([ApiToken::READ, ApiToken::WRITE]))
            ->postJson("/api/editorial/coves/{$plan->id}/editorial", [
                'revision' => $revision,
                'editorial' => 'Klaar om na te lezen.',
            ])
            ->assertOk();

        /*
         * The safety model in one assertion. An agent writes; a person approves.
         * Only approved plans are built, so nothing written here can reach a
         * reader on its own.
         */
        $this->assertSame('draft', $plan->fresh()->status);
    }

    #[Test]
    public function a_write_key_cannot_reach_the_queue_without_read(): void
    {
        $this->withToken($this->key([ApiToken::WRITE]))
            ->getJson('/api/editorial/coves/queue')
            ->assertStatus(403);
    }

    #[Test]
    public function an_agent_written_cove_costs_nothing_to_publish(): void
    {
        $this->find('Een');
        $this->find('Twee');
        $this->find('Drie');
        $this->find('Vier');

        $plan = $this->plan();
        $revision = $this->revision($plan);

        $this->withToken($this->key([ApiToken::READ, ApiToken::WRITE]))
            ->postJson("/api/editorial/coves/{$plan->id}/editorial", [
                'revision' => $revision,
                'editorial' => 'Door een mens geschreven.',
            ])
            ->assertOk();

        $plan->update(['status' => 'approved']);

        config(['giftcoves.ai.enabled' => true, 'giftcoves.ai.api_key' => 'sk-ant-test']);

        $edition = app(EditionBuilder::class)->build(Market::BeNl);

        /*
         * The strategic property of the whole feature: authored prose
         * short-circuits the model, so a Cove written through this API is not
         * subject to the daily cap and spends nothing on this server.
         */
        $this->assertSame('Door een mens geschreven.', $edition->editorial);
        $this->assertSame('planned', $edition->editorial_source);
        $this->assertSame(0, AiUsage::query()->count());
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * The revision the queue would hand out for this plan.
     *
     * Called *before* a write token is applied: it sets its own bearer header,
     * which would otherwise downgrade the request that follows to read-only.
     */
    private function revision(CovePlan $plan): string
    {
        return $this->withToken($this->key([ApiToken::READ]))
            ->getJson('/api/editorial/coves/queue')
            ->json('data.0.revision');
    }

    /**
     * A plan waiting on an outside writer.
     *
     * `writer` is `authored` because that is the state the queue lists, and it
     * is what *Operations > Automation* writes when a market and kind are set
     * to `write: external`. A plan left on the default, `builder`, belongs to
     * the built-in writer and is deliberately not offered here.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function plan(array $attributes = []): CovePlan
    {
        return CovePlan::create([
            'market' => Market::BeNl->value,
            'drop_date' => CarbonImmutable::today()->toDateString(),
            'title' => 'Vondsten voor thuiswerkers',
            'build_instructions' => 'Nadruk op reizen.',
            'status' => 'draft',
            'writer' => PlanWriter::Authored->value,
            ...$attributes,
        ]);
    }

    /** @param list<string> $abilities */
    private function key(array $abilities): string
    {
        return ApiToken::issue('claude', $abilities)['token'];
    }

    private function find(string $title): ProductGroup
    {
        $group = ProductGroup::create([
            'market' => Market::BeNl,
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
            'surprise_score' => 60,
        ]);

        Product::create([
            'source' => Source::Awin,
            'market' => Market::BeNl,
            'merchant_id' => $this->merchant->id,
            'group_id' => $group->id,
            'external_id' => 'e'.bin2hex(random_bytes(5)),
            'title' => $title,
            'price' => 4900,
            'currency' => 'EUR',
            'affiliate_url' => 'https://example.test/buy',
            'availability' => Availability::InStock,
            'status' => ProductStatus::Active,
            'identity_key' => $group->identity_key,
        ]);

        return $group;
    }
}

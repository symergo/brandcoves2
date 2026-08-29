<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Jobs\BuildCove;
use App\Jobs\BuildDailyEdition;
use App\Models\ApiToken;
use App\Models\CovePlan;
use App\Models\GuideTopic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The planner, driven from outside: ask for ideas, write every kind, build it.
 *
 * Three things this pins, all of which were broken or missing and all of which
 * fail silently rather than loudly:
 *
 *  - an agent can ask for N ideas instead of inventing titles, and is told in a
 *    sentence when the source runs out rather than being handed a bare zero it
 *    will retry forever;
 *  - a plan of any kind can be written through `POST /coves`. It used to know
 *    two kinds, and every kind added since was stored silently as a Daily —
 *    with a date, with no slug, and answering 201;
 *  - approving and building an article actually queues a build. `queueBuild`
 *    named the two jobs it knew about, so a guide answered 202 and nothing ever
 *    ran.
 */
class CoveDraftApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_agent_can_ask_the_planner_for_ideas(): void
    {
        $response = $this->withToken($this->key([ApiToken::READ, ApiToken::WRITE]))
            ->postJson('/api/editorial/coves/drafts', [
                'market' => Market::BeNl->value,
                'kind' => CoveKind::Persona->value,
                'count' => 3,
                'withProducts' => false,
            ])
            ->assertCreated()
            ->assertJsonPath('count', 3);

        // Drafts, every one. A writing key may make work; it may not publish it.
        foreach ($response->json('data') as $plan) {
            $this->assertSame('draft', $plan['status']);
            $this->assertSame(CoveKind::Persona->value, $plan['kind']);
            $this->assertNotEmpty($plan['slug']);

            // The product words the wizard already knows for that interest —
            // the part a writer would otherwise have to guess at in Dutch.
            $this->assertNotEmpty($plan['queries']);
        }
    }

    #[Test]
    public function running_out_of_ideas_is_a_sentence_not_a_zero(): void
    {
        GuideTopic::create([
            'market' => Market::BeNl->value,
            'topic' => 'koptelefoon',
            'member_queries' => ['koptelefoon'],
            'search_volume' => 42,
            'available_products' => 8,
            'origin' => 'search',
            'status' => 'candidate',
        ]);

        $this->withToken($this->key([ApiToken::WRITE]))
            ->postJson('/api/editorial/coves/drafts', [
                'market' => Market::BeNl->value,
                'kind' => CoveKind::Guide->value,
                'count' => 10,
                'withProducts' => false,
            ])
            ->assertCreated()
            ->assertJsonPath('count', 1)
            /*
             * The difference between "the queue is exhausted, stop asking" and
             * "the request failed, retry". A scheduled caller cannot tell those
             * apart from a count alone, and one of them is an infinite loop.
             */
            ->assertJsonFragment(['shortfall' => 'The topic queue for be-nl has no more unplanned search topics. '
                .'Mine more with bc:refresh-discovery and bc:pull-charts, or add one by hand under Cove topics.']);
    }

    #[Test]
    public function a_kind_with_no_source_is_refused_with_the_reason(): void
    {
        $this->withToken($this->key([ApiToken::WRITE]))
            ->postJson('/api/editorial/coves/drafts', [
                'market' => Market::BeNl->value,
                'kind' => CoveKind::Advice->value,
                'count' => 5,
            ])
            // 422 rather than an empty 200: an agent told why moves on and
            // writes the titles itself, which is the correct behaviour.
            ->assertStatus(422)
            ->assertJsonValidationErrors('kind');

        $this->assertSame(0, CovePlan::query()->count());
    }

    #[Test]
    public function drafting_needs_a_key_that_may_write(): void
    {
        $this->withToken($this->key([ApiToken::READ]))
            ->postJson('/api/editorial/coves/drafts', [
                'market' => Market::BeNl->value,
                'kind' => CoveKind::Persona->value,
                'count' => 1,
            ])
            ->assertForbidden();
    }

    #[Test]
    public function a_guide_can_be_written_through_the_api_and_keeps_its_address(): void
    {
        $response = $this->withToken($this->key([ApiToken::WRITE]))
            ->postJson('/api/editorial/coves', [
                'market' => Market::BeNl->value,
                'kind' => CoveKind::Guide->value,
                'slug' => 'beste-koptelefoon',
                'title' => 'De beste koptelefoons',
                'blurb' => 'Wat je koopt als je goed wilt horen.',
                'focusKeyphrase' => 'beste koptelefoon',
                'metaDescription' => 'De koptelefoons die het waard zijn.',
                'body' => 'Let eerst op pasvorm.',
                'faq' => [['question' => 'Bluetooth of kabel?', 'answer' => 'Kabel klinkt beter.']],
            ])
            ->assertCreated();

        $plan = CovePlan::query()->firstOrFail();

        /*
         * The whole bug in one assertion. Every kind but Daily and Persona used
         * to land here with a null slug and a date — addressed as a Daily,
         * reported as a guide.
         */
        $this->assertSame(CoveKind::Guide, $plan->kind);
        $this->assertSame('beste-koptelefoon', $plan->slug);
        $this->assertNull($plan->drop_date);

        // The parts of an article a person decides before it is written, which
        // a guide never had a way to receive.
        $this->assertSame('beste koptelefoon', $plan->focus_keyphrase);
        // Equals, not same: the column is jsonb and Postgres stores object keys
        // in its own order, so `q` and `a` come back sorted.
        $this->assertEquals([['q' => 'Bluetooth of kabel?', 'a' => 'Kabel klinkt beter.']], $plan->faq);

        $response->assertJsonPath('data.focusKeyphrase', 'beste koptelefoon');
    }

    #[Test]
    public function a_dateless_kind_without_a_slug_is_refused(): void
    {
        $this->withToken($this->key([ApiToken::WRITE]))
            ->postJson('/api/editorial/coves', [
                'market' => Market::BeNl->value,
                'kind' => CoveKind::Guide->value,
                'title' => 'De beste koptelefoons',
            ])
            // Refused rather than reconciled: keeping it dateless and unaddressed
            // would store a page with no URL, and dating it would hand the
            // morning build a guide.
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');
    }

    #[Test]
    public function a_slug_another_kind_already_holds_is_refused(): void
    {
        CovePlan::create([
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Persona->value,
            'slug' => 'de-audiofiel',
            'title' => 'De audiofiel',
            'status' => 'approved',
        ]);

        $this->withToken($this->key([ApiToken::WRITE, ApiToken::PUBLISH]))
            ->postJson('/api/editorial/coves', [
                'market' => Market::BeNl->value,
                'kind' => CoveKind::Guide->value,
                'slug' => 'de-audiofiel',
                'title' => 'De beste koptelefoons',
            ])
            /*
             * One slug namespace per market covers every kind, so the upsert
             * would have found the persona and silently changed what that page
             * *is* — its URL space, its layout and its product floor at once.
             */
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');

        $this->assertSame(CoveKind::Persona, CovePlan::query()->firstOrFail()->kind);
    }

    #[Test]
    public function an_article_field_sent_with_a_column_is_refused_not_dropped(): void
    {
        $this->withToken($this->key([ApiToken::WRITE]))
            ->postJson('/api/editorial/coves', [
                'market' => Market::BeNl->value,
                'kind' => CoveKind::Persona->value,
                'slug' => 'de-audiofiel',
                'title' => 'De audiofiel',
                'faq' => [['question' => 'Waarom?', 'answer' => 'Daarom.']],
            ])
            // An author who gets a 200 has every reason to believe the FAQ was
            // stored, and finds out when the page renders without one.
            ->assertStatus(422)
            ->assertJsonValidationErrors('faq');
    }

    #[Test]
    public function approving_a_guide_queues_a_build_that_knows_it_is_a_guide(): void
    {
        Queue::fake();

        $plan = CovePlan::create([
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Guide->value,
            'slug' => 'beste-koptelefoon',
            'title' => 'De beste koptelefoons',
            'status' => 'draft',
        ]);

        $this->withToken($this->key([ApiToken::PUBLISH]))
            ->postJson("/api/editorial/coves/{$plan->id}/approve", ['build' => true])
            ->assertOk()
            ->assertJsonPath('buildQueued', true);

        /*
         * `BuildCove` reads the kind off the plan. The version this replaced
         * named `BuildPersonaCove` and `BuildDailyEdition` individually, so a
         * guide fell through to the Daily arm, found no date, and answered 202
         * having queued nothing at all.
         */
        Queue::assertPushed(BuildCove::class, fn (BuildCove $job) => $job->planId === $plan->id);
        Queue::assertNotPushed(BuildDailyEdition::class);
    }

    #[Test]
    public function building_a_guide_reads_back_at_its_own_url(): void
    {
        Queue::fake();

        $plan = CovePlan::create([
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Guide->value,
            'slug' => 'beste-koptelefoon',
            'title' => 'De beste koptelefoons',
            'status' => 'approved',
        ]);

        $this->withToken($this->key([ApiToken::PUBLISH]))
            ->postJson("/api/editorial/coves/{$plan->id}/build")
            ->assertStatus(202)
            // Off the kind, not off the date. A null date used to send every
            // permanent page's read-back URL into /gift-ideas.
            ->assertJsonPath('readBack', '/be-nl/guides/beste-koptelefoon');
    }

    #[Test]
    public function the_calendar_can_be_asked_for_one_kind(): void
    {
        foreach ([CoveKind::Guide, CoveKind::Persona] as $i => $kind) {
            CovePlan::create([
                'market' => Market::BeNl->value,
                'kind' => $kind->value,
                'slug' => 'p-'.$i,
                'title' => 'Iets',
                'status' => 'draft',
            ]);
        }

        $this->withToken($this->key([ApiToken::READ]))
            ->getJson('/api/editorial/coves?market='.Market::BeNl->value.'&kind='.CoveKind::Guide->value)
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.kind', CoveKind::Guide->value);
    }

    /** @param list<string> $abilities */
    private function key(array $abilities): string
    {
        return ApiToken::issue('claude', $abilities)['token'];
    }
}

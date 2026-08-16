<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ModerationStatus;
use App\Jobs\TriageCommunityPost;
use App\Models\CommunityAnswer;
use App\Models\CommunityQuestion;
use App\Services\Ai\AiClient;
use App\Services\Community\PostScreen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The one thing that can put a stranger's writing on a public page.
 *
 * Every test here is really the same assertion from a different angle: the only
 * path to `published` is an explicit "this is fine". Everything else — the model
 * switched off, the API down, a malformed reply, a flat rule tripping — leaves
 * the row exactly as it was created, which is unpublished.
 */
class CommunityTriageTest extends TestCase
{
    use RefreshDatabase;

    private function reply(string $verdict, string $reason = 'ok'): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode(['verdict' => $verdict, 'reason' => $reason]),
                ]],
            ]),
        ]);
    }

    private function withAi(): void
    {
        config()->set('giftcoves.ai.enabled', true);
        config()->set('giftcoves.ai.api_key', 'test-key');
    }

    private function triage(CommunityQuestion|CommunityAnswer $post): void
    {
        /*
         * Run the job's body directly rather than through the queue.
         *
         * `AiClient` refuses to run outside a console process — that check is
         * the AI invariant and it is deliberately coarse — and the test runner
         * *is* a console process, so this exercises the real client with a
         * faked HTTP layer rather than a mock of our own code.
         */
        TriageCommunityPost::for($post)->handle(app(AiClient::class), app(PostScreen::class));
    }

    #[Test]
    public function with_ai_switched_off_nothing_publishes_itself(): void
    {
        // The documented fallback: the admin queue is then the whole moderation
        // system, and the feature still works.
        config()->set('giftcoves.ai.enabled', false);

        $question = CommunityQuestion::factory()->create();
        $this->triage($question);

        $this->assertSame(ModerationStatus::Pending, $question->fresh()->status);
    }

    #[Test]
    public function a_clean_question_is_published(): void
    {
        $this->withAi();
        $this->reply('publish');

        $question = CommunityQuestion::factory()->create([
            'title' => 'What do I get my sister for her thirtieth?',
            'body' => 'She climbs and already owns most of the gear.',
        ]);

        $this->triage($question);

        $fresh = $question->fresh();
        $this->assertSame(ModerationStatus::Published, $fresh->status);
        // The CHECK constraint insists the two move together.
        $this->assertNotNull($fresh->published_at);
    }

    #[Test]
    public function advertising_is_refused_with_its_reason(): void
    {
        $this->withAi();
        $this->reply('refuse', 'advertising');

        $question = CommunityQuestion::factory()->create();
        $this->triage($question);

        $fresh = $question->fresh();
        $this->assertSame(ModerationStatus::Rejected, $fresh->status);
        $this->assertSame('advertising', $fresh->moderation_note);
        $this->assertNull($fresh->published_at);
    }

    #[Test]
    public function an_unsure_verdict_waits_for_a_human(): void
    {
        $this->withAi();
        $this->reply('hold', 'unsure');

        $question = CommunityQuestion::factory()->create();
        $this->triage($question);

        $this->assertSame(ModerationStatus::Pending, $question->fresh()->status);
    }

    #[Test]
    public function a_verdict_the_model_invented_is_treated_as_a_hold(): void
    {
        // Anything that is not an explicit "publish" leaves the row alone.
        $this->withAi();
        $this->reply('probably fine?', 'who knows');

        $question = CommunityQuestion::factory()->create();
        $this->triage($question);

        $this->assertSame(ModerationStatus::Pending, $question->fresh()->status);
    }

    #[Test]
    public function the_api_being_down_leaves_the_post_in_the_queue(): void
    {
        $this->withAi();
        Http::fake(['api.anthropic.com/*' => Http::response('nope', 500)]);

        $question = CommunityQuestion::factory()->create();
        $this->triage($question);

        $this->assertSame(ModerationStatus::Pending, $question->fresh()->status);
    }

    #[Test]
    public function a_link_is_held_without_asking_the_model(): void
    {
        /*
         * The flat rules run first and cost nothing. Answers carry products as
         * rows rather than links, so a URL in the prose has no legitimate use
         * on this surface at all.
         */
        $this->withAi();
        Http::fake(['api.anthropic.com/*' => Http::response([], 500)]);

        $question = CommunityQuestion::factory()->create([
            'title' => 'Great deals over at example.com right now',
            'body' => 'Go and look at www.example.com',
        ]);

        $this->triage($question);

        $fresh = $question->fresh();
        $this->assertSame(ModerationStatus::Pending, $fresh->status);
        $this->assertSame('link', $fresh->moderation_note);

        // And the model was never asked, so a link-stuffer cannot spend our
        // budget by posting.
        Http::assertNothingSent();
    }

    #[Test]
    public function an_email_address_is_held(): void
    {
        $this->withAi();

        $question = CommunityQuestion::factory()->create([
            'title' => 'Message me for cheap perfume',
            'body' => 'reach me at seller@example.com',
        ]);

        $this->triage($question);

        $this->assertSame('contact', $question->fresh()->moderation_note);
    }

    #[Test]
    public function a_decision_already_taken_by_a_human_is_not_overridden(): void
    {
        // The admin looked at it. A job that ran late must not undo that.
        $this->withAi();
        $this->reply('publish');

        $question = CommunityQuestion::factory()->rejected()->create();
        $this->triage($question);

        $this->assertSame(ModerationStatus::Rejected, $question->fresh()->status);
    }

    #[Test]
    public function publishing_an_answer_moves_the_boards_counter(): void
    {
        $this->withAi();
        $this->reply('publish');

        $question = CommunityQuestion::factory()->published()->create();
        $answer = CommunityAnswer::factory()->create(['question_id' => $question->id]);

        $this->triage($answer);

        $this->assertSame(ModerationStatus::Published, $answer->fresh()->status);
        $this->assertSame(1, $question->fresh()->answers_count);
    }
}

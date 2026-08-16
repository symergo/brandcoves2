<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Enums\ModerationStatus;
use App\Enums\Vibe;
use App\Jobs\TriageCommunityPost;
use App\Models\CommunityAnswer;
use App\Models\CommunityQuestion;
use App\Models\ProductGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ask others: the board, and the rule that nothing publishes itself.
 *
 * This is the first surface on the site that shows one visitor's writing to
 * another, so most of what is worth testing is about what does *not* appear.
 * The load-bearing assertion is {@see a_new_question_does_not_appear_on_the_board}:
 * every other guard here can be got right while that one is broken, and if it is
 * broken the feature is a way to publish anything on our own domain.
 */
class AskOthersTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function props(TestResponse $response): array
    {
        return $response->viewData('page')['props'];
    }

    // --- Posting -------------------------------------------------------------

    #[Test]
    public function asking_requires_an_account(): void
    {
        // Reading is open; writing in public is not. A post needs a person
        // behind it — somewhere to send a reply, and something to lose.
        $this->post('/be-nl/ask', ['title' => 'What do I buy my sister?'])
            ->assertRedirect();

        $this->assertSame(0, CommunityQuestion::query()->count());
    }

    #[Test]
    public function a_new_question_does_not_appear_on_the_board(): void
    {
        /*
         * The rule the whole feature rests on. A controller cannot publish;
         * only `TriageCommunityPost` can, and it is a queued job. If this ever
         * fails, the board is an open publishing endpoint on our own domain.
         */
        Queue::fake();

        $this->actingAs(User::factory()->create())
            ->post('/be-nl/ask', ['title' => 'What do I buy my sister for her 30th?'])
            ->assertRedirect();

        $question = CommunityQuestion::query()->firstOrFail();

        $this->assertSame(ModerationStatus::Pending, $question->status);
        $this->assertNull($question->published_at);

        // And it is not on the public board.
        $this->assertSame([], $this->props($this->get('/be-nl/ask'))['questions']);
    }

    #[Test]
    public function posting_a_question_queues_the_triage_job(): void
    {
        Queue::fake();

        $this->actingAs(User::factory()->create())
            ->post('/be-nl/ask', ['title' => 'Ideas for a colleague leaving after ten years?']);

        Queue::assertPushed(TriageCommunityPost::class);
    }

    #[Test]
    public function a_question_needs_more_than_a_couple_of_words(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/be-nl/ask', ['title' => 'help'])
            ->assertSessionHasErrors('title');
    }

    #[Test]
    public function a_budget_arrives_in_euros_and_is_stored_in_cents(): void
    {
        Queue::fake();

        $this->actingAs(User::factory()->create())->post('/be-nl/ask', [
            'title' => 'Something for my father who fishes',
            'budget_max' => '45.50',
        ]);

        // Invariant #7 — the same unit as every other price on the site.
        $this->assertSame(4550, CommunityQuestion::query()->firstOrFail()->budget_max);
    }

    #[Test]
    public function the_optional_fields_are_optional(): void
    {
        /*
         * The question is the whole of what is required. Somebody who types one
         * sentence and presses Ask must get a question on the board — the
         * structure is an accelerator, never a form to complete.
         */
        Queue::fake();

        $this->actingAs(User::factory()->create())
            ->post('/be-nl/ask', ['title' => 'What do I get somebody who has everything?'])
            ->assertRedirect();

        $question = CommunityQuestion::query()->firstOrFail();

        $this->assertNull($question->interests);
        $this->assertNull($question->vibe);
        $this->assertNull($question->values);
        $this->assertSame([], $question->tags());
    }

    #[Test]
    public function a_question_can_describe_the_person(): void
    {
        Queue::fake();

        $this->actingAs(User::factory()->create())->post('/be-nl/ask', [
            'title' => 'Something for my sister who is into climbing',
            'interests' => ['coffee', 'outdoors'],
            'vibe' => 'practical',
            'values' => ['sustainable'],
            'occasion' => 'Birthday',
            'age_band' => '30s',
        ])->assertRedirect();

        $question = CommunityQuestion::query()->firstOrFail();

        $this->assertSame(['coffee', 'outdoors'], $question->interests);
        $this->assertSame(Vibe::Practical, $question->vibe);
        $this->assertSame('Birthday', $question->occasion);

        // Rendered as labels in the reader's language, never as raw enum values.
        $this->assertContains(__('site.gift.interests.coffee'), $question->tags());
        $this->assertContains(__('site.gift.vibes.practical'), $question->tags());
    }

    #[Test]
    public function an_invented_interest_is_refused(): void
    {
        // Constrained to the enum rather than free text: the point is that an
        // answerer can search from a question without a translation layer, and
        // free text here would be a third moderation surface for no gain.
        Queue::fake();

        $this->actingAs(User::factory()->create())
            ->post('/be-nl/ask', [
                'title' => 'A perfectly ordinary question about gifts',
                'interests' => ['competitive-ferreting'],
            ])
            ->assertSessionHasErrors('interests.0');
    }

    #[Test]
    public function a_retired_interest_vanishes_from_an_old_question(): void
    {
        /*
         * A value that is no longer in the enum is skipped rather than printed
         * raw — otherwise retiring an interest puts `photography` in the middle
         * of a Dutch sentence on every question that ever used it.
         */
        $question = CommunityQuestion::factory()->published()->create([
            'interests' => ['coffee', 'no-longer-a-thing'],
        ]);

        $tags = $question->tags();

        $this->assertContains(__('site.gift.interests.coffee'), $tags);
        $this->assertNotContains('no-longer-a-thing', $tags);
        $this->assertCount(1, $tags);
    }

    // --- The board -----------------------------------------------------------

    #[Test]
    public function the_board_shows_published_questions_for_this_market_only(): void
    {
        $here = CommunityQuestion::factory()->published()->create();
        $elsewhere = CommunityQuestion::factory()->published()->inMarket(Market::NlNl)->create();
        $held = CommunityQuestion::factory()->create();

        $titles = array_column($this->props($this->get('/be-nl/ask')->assertOk())['questions'], 'title');

        $this->assertContains($here->title, $titles);
        $this->assertNotContains($elsewhere->title, $titles);
        $this->assertNotContains($held->title, $titles);
    }

    #[Test]
    public function you_are_shown_your_own_question_while_it_waits(): void
    {
        /*
         * Otherwise the feature looks broken in the exact moment somebody
         * first uses it: they press Ask, the board reloads, and their question
         * is not on it. Their own writing is not a disclosure.
         */
        $author = User::factory()->create();
        $question = CommunityQuestion::factory()->create(['user_id' => $author->id]);

        $props = $this->props($this->actingAs($author)->get('/be-nl/ask')->assertOk());

        $this->assertSame([$question->title], array_column($props['mine'], 'title'));
        $this->assertSame([], $props['questions']);
    }

    #[Test]
    public function somebody_elses_held_question_is_not_shown_to_you(): void
    {
        CommunityQuestion::factory()->create();

        $props = $this->props($this->actingAs(User::factory()->create())->get('/be-nl/ask')->assertOk());

        $this->assertSame([], $props['mine']);
        $this->assertSame([], $props['questions']);
    }

    // --- One question --------------------------------------------------------

    #[Test]
    public function a_held_question_is_a_404_to_everybody_but_its_author(): void
    {
        // "This exists but you may not see it" is itself information.
        $question = CommunityQuestion::factory()->create();

        $this->get("/be-nl/ask/{$question->id}/{$question->slug()}")->assertNotFound();

        $this->actingAs(User::factory()->create())
            ->get("/be-nl/ask/{$question->id}/{$question->slug()}")
            ->assertNotFound();

        $this->actingAs(User::find($question->user_id))
            ->get("/be-nl/ask/{$question->id}/{$question->slug()}")
            ->assertOk();
    }

    #[Test]
    public function a_stale_slug_redirects_rather_than_404s(): void
    {
        // The slug is decoration and the id is identity, exactly as on a
        // product page — a retitled question keeps every link already shared.
        $question = CommunityQuestion::factory()->published()->create(['title' => 'A perfectly good question']);

        $this->get("/be-nl/ask/{$question->id}/an-old-slug")
            ->assertRedirect("/be-nl/ask/{$question->id}/{$question->slug()}");
    }

    #[Test]
    public function a_question_with_no_answers_is_not_indexed(): void
    {
        // A thin page made of one stranger's sentence. It becomes indexable the
        // moment somebody answers, which is when it is worth landing on.
        $question = CommunityQuestion::factory()->published()->create();

        $this->get("/be-nl/ask/{$question->id}/{$question->slug()}")
            ->assertOk()
            ->assertSee('noindex', false);
    }

    #[Test]
    public function a_held_answer_is_shown_to_its_author_and_to_nobody_else(): void
    {
        $question = CommunityQuestion::factory()->published()->create();
        $author = User::factory()->create();

        CommunityAnswer::factory()->create([
            'question_id' => $question->id,
            'user_id' => $author->id,
            'body' => 'A held suggestion',
        ]);

        $url = "/be-nl/ask/{$question->id}/{$question->slug()}";

        $this->assertSame([], $this->props($this->get($url)->assertOk())['answers']);

        $mine = $this->props($this->actingAs($author)->get($url)->assertOk())['answers'];

        $this->assertCount(1, $mine);
        $this->assertSame('A held suggestion', $mine[0]['body']);
    }

    // --- Answering -----------------------------------------------------------

    #[Test]
    public function an_answer_starts_unpublished_too(): void
    {
        Queue::fake();

        $question = CommunityQuestion::factory()->published()->create();

        $this->actingAs(User::factory()->create())
            ->post("/be-nl/ask/{$question->id}/answers", ['body' => 'Get her a proper coffee grinder.'])
            ->assertRedirect();

        $answer = CommunityAnswer::query()->firstOrFail();

        $this->assertSame(ModerationStatus::Pending, $answer->status);
        Queue::assertPushed(TriageCommunityPost::class);
    }

    #[Test]
    public function a_held_question_cannot_be_answered(): void
    {
        $question = CommunityQuestion::factory()->create();

        $this->actingAs(User::factory()->create())
            ->post("/be-nl/ask/{$question->id}/answers", ['body' => 'Something'])
            ->assertNotFound();
    }

    #[Test]
    public function an_answer_carries_products_from_our_own_catalogue(): void
    {
        Queue::fake();

        $question = CommunityQuestion::factory()->published()->create();
        $group = ProductGroup::factory()->create(['market' => Market::BeNl]);

        $this->actingAs(User::factory()->create())->post("/be-nl/ask/{$question->id}/answers", [
            'body' => 'This one is excellent.',
            'picks' => [$group->id],
        ]);

        $this->assertSame([$group->id], CommunityAnswer::query()->firstOrFail()->groups->pluck('id')->all());
    }

    #[Test]
    public function a_pick_from_another_market_is_dropped(): void
    {
        /*
         * The ids arrive from the client, so a hand-built request can name a
         * product from another catalogue — which would render a price in the
         * wrong currency for a shop that does not deliver here. Invariant #2.
         */
        Queue::fake();

        $question = CommunityQuestion::factory()->published()->create();
        $foreign = ProductGroup::factory()->create(['market' => Market::NlNl]);

        $this->actingAs(User::factory()->create())->post("/be-nl/ask/{$question->id}/answers", [
            'body' => 'Try this.',
            'picks' => [$foreign->id],
        ]);

        $this->assertSame(0, CommunityAnswer::query()->firstOrFail()->picks()->count());
    }

    // --- The counter ---------------------------------------------------------

    #[Test]
    public function the_answer_count_only_counts_published_answers(): void
    {
        /*
         * A question showing "3 answers" and then displaying none — because all
         * three are held — is worse than showing nothing at all.
         */
        $question = CommunityQuestion::factory()->published()->create();

        CommunityAnswer::factory()->count(2)->create(['question_id' => $question->id]);
        $this->assertSame(0, $question->fresh()->answers_count);

        $published = CommunityAnswer::factory()->create(['question_id' => $question->id]);
        $published->publish();

        $this->assertSame(1, $question->fresh()->answers_count);

        $published->refuse('admin');
        $this->assertSame(0, $question->fresh()->answers_count);
    }
}

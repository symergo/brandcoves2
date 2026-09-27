<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AskAudience;
use App\Enums\ModerationStatus;
use App\Jobs\TriageCommunityPost;
use App\Mail\PeopleQuestionMail;
use App\Models\CommunityAnswer;
use App\Models\CommunityQuestion;
use App\Models\Notification;
use App\Models\User;
use App\Services\Community\QuestionToPeople;
use App\Services\Social\Friends;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ask others: the GiftCoves community, or only your people (owner,
 * 2026-09-27). A people question is never on the board, opens by its link
 * code, reaches the asker's friends at once, is not read first, and hands the
 * asker its link straight after posting. See docs/features/ask-others.md,
 * "Ask the community or ask your people".
 */
class AskYourPeopleTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function props(TestResponse $response): array
    {
        return $response->viewData('page')['props'];
    }

    private function friends(User $a, User $b): void
    {
        app(Friends::class)->link($a, $b);
    }

    private function askPeople(User $asker, string $title = 'Wat koop ik voor mijn zus van dertig?'): CommunityQuestion
    {
        $this->actingAs($asker)
            ->post('/be-nl/ask', ['title' => $title, 'audience' => 'people'])
            ->assertRedirect();

        return CommunityQuestion::query()->where('title', $title)->firstOrFail();
    }

    // --- Asking ----------------------------------------------------------------

    #[Test]
    public function asking_your_people_is_visible_at_once_and_never_read_by_the_triage_job(): void
    {
        Queue::fake([TriageCommunityPost::class]);

        $question = $this->askPeople(User::factory()->create());

        $this->assertSame(AskAudience::People, $question->audience);
        $this->assertSame(ModerationStatus::Published, $question->status);
        $this->assertNotNull($question->published_at);
        $this->assertMatchesRegularExpression('/^[0-9a-z]{10}$/', (string) $question->share_token);

        Queue::assertNotPushed(TriageCommunityPost::class);
    }

    #[Test]
    public function asking_the_community_is_unchanged_held_and_read_first(): void
    {
        Queue::fake([TriageCommunityPost::class]);

        $this->actingAs(User::factory()->create())
            ->post('/be-nl/ask', ['title' => 'Wat koop ik voor mijn broer?', 'audience' => 'public'])
            ->assertRedirect('/be-nl/ask');

        $question = CommunityQuestion::query()->firstOrFail();

        $this->assertSame(AskAudience::Public, $question->audience);
        $this->assertSame(ModerationStatus::Pending, $question->status);
        $this->assertNull($question->share_token);
        Queue::assertPushed(TriageCommunityPost::class, 1);
    }

    #[Test]
    public function no_audience_means_the_community_as_before(): void
    {
        Queue::fake([TriageCommunityPost::class]);

        $this->actingAs(User::factory()->create())
            ->post('/be-nl/ask', ['title' => 'Wat koop ik voor mijn broer?'])
            ->assertRedirect('/be-nl/ask');

        $this->assertSame(AskAudience::Public, CommunityQuestion::query()->firstOrFail()->audience);
        Queue::assertPushed(TriageCommunityPost::class, 1);
    }

    #[Test]
    public function an_unknown_audience_is_refused(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/be-nl/ask', ['title' => 'Wat koop ik voor mijn broer?', 'audience' => 'everyone'])
            ->assertSessionHasErrors('audience');

        $this->assertSame(0, CommunityQuestion::query()->count());
    }

    #[Test]
    public function the_asker_lands_on_the_question_with_its_link_open_to_send(): void
    {
        $asker = User::factory()->create();

        $response = $this->actingAs($asker)
            ->post('/be-nl/ask', ['title' => 'Wat koop ik voor mijn zus van dertig?', 'audience' => 'people']);

        $question = CommunityQuestion::query()->firstOrFail();
        $response->assertRedirect("/be-nl/ask/p/{$question->share_token}");

        $props = $this->props($this->actingAs($asker)->get("/be-nl/ask/p/{$question->share_token}")->assertOk());

        $this->assertTrue($props['openShare']);
        $this->assertSame(url("/be-nl/ask/p/{$question->share_token}"), $props['question']['shareUrl']);
        $this->assertSame('people', $props['question']['audience']);

        // Once: a reload does not open it again, the button does.
        $again = $this->props($this->actingAs($asker)->get("/be-nl/ask/p/{$question->share_token}"));
        $this->assertFalse($again['openShare']);
        $this->assertNotNull($again['question']['shareUrl']);
    }

    // --- Who sees it -------------------------------------------------------------

    #[Test]
    public function it_is_never_on_the_board_discover_or_the_sitemap(): void
    {
        $question = CommunityQuestion::factory()->forPeople()->create(['title' => 'Alleen voor mijn mensen, echt waar', 'answers_count' => 3]);
        CommunityQuestion::factory()->published()->count(3)->create();

        $board = $this->props($this->get('/be-nl/ask'))['questions'];
        $this->assertCount(3, $board);
        $this->assertNotContains($question->id, array_column($board, 'id'));

        $discover = $this->props($this->get('/be-nl/discover-cove'))['questions'];
        $this->assertNotContains('Alleen voor mijn mensen, echt waar', array_column($discover, 'title'));

        $sitemap = (string) $this->get('/sitemap/be-nl/1.xml')->getContent();
        $this->assertStringNotContainsString($question->share_token, $sitemap);
        $this->assertStringNotContainsString("/ask/{$question->id}/", $sitemap);
    }

    #[Test]
    public function its_page_is_noindex(): void
    {
        config()->set('giftcoves.robots_allow', true);

        $question = CommunityQuestion::factory()->forPeople()->create(['answers_count' => 2]);

        $this->get("/be-nl/ask/p/{$question->share_token}")
            ->assertOk()
            ->assertSee('noindex, nofollow', false);
    }

    #[Test]
    public function a_friend_and_anybody_with_the_link_can_open_it(): void
    {
        $asker = User::factory()->create();
        $friend = User::factory()->create();
        $this->friends($asker, $friend);

        $question = CommunityQuestion::factory()->forPeople()->create(['user_id' => $asker->id]);
        $url = "/be-nl/ask/p/{$question->share_token}";

        $this->actingAs($friend)->get($url)->assertOk();

        // Holding the link is the permission, signed in or not.
        $this->app['auth']->forgetGuards();
        $guest = $this->props($this->get($url)->assertOk());
        $this->assertFalse($guest['canAnswer']);
        // The link to send is the asker's alone.
        $this->assertNull($guest['question']['shareUrl']);

        $this->actingAs(User::factory()->create())->get($url)->assertOk();
    }

    #[Test]
    public function without_the_code_it_is_a_404_to_everybody_including_by_its_id(): void
    {
        $asker = User::factory()->create();
        $question = CommunityQuestion::factory()->forPeople()->create(['user_id' => $asker->id]);

        $this->get('/be-nl/ask/p/zzzzzzzzzz')->assertNotFound();

        // Ids can be counted; the id is never an address for it, not even
        // for its asker, who has the link.
        $this->get("/be-nl/ask/{$question->id}/{$question->slug()}")->assertNotFound();
        $this->actingAs($asker)->get("/be-nl/ask/{$question->id}/{$question->slug()}")->assertNotFound();

        // And not from another market.
        $this->get("/nl-nl/ask/p/{$question->share_token}")->assertNotFound();
    }

    #[Test]
    public function one_an_admin_refused_closes_its_link_but_its_asker_still_sees_it(): void
    {
        $asker = User::factory()->create();
        $question = CommunityQuestion::factory()->forPeople()->create(['user_id' => $asker->id]);
        $question->refuse('admin');

        $url = "/be-nl/ask/p/{$question->share_token}";

        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
        $this->actingAs($asker)->get($url)->assertOk();
        $this->actingAs(User::factory()->create())
            ->post("{$url}/answers", ['body' => 'Een boek over klimmen'])
            ->assertNotFound();
    }

    #[Test]
    public function the_code_is_never_serialised_with_the_model(): void
    {
        $question = CommunityQuestion::factory()->forPeople()->create();

        $this->assertArrayNotHasKey('share_token', $question->toArray());
    }

    // --- Your page ---------------------------------------------------------------

    #[Test]
    public function your_questions_carry_their_link_and_your_friends_people_questions_are_listed_for_you(): void
    {
        $me = User::factory()->create();
        $friend = User::factory()->create();
        $stranger = User::factory()->create();
        $this->friends($me, $friend);

        $mineOnBoard = CommunityQuestion::factory()->published()->create(['user_id' => $me->id]);
        $mineHeld = CommunityQuestion::factory()->create(['user_id' => $me->id]);
        $mineForPeople = CommunityQuestion::factory()->forPeople()->create(['user_id' => $me->id]);
        $theirs = CommunityQuestion::factory()->forPeople()->create(['user_id' => $friend->id]);
        $strangers = CommunityQuestion::factory()->forPeople()->create(['user_id' => $stranger->id]);

        $props = $this->props($this->actingAs($me)->get('/be-nl/ask'));

        $mine = collect($props['mine'])->keyBy('id');
        $this->assertEqualsCanonicalizing([$mineOnBoard->id, $mineHeld->id, $mineForPeople->id], $mine->keys()->all());
        $this->assertSame(url("/be-nl/ask/{$mineOnBoard->id}/{$mineOnBoard->slug()}"), $mine[$mineOnBoard->id]['shareUrl']);
        $this->assertSame(url("/be-nl/ask/p/{$mineForPeople->share_token}"), $mine[$mineForPeople->id]['shareUrl']);
        // A held question's link is a 404 to everybody else: nothing to send.
        $this->assertNull($mine[$mineHeld->id]['shareUrl']);

        // Your own board question is under "Jouw vragen", not twice.
        $this->assertNotContains($mineOnBoard->id, array_column($props['questions'], 'id'));

        $this->assertSame([$theirs->id], array_column($props['fromPeople'], 'id'));
        $this->assertSame("/be-nl/ask/p/{$theirs->share_token}", $props['fromPeople'][0]['url']);
        // Nobody else's link rides along on your page.
        $this->assertNull($props['fromPeople'][0]['shareUrl']);
        $this->assertStringNotContainsString((string) $strangers->share_token, json_encode($props));
        $this->assertSame(1, $props['friendCount']);
    }

    #[Test]
    public function a_stranger_sees_no_peoples_questions(): void
    {
        CommunityQuestion::factory()->forPeople()->create();

        $props = $this->props($this->get('/be-nl/ask'));

        $this->assertSame([], $props['fromPeople']);
        $this->assertSame([], $props['mine']);
    }

    // --- Your people hear about it -------------------------------------------------

    #[Test]
    public function friends_are_told_at_once_with_the_link(): void
    {
        Mail::fake();

        $asker = User::factory()->create(['name' => 'Anna']);
        $friend = User::factory()->create();
        $stranger = User::factory()->create();
        $this->friends($asker, $friend);

        $question = $this->askPeople($asker);

        $row = Notification::query()->where('user_id', $friend->id)->firstOrFail();
        $this->assertSame(QuestionToPeople::KIND, $row->kind);
        $this->assertSame("/be-nl/ask/p/{$question->share_token}", $row->url);
        $this->assertSame(0, Notification::query()->where('user_id', $stranger->id)->count());
        $this->assertSame(0, Notification::query()->where('user_id', $asker->id)->count());

        Mail::assertQueued(PeopleQuestionMail::class, fn ($mail) => $mail->hasTo($friend->email)
            && str_contains($mail->url, "/ask/p/{$question->share_token}"));
    }

    #[Test]
    public function the_receivers_switches_still_apply(): void
    {
        Mail::fake();

        $asker = User::factory()->create();
        $off = User::factory()->create();
        $noEmail = User::factory()->create();
        $off->forceFill(['people_questions_off_at' => now()])->save();
        $noEmail->forceFill(['people_question_emails_off_at' => now()])->save();
        $this->friends($asker, $off);
        $this->friends($asker, $noEmail);

        $this->askPeople($asker);

        $this->assertSame(0, Notification::query()->where('user_id', $off->id)->count());
        $this->assertSame(1, Notification::query()->where('user_id', $noEmail->id)->count());
        Mail::assertNothingQueued();
    }

    #[Test]
    public function the_askers_board_switch_does_not_stop_a_question_asked_of_their_people(): void
    {
        Mail::fake();

        $asker = User::factory()->create();
        $asker->forceFill(['ask_people_off_at' => now()])->save();
        $friend = User::factory()->create();
        $this->friends($asker, $friend);

        $this->askPeople($asker);

        // The form's choice is the more specific one.
        $this->assertSame(1, Notification::query()->where('user_id', $friend->id)->count());
    }

    // --- Answering ---------------------------------------------------------------

    #[Test]
    public function answering_needs_an_account_and_then_the_link_is_enough(): void
    {
        Queue::fake([TriageCommunityPost::class]);

        $question = CommunityQuestion::factory()->forPeople()->create();
        $url = "/be-nl/ask/p/{$question->share_token}/answers";

        $this->post($url, ['body' => 'Een boek over klimmen'])->assertRedirect();
        $this->assertSame(0, CommunityAnswer::query()->count());

        $holder = User::factory()->create();
        $page = $this->props($this->actingAs($holder)->get("/be-nl/ask/p/{$question->share_token}"));
        $this->assertTrue($page['canAnswer']);
        $this->assertSame("/be-nl/ask/p/{$question->share_token}/answers", $page['question']['answerUrl']);

        $this->actingAs($holder)->post($url, ['body' => 'Een boek over klimmen'])->assertRedirect();

        $answer = CommunityAnswer::query()->firstOrFail();
        $this->assertSame($question->id, $answer->question_id);
        // Answers are read first on either audience.
        $this->assertSame(ModerationStatus::Pending, $answer->status);
        Queue::assertPushed(TriageCommunityPost::class, 1);
    }

    #[Test]
    public function the_board_answer_route_does_not_take_answers_for_a_people_question(): void
    {
        $question = CommunityQuestion::factory()->forPeople()->create();

        $this->actingAs(User::factory()->create())
            ->post("/be-nl/ask/{$question->id}/answers", ['body' => 'Een boek over klimmen'])
            ->assertNotFound();
    }

    // --- Schema --------------------------------------------------------------------

    #[Test]
    public function an_existing_row_stays_on_the_board_and_a_people_question_needs_a_code(): void
    {
        $id = DB::table('community_questions')->insertGetId([
            'market' => 'be-nl',
            'user_id' => User::factory()->create()->id,
            'title' => 'Een vraag van voor 27 september',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('public', DB::table('community_questions')->where('id', $id)->value('audience'));

        $this->expectException(QueryException::class);
        DB::table('community_questions')->where('id', $id)->update(['audience' => 'people']);
    }
}

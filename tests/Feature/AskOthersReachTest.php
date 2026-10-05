<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\EventType;
use App\Enums\Interest;
use App\Enums\ListKind;
use App\Enums\Market;
use App\Enums\ModerationStatus;
use App\Jobs\SendQuestionToPeople;
use App\Jobs\TriageCommunityPost;
use App\Mail\PeopleQuestionMail;
use App\Models\CommunityQuestion;
use App\Models\Notification;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use App\Services\Community\QuestionToPeople;
use App\Services\Social\Friends;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ask others, easier to reach and sent to your people (owner, 2026-09-26).
 *
 * Four things: Find a gift's fourth way and the list page open the ask form
 * filled in (never with a name), Discover always invites, and a published
 * question reaches the asker's friends under two switches and a daily limit.
 * See docs/features/ask-others.md, "Filled in" and "Sent to your people".
 */
class AskOthersReachTest extends TestCase
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

    // --- Filled in -----------------------------------------------------------

    #[Test]
    public function a_kind_of_person_fills_in_the_title_and_nothing_else(): void
    {
        $prefill = $this->props(
            $this->actingAs(User::factory()->create())->get('/be-nl/ask?from=gift&relationship=mother'),
        )['prefill'];

        $this->assertSame('Cadeaus voor mijn mama?', $prefill['title']);
        $this->assertSame([], $prefill['interests']);
        $this->assertSame('', $prefill['budget_max']);
        $this->assertNull($prefill['list']);
    }

    #[Test]
    public function a_saved_person_fills_in_what_the_form_asks_and_never_their_name_or_notes(): void
    {
        $me = User::factory()->create();

        $mum = Recipient::factory()
            ->into([Interest::Cooking, Interest::Coffee, 'her own words'])
            ->create([
                'owner_user_id' => $me->id,
                'name' => 'Greetje',
                'relationship' => 'mama',
                'notes' => 'Allergic to lavender, lives in Gent',
                'age_band' => '50-64',
            ]);
        // The budget is her list's, not hers (2026-10-05).
        Wishlist::factory()->forSomeone($mum)->create(['owner_user_id' => $me->id, 'title' => 'Kerst', 'budget_max' => 4000]);

        $response = $this->actingAs($me)->get("/be-nl/ask?from=gift&person={$mum->id}");
        $prefill = $this->props($response)['prefill'];

        $this->assertSame('Cadeaus voor mijn mama?', $prefill['title']);
        // Only the fixed vocabulary: a typed word has no place on the form.
        $this->assertSame(['cooking', 'coffee'], $prefill['interests']);
        $this->assertSame('40', $prefill['budget_max']);
        $this->assertSame('50-64 jaar', $prefill['age_band']);
        // Vibe and values were removed site-wide (2026-09-29).
        $this->assertArrayNotHasKey('vibe', $prefill);
        $this->assertArrayNotHasKey('values', $prefill);

        // Nothing that could identify her reaches the form. (The site-wide save
        // menu names your own lists' people back to you; that is yours.)
        $page = json_encode($prefill);
        $this->assertStringNotContainsString('Greetje', $page);
        $this->assertStringNotContainsString('lavender', $page);
    }

    #[Test]
    public function a_relationship_typed_by_hand_is_left_out_because_it_can_hold_a_name(): void
    {
        $me = User::factory()->create();
        $person = Recipient::factory()->create(['owner_user_id' => $me->id, 'relationship' => 'Tante Mieke']);

        $prefill = $this->props($this->actingAs($me)->get("/be-nl/ask?person={$person->id}"))['prefill'];

        $this->assertSame('', $prefill['title']);
        $this->assertStringNotContainsString('Mieke', json_encode($prefill));
    }

    #[Test]
    public function somebody_elses_saved_person_fills_in_nothing(): void
    {
        $theirs = Recipient::factory()->create();

        $response = $this->actingAs(User::factory()->create())->get("/be-nl/ask?person={$theirs->id}");

        $this->assertNull($this->props($response)['prefill']);
    }

    #[Test]
    public function a_gift_list_fills_in_its_person_occasion_and_date(): void
    {
        $me = User::factory()->create();
        $dad = Recipient::factory()->create([
            'owner_user_id' => $me->id,
            'name' => 'Jef',
            'relationship' => 'father',
        ]);
        $list = Wishlist::factory()->forSomeone($dad)->create([
            'owner_user_id' => $me->id,
            'event_type' => EventType::Birthday,
            'event_date' => '2026-10-12',
            'budget_max' => 2500,
        ]);

        $prefill = $this->props($this->actingAs($me)->get("/be-nl/ask?list={$list->id}"))['prefill'];

        $this->assertSame('Cadeaus voor mijn papa?', $prefill['title']);
        $this->assertSame('25', $prefill['budget_max']);
        $this->assertSame('Verjaardag, 12 oktober', $prefill['occasion']);
        $this->assertSame($list->id, $prefill['list']['id']);
        $this->assertStringNotContainsString('Jef', json_encode($prefill));
    }

    #[Test]
    public function a_wish_list_of_your_own_and_somebody_elses_list_fill_in_nothing(): void
    {
        $me = User::factory()->create();
        $mine = Wishlist::factory()->create(['owner_user_id' => $me->id, 'kind' => ListKind::Mine]);
        $theirs = Wishlist::factory()->forSomeone()->create();

        $this->assertNull($this->props($this->actingAs($me)->get("/be-nl/ask?list={$mine->id}"))['prefill']);
        $this->assertNull($this->props($this->actingAs($me)->get("/be-nl/ask?list={$theirs->id}"))['prefill']);
        // Not a uuid at all: nothing, and no database error.
        $this->assertNull($this->props($this->actingAs($me)->get('/be-nl/ask?list=nonsense'))['prefill']);
    }

    #[Test]
    public function a_question_asked_from_your_list_remembers_it_and_only_you_get_the_save_to_it(): void
    {
        Queue::fake([TriageCommunityPost::class]);

        $me = User::factory()->create();
        $list = Wishlist::factory()->forSomeone()->create(['owner_user_id' => $me->id]);

        $this->actingAs($me)->post('/be-nl/ask', [
            'title' => 'Cadeaus voor mijn mama?',
            'list_id' => $list->id,
        ])->assertRedirect();

        $question = CommunityQuestion::query()->firstOrFail();
        $this->assertSame($list->id, $question->wishlist_id);

        $question->forceFill(['status' => ModerationStatus::Published, 'published_at' => now()])->saveQuietly();
        $url = "/be-nl/ask/{$question->id}/{$question->slug()}";

        $this->assertSame($list->id, $this->props($this->actingAs($me)->get($url))['into']['id']);
        $this->assertNull($this->props($this->actingAs(User::factory()->create())->get($url))['into']);
    }

    #[Test]
    public function a_list_that_is_not_yours_is_not_remembered_on_the_question(): void
    {
        Queue::fake([TriageCommunityPost::class]);

        $theirs = Wishlist::factory()->forSomeone()->create();

        $this->actingAs(User::factory()->create())->post('/be-nl/ask', [
            'title' => 'What do I buy my neighbour?',
            'list_id' => $theirs->id,
        ])->assertRedirect();

        $this->assertNull(CommunityQuestion::query()->firstOrFail()->wishlist_id);
    }

    #[Test]
    public function discover_links_to_the_form_even_with_no_questions(): void
    {
        $props = $this->props($this->get('/be-nl/discover-cove'));

        // The invitation is drawn from `urls.ask` alone; the list stays empty
        // until there are three.
        $this->assertSame('/be-nl/ask', $props['urls']['ask']);
        $this->assertSame([], $props['questions']);

        // And `?new=1` opens the form.
        $this->assertTrue($this->props($this->get('/be-nl/ask?new=1'))['open']);
    }

    // --- Sent to your people -------------------------------------------------

    #[Test]
    public function a_published_question_reaches_friends_only_and_never_the_asker(): void
    {
        Mail::fake();

        $asker = User::factory()->create(['name' => 'Anna']);
        $friend = User::factory()->create();
        $stranger = User::factory()->create();
        $this->friends($asker, $friend);

        $question = CommunityQuestion::factory()->create(['user_id' => $asker->id, 'title' => 'Wat koop ik voor mijn zus?']);
        $question->publish();

        $row = Notification::query()->where('user_id', $friend->id)->firstOrFail();
        $this->assertSame(QuestionToPeople::KIND, $row->kind);
        $this->assertSame('Anna vraagt: “Wat koop ik voor mijn zus?”', $row->title);
        $this->assertSame("/be-nl/ask/{$question->id}/{$question->slug()}", $row->url);

        $this->assertSame(0, Notification::query()->where('user_id', $stranger->id)->count());
        $this->assertSame(0, Notification::query()->where('user_id', $asker->id)->count());

        Mail::assertQueued(PeopleQuestionMail::class, fn ($mail) => $mail->hasTo($friend->email));
        Mail::assertQueuedCount(1);
    }

    #[Test]
    public function nothing_is_sent_before_the_question_is_published(): void
    {
        Mail::fake();
        Queue::fake([TriageCommunityPost::class]);

        $asker = User::factory()->create();
        $friend = User::factory()->create();
        $this->friends($asker, $friend);

        $this->actingAs($asker)->post('/be-nl/ask', ['title' => 'What do I buy my sister for her 30th?']);

        $question = CommunityQuestion::query()->firstOrFail();
        $this->assertSame(ModerationStatus::Pending, $question->status);

        // Even asked directly, the service refuses a question not on the board.
        $this->assertSame(0, app(QuestionToPeople::class)->send($question));
        $question->refuse('spam');
        $this->assertSame(0, app(QuestionToPeople::class)->send($question->fresh()));

        $this->assertSame(0, Notification::query()->count());
        Mail::assertNothingQueued();
    }

    #[Test]
    public function publishing_queues_the_job(): void
    {
        Queue::fake([SendQuestionToPeople::class]);

        $question = CommunityQuestion::factory()->create();
        $question->publish();

        Queue::assertPushed(SendQuestionToPeople::class, fn ($job) => $job->questionId === $question->id);
    }

    #[Test]
    public function an_asker_who_switched_it_off_reaches_nobody(): void
    {
        Mail::fake();

        $asker = User::factory()->create();
        $friend = User::factory()->create();
        $this->friends($asker, $friend);

        $this->actingAs($asker)
            ->post('/be-nl/notifications/ask-people', ['setting' => 'ask', 'on' => false])
            ->assertRedirect();
        $this->assertNotNull($asker->fresh()->ask_people_off_at);

        CommunityQuestion::factory()->create(['user_id' => $asker->id])->publish();

        $this->assertSame(0, Notification::query()->count());
        Mail::assertNothingQueued();
    }

    #[Test]
    public function a_receiver_who_switched_it_off_hears_nothing(): void
    {
        Mail::fake();

        $asker = User::factory()->create();
        $off = User::factory()->create();
        $on = User::factory()->create();
        $this->friends($asker, $off);
        $this->friends($asker, $on);

        $this->actingAs($off)->post('/be-nl/notifications/ask-people', ['setting' => 'receive', 'on' => false]);

        CommunityQuestion::factory()->create(['user_id' => $asker->id])->publish();

        $this->assertSame(0, Notification::query()->where('user_id', $off->id)->count());
        $this->assertSame(1, Notification::query()->where('user_id', $on->id)->count());
        Mail::assertQueued(PeopleQuestionMail::class, fn ($mail) => ! $mail->hasTo($off->email));
    }

    #[Test]
    public function a_receiver_without_email_gets_the_notification_only(): void
    {
        Mail::fake();

        $asker = User::factory()->create();
        $friend = User::factory()->create();
        $friend->forceFill(['people_question_emails_off_at' => now()])->save();
        $this->friends($asker, $friend);

        CommunityQuestion::factory()->create(['user_id' => $asker->id])->publish();

        $this->assertSame(1, Notification::query()->where('user_id', $friend->id)->count());
        Mail::assertNothingQueued();
    }

    #[Test]
    public function one_a_day_per_asker_per_receiver_and_each_question_once(): void
    {
        Mail::fake();

        $asker = User::factory()->create();
        $friend = User::factory()->create();
        $this->friends($asker, $friend);

        $first = CommunityQuestion::factory()->create(['user_id' => $asker->id]);
        $first->publish();
        $second = CommunityQuestion::factory()->create(['user_id' => $asker->id]);
        $second->publish();

        // Refused and published again by hand: still sent once.
        $first->refuse();
        $first->publish();

        $this->assertSame(1, Notification::query()->where('user_id', $friend->id)->count());

        // A day later the next question gets through.
        $this->travel(25)->hours();
        CommunityQuestion::factory()->create(['user_id' => $asker->id])->publish();

        $this->assertSame(2, Notification::query()->where('user_id', $friend->id)->count());
        Mail::assertQueuedCount(2);
    }

    #[Test]
    public function the_email_is_in_the_receivers_language_and_carries_a_working_unsubscribe(): void
    {
        Mail::fake();

        $asker = User::factory()->create();
        $friend = User::factory()->create(['preferred_market' => Market::BeFr]);
        $this->friends($asker, $friend);

        CommunityQuestion::factory()->create(['user_id' => $asker->id, 'title' => 'Wat koop ik voor mijn zus?'])->publish();

        $this->assertStringContainsString('demande', Notification::query()->where('user_id', $friend->id)->value('title'));

        $mail = null;
        Mail::assertQueued(PeopleQuestionMail::class, function (PeopleQuestionMail $m) use (&$mail) {
            $mail = $m;

            return true;
        });

        $this->assertSame('fr', $mail->language);
        $this->assertStringContainsString('Wat koop ik voor mijn zus?', $mail->render());
        $this->assertSame('List-Unsubscribe=One-Click', $mail->headers()->text['List-Unsubscribe-Post']);

        // The link works signed out, and turns the email off, not the inbox.
        $this->post($mail->unsubscribeUrl)->assertRedirect();
        $friend->refresh();
        $this->assertNotNull($friend->people_question_emails_off_at);
        $this->assertNull($friend->people_questions_off_at);

        // A tampered link does nothing.
        $other = User::factory()->create();
        $forged = str_replace("/{$friend->id}?", "/{$other->id}?", $mail->unsubscribeUrl);
        $this->get($forged)->assertForbidden();
        $this->assertNull($other->fresh()->people_question_emails_off_at);
    }

    #[Test]
    public function the_settings_default_on_and_show_on_the_notifications_page(): void
    {
        $me = User::factory()->create();

        $this->assertSame(
            ['ask' => true, 'receive' => true, 'email' => true],
            $this->props($this->actingAs($me)->get('/be-nl/notifications'))['askPeople'],
        );

        $this->actingAs($me)->post('/be-nl/notifications/ask-people', ['setting' => 'email', 'on' => false]);
        $this->assertFalse($this->props($this->actingAs($me)->get('/be-nl/notifications'))['askPeople']['email']);

        $this->actingAs($me)->post('/be-nl/notifications/ask-people', ['setting' => 'email', 'on' => true]);
        $this->assertNull($me->fresh()->people_question_emails_off_at);

        // Only the three switches exist.
        $this->actingAs($me)
            ->post('/be-nl/notifications/ask-people', ['setting' => 'is_admin', 'on' => true])
            ->assertSessionHasErrors('setting');
    }
}

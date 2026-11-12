<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\MagicLinkMail;
use App\Mail\SantaNoteMail;
use App\Mail\SecretSantaAssignmentMail;
use App\Models\SecretSantaGroup;
use App\Models\SecretSantaMember;
use App\Models\SecretSantaQuestion;
use App\Models\User;
use App\Services\Gift\SantaGame;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The game around the draw (owner, 2026-11-12): starting without an account,
 * names to tap, a draw that happens by itself, the reveal, questions without
 * a name, the guess, and the same group a year on.
 *
 * The draw and the repair are covered in SecretSantaTest and the unit tests;
 * this file is what was added on top. See docs/features/secret-santa.md.
 */
class SantaGameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    /** @param  array<string, mixed>  $with */
    private function group(array $with = []): SecretSantaGroup
    {
        $this->actingAs(User::factory()->create(['name' => 'Olga']))
            ->post('/be-nl/santa', ['title' => 'Familie 2026', 'budget_max' => 25] + $with)
            ->assertRedirect();

        return SecretSantaGroup::query()->latest('created_at')->firstOrFail();
    }

    /** @param  array<string, mixed>  $with */
    private function join(SecretSantaGroup $group, string $name, array $with = []): SecretSantaMember
    {
        auth()->logout();
        $email = mb_strtolower($name).'@example.test';

        $this->post("/be-nl/s/{$group->invite_token}", ['display_name' => $name, 'email' => $email] + $with)
            ->assertRedirect();

        return $group->members()->where('email', $email)->firstOrFail();
    }

    private function draw(SecretSantaGroup $group): void
    {
        $this->actingAs($group->organiser)->post("/be-nl/santa/{$group->id}/draw")->assertSessionHas('success');
        auth()->logout();
    }

    private function mine(SecretSantaGroup $group, SecretSantaMember $member): string
    {
        return "/be-nl/santa/{$group->id}/me/{$member->join_token}";
    }

    private function gifteeOf(SecretSantaMember $member): SecretSantaMember
    {
        return SecretSantaMember::query()->findOrFail((int) $member->fresh()->assigned_member_id);
    }

    #[Test]
    public function a_visitor_starts_a_group_without_an_account(): void
    {
        $this->from('/be-nl/santa')->post('/be-nl/santa', [
            'title' => 'Kantoor',
            'organiser_email' => 'Nina@Example.test',
            'organiser_name' => 'Nina',
            'elapsed_ms' => 5000,
        ])->assertRedirect();

        $group = SecretSantaGroup::query()->sole();
        $user = User::query()->where('email', 'nina@example.test')->sole();

        // Signed in on the spot, unconfirmed, with the link that confirms it on its way.
        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->emailConfirmed());
        Mail::assertSent(MagicLinkMail::class);
        $this->assertTrue($group->isOrganiser($user));
        $this->assertSame('Nina', $group->members()->sole()->display_name);
    }

    #[Test]
    public function an_address_with_an_account_has_to_sign_in_to_start_a_group(): void
    {
        User::factory()->create(['email' => 'known@example.test']);

        $this->from('/be-nl/santa')->post('/be-nl/santa', [
            'title' => 'Kantoor',
            'organiser_email' => 'known@example.test',
            'organiser_name' => 'Somebody',
            'elapsed_ms' => 5000,
        ])->assertSessionHasErrors('organiser_email');

        // Typing somebody's address proves nothing.
        $this->assertGuest();
        $this->assertSame(0, SecretSantaGroup::query()->count());
    }

    #[Test]
    public function a_form_sent_by_a_script_starts_nothing(): void
    {
        $this->post('/be-nl/santa', [
            'title' => 'Spam',
            'organiser_email' => 'bot@example.test',
            'organiser_name' => 'Bot',
            'website' => 'https://spam.example',
            'elapsed_ms' => 5000,
        ]);

        $this->assertSame(0, SecretSantaGroup::query()->count());
        $this->assertDatabaseMissing('users', ['email' => 'bot@example.test']);
    }

    #[Test]
    public function the_mail_says_there_is_a_name_and_the_page_turns_the_card(): void
    {
        $group = $this->group();
        $sam = $this->join($group, 'Sam');
        $this->draw($group);

        $giftee = $this->gifteeOf($sam);

        // Read over a shoulder, the mail gives nothing away.
        $mail = new SecretSantaAssignmentMail($giftee->display_name, $group->title, $group->market, 'https://example.test/me');
        $this->assertStringNotContainsString($giftee->display_name, $mail->envelope()->subject);
        $this->assertStringNotContainsString($giftee->display_name, $mail->render());

        $this->get($this->mine($group, $sam))
            ->assertInertia(fn ($page) => $page->where('me.revealed', false)->where('me.giftee.name', $giftee->display_name));

        // Nothing is counted as "looked at" before the card is turned.
        $this->assertSame(0, $giftee->fresh()->list_views);

        $this->post($this->mine($group, $sam).'/reveal')->assertRedirect();

        $this->get($this->mine($group, $sam))->assertInertia(fn ($page) => $page->where('me.revealed', true));
    }

    #[Test]
    public function a_first_look_at_the_list_is_told_to_its_owner_once(): void
    {
        $group = $this->group();
        $sam = $this->join($group, 'Sam');
        $this->draw($group);
        $giftee = $this->gifteeOf($sam);
        Mail::fake();

        $this->post($this->mine($group, $sam).'/reveal');
        $this->get($this->mine($group, $sam))->assertOk();
        $this->get($this->mine($group, $sam))->assertOk();

        // Twice opened within the hour is one look, and one mail.
        $this->assertSame(1, $giftee->fresh()->list_views);
        Mail::assertQueued(SantaNoteMail::class, fn (SantaNoteMail $mail) => $mail->kind === 'viewed' && $mail->hasTo($giftee->email));
        Mail::assertQueued(SantaNoteMail::class, 1);

        $this->get($this->mine($group, $giftee))->assertInertia(fn ($page) => $page->where('me.listViews', 1));
    }

    #[Test]
    public function joining_takes_names_to_avoid_and_a_few_interests(): void
    {
        $group = $this->group();
        $this->join($group, 'Sam');
        $ash = $this->join($group, 'Ash', ['exclusions' => ['Sam'], 'interests' => ['books', 'coffee'], 'hints' => 'Maat M']);

        $this->assertSame(['books', 'coffee'], $ash->interests);
        $this->assertSame('Maat M', $ash->hints);

        $map = app(SantaGame::class)->exclusionMap($group->members()->get());
        $sam = $group->members()->where('display_name', 'Sam')->sole();
        $this->assertSame([$sam->id], $map[$ash->id]);

        // Something to go on, without a list.
        $this->assertSame(1, app(SantaGame::class)->progress($group)['ready']);
    }

    #[Test]
    public function the_organiser_sets_a_couple_by_tapping_two_names(): void
    {
        $group = $this->group();
        $sam = $this->join($group, 'Sam');
        $ash = $this->join($group, 'Ash');
        $url = "/be-nl/santa/{$group->id}/exclusions";

        $this->actingAs($group->organiser)->post($url, ['a' => $sam->id, 'b' => $ash->id])->assertRedirect();

        // Both ways: a couple is two people who may not draw each other.
        $map = app(SantaGame::class)->exclusionMap($group->members()->get());
        $this->assertSame([$ash->id], $map[$sam->id]);
        $this->assertSame([$sam->id], $map[$ash->id]);

        $this->actingAs($group->organiser)->get("/be-nl/santa/{$group->id}")
            ->assertInertia(fn ($page) => $page->where('members', fn ($members) => collect($members)->firstWhere('id', $sam->id)['excludes'] === [$ash->id]));

        // Tapped again, it is lifted.
        $this->actingAs($group->organiser)->post($url, ['a' => $ash->id, 'b' => $sam->id]);
        $this->assertSame([], app(SantaGame::class)->exclusionMap($group->members()->get())[$sam->id]);

        // Not somebody else's to set.
        $this->actingAs(User::factory()->create())->post($url, ['a' => $sam->id, 'b' => $ash->id])->assertForbidden();
    }

    #[Test]
    public function the_draw_happens_by_itself_once_enough_people_are_in(): void
    {
        $group = $this->group(['draw_when_count' => 3]);
        $this->join($group, 'Sam');

        $this->assertFalse($group->fresh()->status->isDrawn());

        $this->join($group, 'Ash');

        $this->assertTrue($group->fresh()->status->isDrawn());
        Mail::assertQueued(SecretSantaAssignmentMail::class, 3);
    }

    #[Test]
    public function the_draw_happens_by_itself_at_the_moment_the_organiser_chose(): void
    {
        $group = $this->group();
        $this->join($group, 'Sam');
        $this->join($group, 'Ash');
        $group->update(['draw_at' => now()->addHour()]);

        $this->assertSame(0, app(SantaGame::class)->drawDue());

        $this->travel(2)->hours();

        $this->assertSame(1, app(SantaGame::class)->drawDue());
        $this->assertTrue($group->fresh()->status->isDrawn());
        $this->assertNull($group->fresh()->draw_at);
    }

    #[Test]
    public function a_question_reaches_the_giftee_without_a_name_and_the_answer_comes_back(): void
    {
        $group = $this->group();
        $sam = $this->join($group, 'Sam');
        $this->join($group, 'Ash');
        $this->draw($group);
        $giftee = $this->gifteeOf($sam);
        Mail::fake();

        $this->post($this->mine($group, $sam).'/questions', ['question' => 'Koffie of thee?'])->assertSessionHas('success');

        $question = SecretSantaQuestion::query()->sole();

        // Who asked is the pairing: encrypted at rest, and never serialised.
        $this->assertArrayNotHasKey('asker_member_id', $question->toArray());
        $this->assertNotSame((string) $sam->id, $question->getRawOriginal('asker_member_id'));
        Mail::assertQueued(SantaNoteMail::class, fn (SantaNoteMail $mail) => $mail->kind === 'question' && $mail->hasTo($giftee->email)
            && ! str_contains(implode(' ', $mail->lines), 'Sam'));

        // The giftee sees the question, and nothing about who asked.
        $this->get($this->mine($group, $giftee))
            ->assertInertia(fn ($page) => $page->where('me.inbox.0.question', 'Koffie of thee?')->missing('me.inbox.0.asker'));

        $this->post($this->mine($group, $giftee)."/questions/{$question->id}", ['answer' => 'Thee'])->assertSessionHas('success');

        Mail::assertQueued(SantaNoteMail::class, fn (SantaNoteMail $mail) => $mail->kind === 'answer' && $mail->hasTo($sam->email));
        $this->get($this->mine($group, $sam))->assertInertia(fn ($page) => $page->where('me.giftee.asked.0.answer', 'Thee'));

        // Somebody else's question is not theirs to answer.
        $this->post($this->mine($group, $sam)."/questions/{$question->id}", ['answer' => 'x'])->assertNotFound();
    }

    #[Test]
    public function somebody_who_muted_the_group_gets_no_friendly_mail(): void
    {
        $group = $this->group();
        $sam = $this->join($group, 'Sam');
        $this->draw($group);
        $giftee = $this->gifteeOf($sam);
        Mail::fake();

        $this->post($this->mine($group, $giftee).'/mute')->assertSessionHas('success');
        $this->post($this->mine($group, $sam).'/questions', ['question' => 'Welke maat?']);

        Mail::assertNothingQueued();
        // The question is still there to answer.
        $this->get($this->mine($group, $giftee))->assertInertia(fn ($page) => $page->where('me.muted', true)->has('me.inbox', 1));
    }

    #[Test]
    public function tips_become_searches_that_fit_the_budget(): void
    {
        $group = $this->group();
        $sam = $this->join($group, 'Sam');
        $this->draw($group);
        $giftee = $this->gifteeOf($sam);

        $this->post($this->mine($group, $giftee).'/hints', ['interests' => ['books'], 'hints' => 'Geen kaarsen'])->assertSessionHas('success');
        $this->post($this->mine($group, $sam).'/reveal');

        $this->get($this->mine($group, $sam))->assertInertia(fn ($page) => $page
            ->where('me.giftee.interests', ['books'])
            ->where('me.giftee.hints', 'Geen kaarsen')
            ->where('me.giftee.ideas.0.url', '/be-nl/search?q=boeken&max=25'));
    }

    #[Test]
    public function guessing_who_had_you_opens_on_the_exchange_day(): void
    {
        $group = $this->group(['exchange_date' => now()->addDays(10)->toDateString()]);
        $sam = $this->join($group, 'Sam');
        $this->join($group, 'Ash');
        $this->draw($group);
        $had = app(SantaGame::class)->santaOf($group, $sam);

        $this->get($this->mine($group, $sam))->assertInertia(fn ($page) => $page->where('me.guess', null));
        $this->post($this->mine($group, $sam).'/guess', ['member_id' => $had->id])->assertForbidden();

        $this->travel(11)->days();
        Mail::fake();

        $this->get($this->mine($group, $sam))->assertInertia(fn ($page) => $page
            ->where('me.guess.guessedId', null)
            // No answer before the guess.
            ->where('me.guess.santaName', null)
            ->has('me.guess.options', 2));

        $this->post($this->mine($group, $sam).'/guess', ['member_id' => $had->id])->assertRedirect();

        $this->get($this->mine($group, $sam))->assertInertia(fn ($page) => $page
            ->where('me.guess.correct', true)
            ->where('me.guess.santaName', $had->display_name));

        // One guess: a second one changes nothing.
        $other = $group->members()->whereNotIn('id', [$sam->id, $had->id])->sole();
        $this->post($this->mine($group, $sam).'/guess', ['member_id' => $other->id]);
        $this->assertSame($had->id, (int) $sam->fresh()->guess_member_id);

        $this->post($this->mine($group, $sam).'/thanks', ['message' => 'Dank je!'])->assertSessionHas('success');
        Mail::assertQueued(SantaNoteMail::class, fn (SantaNoteMail $mail) => $mail->kind === 'thanks' && $mail->hasTo($had->email));

        $this->actingAs($group->organiser)->get("/be-nl/santa/{$group->id}")
            ->assertInertia(fn ($page) => $page->where('group.guesses', ['made' => 1, 'right' => 1]));
    }

    #[Test]
    public function the_same_group_a_year_on_avoids_who_you_had(): void
    {
        $group = $this->group(['title' => 'Familie 2026', 'exchange_date' => now()->subDays(20)->toDateString()]);
        $sam = $this->join($group, 'Sam');
        $ash = $this->join($group, 'Ash');
        $this->draw($group);
        $before = $group->members()->get()->mapWithKeys(fn ($m) => [$m->email => $this->gifteeOf($m)->email]);
        Mail::fake();

        $this->actingAs($group->organiser)->post("/be-nl/santa/{$group->id}/again")->assertRedirect();
        $new = SecretSantaGroup::query()->where('previous_group_id', $group->id)->sole();

        $this->assertSame('Familie 2027', $new->title);
        $this->assertSame(2500, $new->budget_max);
        $this->assertTrue($new->exchange_date->isFuture());
        // Everybody is copied in; only the organiser counts as in again.
        $this->assertSame(3, $new->members()->count());
        $this->assertSame(1, $new->members()->whereNotNull('joined_at')->count());
        Mail::assertQueued(SantaNoteMail::class, fn (SantaNoteMail $mail) => $mail->kind === 'again', 2);

        // Pressed twice, it is still one new group.
        $this->actingAs($group->organiser)->post("/be-nl/santa/{$group->id}/again");
        $this->assertSame(1, SecretSantaGroup::query()->where('previous_group_id', $group->id)->count());
        auth()->logout();

        // Opening the link is not joining (mail scanners open links); the button is.
        foreach ($new->members()->whereNull('joined_at')->get() as $member) {
            $this->get($this->mine($new, $member))->assertInertia(fn ($page) => $page->where('me.joined', false));
            $this->assertNull($member->fresh()->joined_at);
            $this->post($this->mine($new, $member).'/in')->assertRedirect();
        }

        $this->draw($new);

        // Three people have two possible rings; last year's is not this year's.
        foreach ($new->members()->get() as $member) {
            $this->assertNotSame($before[$member->email], $this->gifteeOf($member)->email);
        }
    }

    #[Test]
    public function somebody_who_never_said_they_are_in_again_sits_the_draw_out(): void
    {
        $group = $this->group(['exchange_date' => now()->subDays(20)->toDateString()]);
        $this->join($group, 'Sam');
        $this->join($group, 'Ash');
        $this->draw($group);

        $new = app(SantaGame::class)->again($group);
        $sam = $new->members()->where('display_name', 'Sam')->sole();
        $this->post($this->mine($new, $sam).'/in')->assertRedirect();

        $this->draw($new);

        $this->assertSame(2, $new->members()->count());
        $this->assertNotNull($new->allMembers()->where('display_name', 'Ash')->sole()->removed_at);
    }

    #[Test]
    public function the_weekly_mail_goes_only_to_members_with_something_left_to_do(): void
    {
        $group = $this->group(['exchange_date' => now()->addDays(14)->toDateString()]);
        $sam = $this->join($group, 'Sam', ['interests' => ['games']]);
        $ash = $this->join($group, 'Ash');
        Mail::fake();

        // Before the draw: Sam gave tips, Ash and the organiser did not.
        $this->assertSame(2, app(SantaGame::class)->sendProgress());
        Mail::assertNotQueued(SantaNoteMail::class, fn (SantaNoteMail $mail) => $mail->hasTo($sam->email));
        Mail::assertQueued(SantaNoteMail::class, fn (SantaNoteMail $mail) => $mail->kind === 'progress' && $mail->hasTo($ash->email));
    }

    #[Test]
    public function the_homepage_features_the_group_draw(): void
    {
        $this->get('/be-nl')->assertOk();

        // The band is drawn client-side; what the server owes it is the words.
        $this->assertNotSame('site.home.santa_title', __('site.home.santa_title'));
        $this->assertStringContainsString('santa?new=1', (string) file_get_contents(resource_path('js/Pages/Home.tsx')));
    }
}

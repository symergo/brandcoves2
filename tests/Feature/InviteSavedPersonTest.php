<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RecipientStatus;
use App\Mail\FriendInviteMail;
use App\Models\FriendInvite;
use App\Models\Friendship;
use App\Models\Recipient;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Nodig uit op GiftCoves" on a saved person (owner's request, 2026-09-27).
 *
 * The invitation is the friends' own (`POST /friends`); what these hold is the
 * part that is new: when the invitation becomes a connection, the saved person
 * is linked to that account, so My people shows one person and not two. And
 * the guarantees around it: only your own, unlinked saved person; the answer
 * the same with or without an account; no duplicate when the account is
 * already saved as somebody else. See docs/features/my-people.md.
 */
class InviteSavedPersonTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->me = User::factory()->create(['name' => 'Anna', 'email' => 'anna@example.com']);
    }

    #[Test]
    public function an_address_with_an_account_links_the_saved_person_at_once(): void
    {
        $mum = $this->saved('Mama');
        $account = User::factory()->create(['email' => 'mama@example.com']);

        $this->invite($mum, 'Mama@Example.com')->assertSessionHas('success', __('site.friends.added'));

        $mum->refresh();
        $this->assertSame($account->id, $mum->user_id);
        $this->assertSame(RecipientStatus::Linked, $mum->status);
        $this->assertTrue(Friendship::query()->where('user_id', $this->me->id)->where('friend_id', $account->id)->exists());
        Mail::assertQueued(FriendInviteMail::class, fn (FriendInviteMail $mail) => $mail->hasTo('mama@example.com'));

        // One row: the friend joined Mama, not a second "f:" row beside her.
        $this->actingAs($this->me)->get('/be-nl/people')
            ->assertInertia(fn ($page) => $page
                ->has('people', 1)
                ->where('people.0.key', 'p:'.$mum->id)
                ->where('people.0.friend.id', $account->id)
                ->where('people.0.invitable', false));
    }

    #[Test]
    public function an_address_without_an_account_links_the_saved_person_at_sign_in(): void
    {
        $mum = $this->saved('Mama');

        $this->invite($mum, 'mama@example.com');

        $this->assertNull($mum->refresh()->user_id);
        $this->assertSame($mum->id, FriendInvite::query()->where('email', 'mama@example.com')->value('recipient_id'));

        $account = User::factory()->create(['email' => 'mama@example.com']);
        event(new Login('web', $account, false));

        $mum->refresh();
        $this->assertSame($account->id, $mum->user_id);
        $this->assertSame(RecipientStatus::Linked, $mum->status);
        $this->assertFalse(FriendInvite::query()->exists());

        $this->actingAs($this->me)->get('/be-nl/people')
            ->assertInertia(fn ($page) => $page
                ->has('people', 1)
                ->where('people.0.key', 'p:'.$mum->id)
                ->where('people.0.friend.id', $account->id));
    }

    #[Test]
    public function the_answer_is_the_same_with_or_without_an_account(): void
    {
        User::factory()->create(['email' => 'kim@example.com']);

        $known = $this->invite($this->saved('Kim'), 'kim@example.com');
        $unknown = $this->invite($this->saved('Bo'), 'bo@example.com');

        $this->assertSame(__('site.friends.added'), $known->getSession()->get('success'));
        $this->assertSame($known->getSession()->get('success'), $unknown->getSession()->get('success'));
        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame($known->headers->get('Location'), $unknown->headers->get('Location'));
        Mail::assertQueuedCount(2);
    }

    #[Test]
    public function somebody_elses_saved_person_is_refused_and_left_alone(): void
    {
        $other = User::factory()->create();
        $theirs = Recipient::create(['owner_user_id' => $other->id, 'name' => 'Theirs']);
        $account = User::factory()->create(['email' => 'target@example.com']);

        $this->actingAs($this->me)->from('/be-nl/people')->post('/be-nl/friends', [
            'email' => 'target@example.com',
            'recipient_id' => $theirs->id,
        ])->assertNotFound();

        $this->assertNull($theirs->refresh()->user_id);
        $this->assertSame(RecipientStatus::Stub, $theirs->status);
        // Nothing recorded, nothing sent.
        $this->assertFalse(Friendship::query()->where('user_id', $this->me->id)->where('friend_id', $account->id)->exists());
        Mail::assertNothingQueued();
    }

    #[Test]
    public function a_person_already_linked_gets_no_invite_option_and_is_never_re_pointed(): void
    {
        $friend = User::factory()->create(['email' => 'sam@example.com']);
        $sam = $this->saved('Sam', ['user_id' => $friend->id, 'status' => RecipientStatus::Linked]);
        $unlinked = $this->saved('Bo');

        $this->actingAs($this->me)->get('/be-nl/people')
            ->assertInertia(fn ($page) => $page
                ->where('people', fn ($people) => collect($people)->firstWhere('key', 'p:'.$sam->id)['invitable'] === false
                    && collect($people)->firstWhere('key', 'p:'.$unlinked->id)['invitable'] === true));

        $this->actingAs($this->me)->get("/be-nl/people/{$sam->id}")
            ->assertInertia(fn ($page) => $page->where('urls.invite', null));
        $this->actingAs($this->me)->get("/be-nl/people/{$unlinked->id}")
            ->assertInertia(fn ($page) => $page->where('urls.invite', '/be-nl/friends'));

        // A stale page posting it anyway: the invitation goes, Sam stays Sam.
        $other = User::factory()->create(['email' => 'other@example.com']);
        $this->invite($sam, 'other@example.com')->assertSessionHas('success');

        $this->assertSame($friend->id, $sam->refresh()->user_id);
        $this->assertTrue(Friendship::query()->where('user_id', $this->me->id)->where('friend_id', $other->id)->exists());
    }

    #[Test]
    public function an_account_already_saved_as_somebody_else_is_not_linked_twice(): void
    {
        $account = User::factory()->create(['email' => 'sam@example.com']);
        $first = $this->saved('Sam', ['user_id' => $account->id, 'status' => RecipientStatus::Linked]);
        $second = $this->saved('Sammy');

        // Now, and at a later sign-in: neither may fail, neither may link.
        $this->invite($second, 'sam@example.com')->assertSessionHas('success');
        event(new Login('web', $account, false));

        $this->assertSame($account->id, $first->refresh()->user_id);
        $this->assertNull($second->refresh()->user_id);
        $this->assertSame(RecipientStatus::Stub, $second->status);
    }

    #[Test]
    public function a_birthday_typed_in_the_form_is_kept_on_a_person_who_had_none(): void
    {
        $mum = $this->saved('Mama');
        $dad = $this->saved('Papa', ['birthday' => Recipient::birthdayFrom(3, 4)]);

        $this->invite($mum, 'mama@example.com', '05-12');
        $this->invite($dad, 'papa@example.com', '06-01');

        $this->assertSame('05-12', $mum->refresh()->birthday?->format('m-d'));
        // The one already saved is what the reminder email reads: not replaced.
        $this->assertSame('04-03', $dad->refresh()->birthday?->format('m-d'));
    }

    #[Test]
    public function deleting_the_person_before_they_join_leaves_the_invitation_standing(): void
    {
        $mum = $this->saved('Mama');
        $this->invite($mum, 'mama@example.com');

        $mum->delete();
        $this->assertNull(FriendInvite::query()->value('recipient_id'));

        $account = User::factory()->create(['email' => 'mama@example.com']);
        event(new Login('web', $account, false));

        $this->assertTrue(Friendship::query()->where('user_id', $this->me->id)->where('friend_id', $account->id)->exists());
    }

    #[Test]
    public function the_plain_form_does_not_forget_whose_invitation_it_was(): void
    {
        $mum = $this->saved('Mama');
        $this->invite($mum, 'mama@example.com');

        // The same address again from "Nodig uit" at the top of the page.
        $this->actingAs($this->me)->from('/be-nl/people')->post('/be-nl/friends', ['email' => 'mama@example.com']);

        $this->assertSame($mum->id, FriendInvite::query()->value('recipient_id'));
    }

    /** @param  array<string, mixed>  $attributes */
    private function saved(string $name, array $attributes = []): Recipient
    {
        return Recipient::create(['owner_user_id' => $this->me->id, 'name' => $name, ...$attributes]);
    }

    private function invite(Recipient $person, string $email, ?string $birthday = null): TestResponse
    {
        return $this->actingAs($this->me)->from('/be-nl/people')->post('/be-nl/friends', [
            'email' => $email,
            'birthday' => $birthday,
            'recipient_id' => $person->id,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Mail\FriendInviteMail;
use App\Models\FriendInvite;
use App\Models\Friendship;
use App\Models\InviteComplaint;
use App\Models\User;
use App\Services\Social\InviteMailer;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Inviting somebody by email sends them an email (owner's request, 2026-09-26).
 *
 * What these hold: the email goes out and reads the same whether or not the
 * address has an account; the two limits and "never your own address"; the
 * "no more invitations" link, which needs no account, is signed and silences an
 * address for every member; only the separate spam button on its page counts a
 * complaint against the member; enough complaints stop a member's
 * emails without the member being able to tell; and the invitation still
 * turns into a connection when the person signs in.
 * See docs/features/friend-invite-mail.md.
 */
class FriendInviteMailTest extends TestCase
{
    use RefreshDatabase;

    private User $anna;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->anna = User::factory()->create(['name' => 'Anna', 'email' => 'anna@example.com']);
    }

    #[Test]
    public function inviting_an_address_emails_it(): void
    {
        $this->invite($this->anna, 'Bo@Example.com')->assertSessionHas('success', __('site.friends.added'));

        Mail::assertQueued(FriendInviteMail::class, function (FriendInviteMail $mail) {
            $html = $mail->render();

            return $mail->hasTo('bo@example.com')
                && $mail->envelope()->subject === 'Anna nodigt je uit op GiftCoves'
                // The button: the sign-in page, address filled in.
                && $mail->url === url('/be-nl/login?email=bo%40example.com')
                && str_contains($html, 'Uitnodiging aannemen')
                && str_contains($html, 'Wil je geen uitnodigingen meer ontvangen?')
                // RFC 8058 one-click, pointing at the same signed link.
                && $mail->headers()->text['List-Unsubscribe'] === "<{$mail->notWantedUrl}>"
                && $mail->headers()->text['List-Unsubscribe-Post'] === 'List-Unsubscribe=One-Click';
        });
    }

    #[Test]
    public function the_email_and_the_answer_are_the_same_with_or_without_an_account(): void
    {
        User::factory()->create(['email' => 'kim@example.com']);

        $known = $this->invite($this->anna, 'kim@example.com');
        $unknown = $this->invite($this->anna, 'nobody@example.com');

        $this->assertSame($known->getSession()->get('success'), $unknown->getSession()->get('success'));

        $bodies = [];
        foreach (['kim@example.com', 'nobody@example.com'] as $address) {
            Mail::assertQueued(FriendInviteMail::class, function (FriendInviteMail $mail) use ($address, &$bodies) {
                if (! $mail->hasTo($address)) {
                    return false;
                }

                // Everything but the two links, which carry the address.
                $bodies[$address] = str_replace([e($mail->url), e($mail->notWantedUrl), $mail->url, $mail->notWantedUrl], '', $mail->render());

                return true;
            });
        }

        $this->assertSame($bodies['kim@example.com'], $bodies['nobody@example.com']);
    }

    #[Test]
    public function the_invitation_still_becomes_a_connection_at_sign_in(): void
    {
        $this->invite($this->anna, 'later@example.com');

        // The email's button: the sign-in page with the address filled in,
        // opened by somebody who is not signed in.
        $this->app['auth']->forgetGuards();
        $this->get('/be-nl/login?email=later@example.com')
            ->assertInertia(fn ($page) => $page->where('email', 'later@example.com'));
        $this->get('/be-nl/login?email=not-an-address')
            ->assertInertia(fn ($page) => $page->where('email', null));

        $later = User::factory()->create(['email' => 'later@example.com']);
        $this->actingAs($later);
        event(new Login('web', $later, false));

        $this->assertTrue(Friendship::query()->where('user_id', $this->anna->id)->where('friend_id', $later->id)->exists());
    }

    #[Test]
    public function the_same_address_is_emailed_once_a_month(): void
    {
        $this->invite($this->anna, 'bo@example.com');
        $this->invite($this->anna, 'bo@example.com')
            ->assertSessionHas('success', __('site.people.invite_again', ['days' => 30]));

        Mail::assertQueuedCount(1);

        $this->travel(31)->days();
        $this->invite($this->anna, 'bo@example.com')->assertSessionHas('success', __('site.friends.added'));

        Mail::assertQueuedCount(2);
    }

    #[Test]
    public function a_member_invites_at_most_the_daily_limit(): void
    {
        config(['giftcoves.invites.daily_limit' => 3]);

        foreach (range(1, 3) as $n) {
            $this->invite($this->anna, "friend{$n}@example.com")->assertSessionHasNoErrors();
        }

        $this->invite($this->anna, 'friend4@example.com')->assertSessionHasErrors('email');
        Mail::assertQueuedCount(3);
        $this->assertDatabaseMissing('friend_invites', ['email' => 'friend4@example.com']);

        $this->travel(25)->hours();
        $this->invite($this->anna, 'friend4@example.com')->assertSessionHasNoErrors();
        Mail::assertQueuedCount(4);
    }

    #[Test]
    public function your_own_address_is_never_emailed(): void
    {
        $this->invite($this->anna, 'ANNA@example.com')->assertSessionHasErrors('email');

        Mail::assertNothingQueued();
        $this->assertSame(0, FriendInvite::query()->count());
        $this->assertSame(0, DB::table('friend_invite_mails')->count());
    }

    #[Test]
    public function the_no_more_invitations_link_silences_the_address_for_everybody_without_a_complaint(): void
    {
        $this->invite($this->anna, 'bo@example.com');
        $url = $this->notWantedUrlFor('bo@example.com');

        // Opening the link is not pressing it: mail scanners open every link.
        $this->get($url)->assertOk()->assertInertia(fn ($page) => $page
            ->component('Invites/NotWanted')
            ->where('stopped', false)
            ->where('reported', false));
        $this->assertSame(0, DB::table('invite_suppressions')->count());

        // The press, with no account and no session. Saying "no thanks" is
        // not a complaint against the friend who invited (owner, 2026-09-27).
        $this->post($url)->assertRedirect();
        $this->get($url)->assertInertia(fn ($page) => $page->where('stopped', true)->where('reported', false));

        $this->assertSame(0, InviteComplaint::query()->count());
        $this->assertDatabaseHas('invite_suppressions', ['email_hash' => InviteMailer::hash('bo@example.com')]);
        // A hash, never the address.
        $this->assertDatabaseMissing('invite_suppressions', ['email_hash' => 'bo@example.com']);

        // Another member invites the same address: recorded, answered as
        // always, and not emailed.
        $carl = User::factory()->create(['email' => 'carl@example.com']);
        $this->invite($carl, 'Bo@example.com')->assertSessionHas('success', __('site.friends.added'));

        Mail::assertQueuedCount(1);
        $this->assertDatabaseHas('friend_invites', ['inviter_id' => $carl->id, 'email' => 'bo@example.com']);
        $this->assertDatabaseHas('friend_invite_mails', ['inviter_id' => $carl->id, 'status' => 'suppressed']);
    }

    #[Test]
    public function the_spam_button_stops_the_invitations_and_counts_one_complaint(): void
    {
        $this->invite($this->anna, 'bo@example.com');
        $url = $this->notWantedUrlFor('bo@example.com');

        $this->post($this->spamUrlFor('bo@example.com'))->assertRedirect($url);
        $this->get($url)->assertInertia(fn ($page) => $page->where('stopped', true)->where('reported', true));

        $this->assertSame(1, InviteComplaint::query()->where('inviter_id', $this->anna->id)->count());
        $this->assertDatabaseHas('invite_suppressions', ['email_hash' => InviteMailer::hash('bo@example.com')]);

        // Pressing again is still one complaint.
        $this->post($this->spamUrlFor('bo@example.com'));
        $this->assertSame(1, InviteComplaint::query()->count());
    }

    #[Test]
    public function a_tampered_spam_link_is_refused(): void
    {
        $this->invite($this->anna, 'bo@example.com');

        foreach ([$this->notWantedUrlFor('bo@example.com'), $this->spamUrlFor('bo@example.com')] as $url) {
            $otherMember = str_replace("/not-wanted/{$this->anna->id}/", '/not-wanted/999/', $url);
            $otherAddress = str_replace(InviteMailer::hash('bo@example.com'), InviteMailer::hash('x@example.com'), $url);
            $unsigned = strtok($url, '?');

            foreach ([$otherMember, $otherAddress, $unsigned] as $bad) {
                $this->post($bad)->assertForbidden();
            }
        }

        $this->assertSame(0, InviteComplaint::query()->count());
        $this->assertSame(0, DB::table('invite_suppressions')->count());
    }

    #[Test]
    public function enough_complaints_stop_a_members_emails_without_telling_them(): void
    {
        config(['giftcoves.invites.complaint_limit' => 3]);

        foreach (['a', 'b', 'c'] as $who) {
            $this->invite($this->anna, "{$who}@example.com");
            $this->post($this->spamUrlFor("{$who}@example.com"));
        }

        Mail::assertQueuedCount(3);

        $this->invite($this->anna, 'd@example.com')->assertSessionHas('success', __('site.friends.added'));

        Mail::assertQueuedCount(3);
        $this->assertDatabaseHas('friend_invites', ['inviter_id' => $this->anna->id, 'email' => 'd@example.com']);
        $this->assertDatabaseHas('friend_invite_mails', ['inviter_id' => $this->anna->id, 'status' => 'sender_muted']);

        // Somebody else's invitations are untouched.
        $carl = User::factory()->create(['email' => 'carl@example.com']);
        $this->invite($carl, 'e@example.com');
        Mail::assertQueuedCount(4);
    }

    #[Test]
    public function the_undo_lets_invitations_through_again_and_withdraws_the_complaint(): void
    {
        $this->invite($this->anna, 'bo@example.com');
        $url = $this->notWantedUrlFor('bo@example.com');
        $this->post($this->spamUrlFor('bo@example.com'));
        $this->assertSame(1, InviteComplaint::query()->count());

        $undo = app(InviteMailer::class)->undoUrl($this->anna->id, InviteMailer::hash('bo@example.com'), Market::BeNl);
        $this->post($undo)->assertRedirect($url);

        $this->assertSame(0, InviteComplaint::query()->count());
        $this->assertSame(0, DB::table('invite_suppressions')->count());

        $carl = User::factory()->create(['email' => 'carl@example.com']);
        $this->invite($carl, 'bo@example.com');
        Mail::assertQueuedCount(2);
    }

    #[Test]
    public function an_admin_sees_who_was_complained_about(): void
    {
        $this->invite($this->anna, 'bo@example.com');
        $this->post($this->spamUrlFor('bo@example.com'));

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $admin->forceFill(['is_admin' => true])->save();

        $this->actingAs($admin)->get('/admin/invite-complaints')
            ->assertOk()
            ->assertSee('anna@example.com');
    }

    #[Test]
    public function pruning_keeps_the_suppression_list_and_clears_the_rest_on_time(): void
    {
        $this->invite($this->anna, 'bo@example.com');
        $this->post($this->spamUrlFor('bo@example.com'));

        $this->travel(400)->days();
        $this->artisan('bc:prune-personal-data')->assertSuccessful();

        $this->assertSame(0, FriendInvite::query()->count());
        $this->assertSame(0, DB::table('friend_invite_mails')->count());
        $this->assertSame(0, InviteComplaint::query()->count());
        $this->assertSame(1, DB::table('invite_suppressions')->count());
    }

    private function invite(User $who, string $email): TestResponse
    {
        return $this->actingAs($who)->from('/be-nl/people')->post('/be-nl/friends', ['email' => $email]);
    }

    /** The link as it went out in the queued email to this address. */
    private function notWantedUrlFor(string $email): string
    {
        $url = null;

        Mail::assertQueued(FriendInviteMail::class, function (FriendInviteMail $mail) use ($email, &$url) {
            if ($mail->hasTo($email)) {
                $url = $mail->notWantedUrl;

                return true;
            }

            return false;
        });

        // Signed-out from here on: the reader of an email has no session here.
        $this->app['auth']->forgetGuards();

        return (string) $url;
    }

    /** The spam button on the page that link opens, for an invitation from Anna. */
    private function spamUrlFor(string $email): string
    {
        $this->app['auth']->forgetGuards();

        return app(InviteMailer::class)->spamUrl($this->anna->id, InviteMailer::hash($email), Market::BeNl);
    }
}

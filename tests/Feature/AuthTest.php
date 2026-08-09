<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Mail\MagicLinkMail;
use App\Models\AnonymousIdentity;
use App\Models\LoginToken;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * Passwordless sign-in, and the merge that makes "useful before you sign up"
 * true rather than a slogan.
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    #[Test]
    public function a_mail_transport_failure_is_reported_rather_than_thrown(): void
    {
        Mail::shouldReceive('to->send')
            ->andThrow(new TransportException('down'));

        /*
         * The link is sent inline because it expires in fifteen minutes, so a
         * broken transport lands in the request. It landed as a 500 on the one
         * form whose whole job is to be the way in.
         */
        $this->post('/be-nl/login', ['email' => 'someone@example.test'])
            ->assertSessionHasErrors('email');
    }

    #[Test]
    public function requesting_a_link_sends_one(): void
    {
        $this->post('/be-nl/login', ['email' => 'someone@example.test'])
            ->assertRedirect();

        Mail::assertSent(MagicLinkMail::class);
        $this->assertDatabaseCount('login_tokens', 1);
    }

    #[Test]
    public function the_response_is_identical_for_unknown_addresses(): void
    {
        User::create(['email' => 'known@example.test']);

        $known = $this->post('/be-nl/login', ['email' => 'known@example.test']);
        $unknown = $this->post('/be-nl/login', ['email' => 'nobody@example.test']);

        // Anything else turns this form into an account-existence oracle:
        // "does this person have an account here" is not ours to disclose.
        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame(
            session()->get('success'),
            $unknown->getSession()->get('success'),
        );
    }

    #[Test]
    public function a_link_signs_you_in_and_creates_the_account(): void
    {
        $this->post('/be-nl/login', ['email' => 'new@example.test']);

        $token = null;
        Mail::assertSent(MagicLinkMail::class, function (MagicLinkMail $mail) use (&$token) {
            $token = $mail->token;

            return true;
        });

        $this->get("/be-nl/auth/magic/{$token}")->assertRedirect('/be-nl/lists');

        $this->assertAuthenticated();
        $user = User::query()->where('email', 'new@example.test')->firstOrFail();
        // A magic link IS proof of mailbox control.
        $this->assertNotNull($user->email_verified_at);
    }

    #[Test]
    public function a_link_works_exactly_once(): void
    {
        $this->post('/be-nl/login', ['email' => 'once@example.test']);
        $token = null;
        Mail::assertSent(MagicLinkMail::class, function ($mail) use (&$token) {
            $token = $mail->token;

            return true;
        });

        $this->get("/be-nl/auth/magic/{$token}")->assertRedirect('/be-nl/lists');

        $this->post('/be-nl/logout');

        // A login link lands in an inbox, in forwarded mail, and in the logs of
        // every proxy it passes through. It has to die on first use.
        $this->get("/be-nl/auth/magic/{$token}")->assertRedirect('/be-nl/login');
        $this->assertGuest();
    }

    #[Test]
    public function an_expired_link_is_refused(): void
    {
        ['token' => $token, 'model' => $model] = LoginToken::issue('old@example.test');
        $model->update(['expires_at' => now()->subMinute()]);

        $this->get("/be-nl/auth/magic/{$token}")->assertRedirect('/be-nl/login');
        $this->assertGuest();
    }

    #[Test]
    public function requesting_a_new_link_kills_the_previous_one(): void
    {
        ['token' => $first] = LoginToken::issue('a@example.test');
        LoginToken::issue('a@example.test');

        // "It didn't arrive, send another" is the normal flow, and leaving the
        // old link live widens the window for no benefit.
        $this->get("/be-nl/auth/magic/{$first}")->assertRedirect('/be-nl/login');
        $this->assertGuest();
    }

    #[Test]
    public function the_plaintext_token_is_never_stored(): void
    {
        ['token' => $token] = LoginToken::issue('hash@example.test');

        // A database leak must not hand over live login links.
        $this->assertDatabaseMissing('login_tokens', ['token_hash' => $token]);
        $this->assertDatabaseHas('login_tokens', ['token_hash' => hash('sha256', $token)]);
    }

    #[Test]
    public function email_matching_is_case_insensitive(): void
    {
        User::create(['email' => 'mixed@example.test']);

        ['token' => $token] = LoginToken::issue('MIXED@Example.Test');
        $this->get("/be-nl/auth/magic/{$token}");

        // Otherwise one human gets two accounts with half a gift list each.
        $this->assertSame(1, User::query()->count());
        $this->assertAuthenticated();
    }

    #[Test]
    public function work_done_anonymously_survives_signing_up(): void
    {
        // Build a list without an account, exactly as a visitor would.
        $this->get('/be-nl');
        $anon = AnonymousIdentity::query()->firstOrFail();

        $recipient = Recipient::create([
            'owner_anon_id' => $anon->id,
            'name' => 'Mum',
        ]);
        $list = Wishlist::create([
            'owner_anon_id' => $anon->id,
            'recipient_id' => $recipient->id,
            'title' => 'Birthday',
            'market' => Market::BeNl,
        ]);

        ['token' => $token] = LoginToken::issue('merge@example.test');
        $this->withCookie('bc_visitor', $anon->id)->get("/be-nl/auth/magic/{$token}");

        $user = User::query()->where('email', 'merge@example.test')->firstOrFail();

        // Losing a list someone built themselves is the worst moment this
        // product can produce.
        $this->assertSame($user->id, $list->fresh()->owner_user_id);
        $this->assertNull($list->fresh()->owner_anon_id);
        $this->assertSame($user->id, $recipient->fresh()->owner_user_id);
        $this->assertNotNull($anon->fresh()->merged_at);
    }

    #[Test]
    public function merging_twice_does_not_re_parent_anything(): void
    {
        $this->get('/be-nl');
        $anon = AnonymousIdentity::query()->firstOrFail();
        $other = User::create(['email' => 'other@example.test']);

        ['token' => $first] = LoginToken::issue('first@example.test');
        $this->withCookie('bc_visitor', $anon->id)->get("/be-nl/auth/magic/{$first}");
        $this->post('/be-nl/logout');

        Wishlist::create([
            'owner_user_id' => $other->id,
            'title' => 'Someone else',
            'market' => Market::BeNl,
        ]);

        ['token' => $second] = LoginToken::issue('second@example.test');
        $this->withCookie('bc_visitor', $anon->id)->get("/be-nl/auth/magic/{$second}");

        // A second sign-in from the same browser must not move a third party's
        // data onto the new account.
        $this->assertSame(
            $other->id,
            Wishlist::query()->where('title', 'Someone else')->value('owner_user_id'),
        );
    }

    #[Test]
    public function too_many_requests_are_refused(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/be-nl/login', ['email' => 'flood@example.test']);
        }

        // Protects the mailbox of whoever's address is being entered.
        $this->post('/be-nl/login', ['email' => 'flood@example.test'])
            ->assertSessionHasErrors('email');
    }

    #[Test]
    public function the_google_button_is_hidden_without_credentials(): void
    {
        config(['services.google.client_id' => null]);

        $this->get('/be-nl/login')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('googleEnabled', false));

        // And the route itself is gone, not just the button.
        $this->get('/be-nl/auth/google')->assertNotFound();
    }
}

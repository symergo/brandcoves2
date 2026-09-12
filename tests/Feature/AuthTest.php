<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Enums\Source;
use App\Mail\MagicLinkMail;
use App\Mail\NewRegistrationMail;
use App\Models\AnonymousIdentity;
use App\Models\GiftPledge;
use App\Models\ListItemVote;
use App\Models\LoginToken;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
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

        /*
         * And things done on somebody else's list: a pledge towards a group
         * gift, a vote on which one to buy. Until 2026-09-06 these stayed on
         * the cookie identity, which is never resolved again after sign-in —
         * so the pledge became money the person could not see or withdraw.
         */
        $theirs = Wishlist::create([
            'owner_user_id' => User::factory()->create()->id,
            'title' => 'Office gift',
            'market' => Market::BeNl,
        ]);
        $item = WishlistItem::create([
            'wishlist_id' => $theirs->id,
            'source' => Source::Manual,
            'snapshot_title' => 'Espresso machine',
            'accepted_at' => now(),
        ]);
        $other = WishlistItem::create([
            'wishlist_id' => $theirs->id,
            'source' => Source::Manual,
            'snapshot_title' => 'Grinder',
            'accepted_at' => now(),
        ]);
        $pledge = GiftPledge::create([
            'wishlist_id' => $theirs->id,
            'anon_id' => $anon->id,
            'display_name' => 'Me',
            'amount' => 2500,
        ]);
        $vote = ListItemVote::create(['item_id' => $item->id, 'anon_id' => $anon->id]);
        $duplicate = ListItemVote::create(['item_id' => $other->id, 'anon_id' => $anon->id]);

        ['token' => $token] = LoginToken::issue('merge@example.test');

        // The account has already voted on the grinder from another device,
        // so the anonymous vote there is a duplicate of one it holds.
        $user = User::factory()->create(['email' => 'merge@example.test']);
        ListItemVote::create(['item_id' => $other->id, 'user_id' => $user->id]);

        $this->withCookie('bc_visitor', $anon->id)->get("/be-nl/auth/magic/{$token}");

        // Losing a list someone built themselves is the worst moment this
        // product can produce.
        $this->assertSame($user->id, $list->fresh()->owner_user_id);
        $this->assertNull($list->fresh()->owner_anon_id);
        $this->assertSame($user->id, $recipient->fresh()->owner_user_id);
        $this->assertNotNull($anon->fresh()->merged_at);

        $this->assertSame($user->id, $pledge->fresh()->user_id);
        $this->assertNull($pledge->fresh()->anon_id);
        $this->assertSame($user->id, $vote->fresh()->user_id);
        // Dropped rather than re-parented into a unique-index collision.
        $this->assertNull($duplicate->fresh());
        $this->assertSame(1, ListItemVote::query()->where('item_id', $other->id)->count());
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
        $this->get('/auth/google/callback')->assertNotFound();
    }

    #[Test]
    public function the_callback_is_registered_where_google_is_told_to_send_people(): void
    {
        /*
         * GOOGLE_REDIRECT_URI is "${APP_URL}/auth/google/callback", with no
         * market segment — and for a while the only callback route was inside
         * the {market} prefix, so that URI matched nothing. Sign-in was not
         * broken subtly; every visitor came back from Google to a 404, and it
         * would have been invisible until the day credentials were first set.
         *
         * Socialite still rejects this request — there is no OAuth state on it —
         * which is exactly the path a cancelled consent screen takes: back to
         * the login page, in the market the visitor left from.
         */
        $this->configureGoogle();

        $this->withSession(['auth.market' => 'be-nl'])
            ->get('/auth/google/callback')
            ->assertRedirect('/be-nl/login');
    }

    #[Test]
    public function a_callback_with_no_session_left_still_goes_somewhere_real(): void
    {
        /*
         * The session carries the market across the round-trip, and a slow
         * visitor can outlive it. With the {market} route gone there is no
         * segment to fall back on, so an unguarded null built "//login" — which
         * a browser reads as protocol-relative and resolves against a host
         * called "login", sending the visitor off-site entirely.
         */
        $this->configureGoogle();

        $location = $this->get('/auth/google/callback')->headers->get('Location');

        $this->assertNotNull($location);
        $this->assertMatchesRegularExpression(
            '#^(https?://[^/]+)?/('.implode('|', array_map('preg_quote', Market::values())).')/login$#',
            $location,
        );
    }

    private function configureGoogle(): void
    {
        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.redirect' => 'http://localhost/auth/google/callback',
        ]);
    }

    #[Test]
    public function opening_the_login_page_while_signed_in_goes_home(): void
    {
        /*
         * It used to 500.
         *
         * Laravel's guest middleware sends an authenticated visitor to
         * `route('home')`, which is `/{market}` here and cannot be generated
         * without a market — `UrlGenerationException`, straight out as a server
         * error. The mirror case, a guest hitting an auth-only route, was fixed
         * long ago; this direction was not, and nothing opened the page while
         * signed in to notice.
         *
         * Easy to reach: a bookmarked login page, a stale "Sign in" link, or a
         * magic-link email opened after signing in on another tab.
         */
        $this->actingAs(User::factory()->create())
            ->get('/be-nl/login')
            ->assertRedirect('/be-nl');
    }

    #[Test]
    public function signing_out_returns_to_the_market_you_were_in(): void
    {
        $user = User::factory()->create();

        // Nothing linked to this route until the account menu existed, so it
        // had never been exercised from the interface at all.
        $this->actingAs($user)->post('/be-nl/logout')->assertRedirect();
        $this->assertGuest();
    }

    #[Test]
    public function the_link_sent_message_is_rendered_once(): void
    {
        /*
         * "Check your inbox…" appeared twice, one line above the other.
         *
         * `SiteLayout` renders `<FlashMessage />` above every page, and
         * `Login.tsx` had its own copy of `flash.success` from before that
         * component existed — so sending a magic link printed the same sentence
         * from both.
         *
         * Asserted against the source rather than the rendered DOM: the banner
         * is drawn client-side from a shared prop, so there is nothing in the
         * HTML response to count. What matters is the rule — the layout owns
         * flash, and a page does not render it a second time.
         */
        $login = file_get_contents(resource_path('js/Pages/Auth/Login.tsx'));

        $this->assertStringNotContainsString('{flash.success}', $login);

        // And the server still says it, so removing the duplicate did not
        // remove the message.
        $this->post('/be-nl/login', ['email' => 'someone@example.test'])
            ->assertRedirect()
            ->assertSessionHas('success', __('site.auth.link_sent'));
    }

    #[Test]
    public function a_first_sign_in_by_magic_link_is_reported_as_a_sign_up_and_a_second_is_not(): void
    {
        /*
         * Registrations are counted as a GA4 conversion from the page after
         * the redirect: the callback flashes how the new account signed in,
         * the page carries it as flash.signUp for exactly one request, and the
         * client fires sign_up. A returning sign-in must stay silent, or every
         * login would count as a registration. The owner's email follows the
         * same rule, from the same place.
         */
        config(['giftcoves.registrations.notify' => 'owner@example.test']);

        $this->get('/be-nl/auth/magic/'.$this->requestLink('new@example.test'))
            ->assertRedirect('/be-nl/lists')
            ->assertSessionHas('signed_up', 'email');

        Mail::assertQueued(NewRegistrationMail::class, fn (NewRegistrationMail $mail) => $mail->hasTo('owner@example.test')
            && $mail->email === 'new@example.test'
            && $mail->method === 'email'
            && $mail->market === 'be-nl'
            && $mail->total === 1);

        $this->get('/be-nl/lists')
            ->assertInertia(fn ($page) => $page->where('flash.signUp', 'email'));

        // One request only: a reload must not report the sign-up again.
        $this->get('/be-nl/lists')
            ->assertInertia(fn ($page) => $page->where('flash.signUp', null));

        $this->post('/be-nl/logout');

        $this->get('/be-nl/auth/magic/'.$this->requestLink('new@example.test'))
            ->assertRedirect('/be-nl/lists')
            ->assertSessionMissing('signed_up');
        Mail::assertNotQueued(NewRegistrationMail::class);

        $this->assertSame(1, User::query()->where('email', 'new@example.test')->count());
    }

    #[Test]
    public function a_first_sign_in_with_google_is_reported_as_a_sign_up_and_a_second_is_not(): void
    {
        $this->configureGoogle();
        Mail::fake();
        config(['giftcoves.registrations.notify' => 'owner@example.test']);

        $googleUser = (new GoogleUser)->map([
            'email' => 'new@example.test',
            'name' => 'New Person',
            'avatar' => 'https://lh3.googleusercontent.com/a/photo',
        ]);
        Socialite::shouldReceive('driver->user')->andReturn($googleUser);

        $this->withSession(['auth.market' => 'be-nl'])
            ->get('/auth/google/callback')
            ->assertRedirect('/be-nl/lists')
            ->assertSessionHas('signed_up', 'google');

        Mail::assertQueued(NewRegistrationMail::class, fn (NewRegistrationMail $mail) => $mail->hasTo('owner@example.test')
            && $mail->name === 'New Person'
            && $mail->method === 'google');

        $this->post('/be-nl/logout');

        $this->withSession(['auth.market' => 'be-nl'])
            ->get('/auth/google/callback')
            ->assertRedirect('/be-nl/lists')
            ->assertSessionMissing('signed_up');

        // Once. A returning Google sign-in is not a registration either.
        Mail::assertQueued(NewRegistrationMail::class, 1);

        $this->assertSame(1, User::query()->where('email', 'new@example.test')->count());
    }

    /** Ask for a magic link and return its token, the way a mailbox would hand it over. */
    private function requestLink(string $email): string
    {
        Mail::fake();
        $this->post('/be-nl/login', ['email' => $email]);

        $token = null;
        Mail::assertSent(MagicLinkMail::class, function (MagicLinkMail $mail) use (&$token) {
            $token = $mail->token;

            return true;
        });

        $this->assertNotNull($token);

        return $token;
    }

    #[Test]
    public function nobody_is_mailed_about_a_registration_unless_an_address_is_configured(): void
    {
        config(['giftcoves.registrations.notify' => null]);

        $this->get('/be-nl/auth/magic/'.$this->requestLink('new@example.test'))
            ->assertRedirect('/be-nl/lists')
            ->assertSessionHas('signed_up', 'email');

        Mail::assertNothingQueued();
        $this->assertAuthenticated();
    }
}

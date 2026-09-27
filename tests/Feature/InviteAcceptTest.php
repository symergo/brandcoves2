<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RecipientStatus;
use App\Mail\FriendInviteMail;
use App\Models\FriendInvite;
use App\Models\FriendInviteToken;
use App\Models\Friendship;
use App\Models\Recipient;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tests\TestCase;

/**
 * "Uitnodiging aannemen" signs a new invitee straight in (owner, 2026-09-27).
 *
 * What these hold: opening the link consumes nothing; pressing the button
 * creates the account, signs it in and turns the invitation into the
 * connection (and the saved person) exactly as a magic link would; the button
 * works once and for `accept_days`; it never signs in an existing account;
 * and a signed-in visitor is not switched silently.
 * See docs/features/friend-invite-mail.md, "Accepting in one press".
 */
class InviteAcceptTest extends TestCase
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
    public function opening_the_link_consumes_nothing(): void
    {
        $url = $this->invitedAs('bo@example.com');

        $this->get($url)->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Invites/Accept')
                ->where('inviterName', 'Anna')
                ->where('signedInAs', null)
                ->where('acceptUrl', parse_url($url, PHP_URL_PATH)));
        $this->get($url)->assertOk();

        $this->assertGuest();
        $this->assertNull(FriendInviteToken::query()->value('used_at'));
        $this->assertFalse(User::query()->where('email', 'bo@example.com')->exists());
    }

    #[Test]
    public function pressing_the_button_creates_the_account_signs_in_and_connects(): void
    {
        $mum = Recipient::create(['owner_user_id' => $this->anna->id, 'name' => 'Mama']);
        $this->actingAs($this->anna)->from('/be-nl/people')->post('/be-nl/friends', [
            'email' => 'Mama@Example.com',
            'recipient_id' => $mum->id,
        ]);
        $url = $this->buttonFor('mama@example.com');

        $this->get($url);
        $this->post($url)->assertRedirect('/be-nl/people');

        $mama = User::query()->where('email', 'mama@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($mama);
        $this->assertNotNull($mama->email_verified_at);

        // The connection and the saved person, made by LinkSharerAsFriend on
        // the Login event, as for a magic link.
        $this->assertTrue(Friendship::query()->where('user_id', $this->anna->id)->where('friend_id', $mama->id)->exists());
        $mum->refresh();
        $this->assertSame($mama->id, $mum->user_id);
        $this->assertSame(RecipientStatus::Linked, $mum->status);
        $this->assertFalse(FriendInvite::query()->exists());
    }

    #[Test]
    public function the_button_works_once(): void
    {
        $url = $this->invitedAs('bo@example.com');

        $this->post($url)->assertRedirect('/be-nl/people');
        $this->post('/be-nl/logout');
        $this->app['auth']->forgetGuards();

        $this->post($url)->assertRedirect('/be-nl/login?email=bo%40example.com');
        $this->assertGuest();
        $this->get($url)->assertRedirect('/be-nl/login?email=bo%40example.com');
    }

    #[Test]
    public function an_expired_button_leads_to_the_sign_in_page(): void
    {
        $url = $this->invitedAs('bo@example.com');

        $this->travel((int) config('giftcoves.invites.accept_days') + 1)->days();

        $this->get($url)->assertRedirect('/be-nl/login?email=bo%40example.com')
            ->assertSessionHas('status', __('site.invite_accept.sign_in_instead'));
        $this->post($url)->assertRedirect('/be-nl/login?email=bo%40example.com');
        $this->assertGuest();
        $this->assertFalse(User::query()->where('email', 'bo@example.com')->exists());
    }

    #[Test]
    public function an_existing_account_is_never_signed_in_by_the_button(): void
    {
        $kim = User::factory()->create(['email' => 'kim@example.com']);
        $url = $this->invitedAs('kim@example.com');

        $this->get($url)->assertRedirect('/be-nl/login?email=kim%40example.com');
        $this->post($url)->assertRedirect('/be-nl/login?email=kim%40example.com');
        $this->assertGuest();

        // An account that appeared between the email and the press: the same.
        $url = $this->invitedAs('late@example.com');
        $this->get($url)->assertOk();
        User::factory()->create(['email' => 'late@example.com']);
        $this->post($url)->assertRedirect('/be-nl/login?email=late%40example.com');
        $this->assertGuest();
        $this->assertSame(1, User::query()->where('email', 'late@example.com')->count());
        $this->assertNotNull($kim->fresh());
    }

    #[Test]
    public function a_tampered_token_leads_to_the_sign_in_page(): void
    {
        $url = $this->invitedAs('bo@example.com');
        $token = substr($url, -64);
        $tampered = substr($url, 0, -64).($token[0] === 'a' ? 'b' : 'a').substr($token, 1);

        $this->get($tampered)->assertRedirect('/be-nl/login');
        $this->post($tampered)->assertRedirect('/be-nl/login');
        $this->assertGuest();
        $this->assertNull(FriendInviteToken::query()->value('used_at'));
    }

    #[Test]
    public function a_signed_in_visitor_is_not_switched_to_another_account(): void
    {
        $url = $this->invitedAs('bo@example.com');
        $kim = User::factory()->create(['email' => 'kim@example.com']);

        $this->actingAs($kim)->get($url)->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('signedInAs', 'kim@example.com')
                ->where('acceptUrl', null));
        $this->actingAs($kim)->post($url)->assertRedirect(parse_url($url, PHP_URL_PATH));

        $this->assertAuthenticatedAs($kim);
        $this->assertNull(FriendInviteToken::query()->value('used_at'));
        $this->assertFalse(User::query()->where('email', 'bo@example.com')->exists());
    }

    #[Test]
    public function the_button_needs_the_pages_csrf_token(): void
    {
        $url = $this->invitedAs('bo@example.com');
        $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');

        // Laravel skips the CSRF check for the whole suite, so a POST without
        // a token proves nothing here (see EbayAccountDeletionTest). What can
        // be held: the path is on no exemption list, and the page hands its
        // form the session's token.
        $never = (new ReflectionProperty(PreventRequestForgery::class, 'neverVerify'))->getValue();
        foreach ($never as $pattern) {
            $this->assertFalse(Str::is(trim((string) $pattern, '/'), $path), "{$pattern} exempts the accept button from CSRF");
        }

        $this->get($url)->assertInertia(fn ($page) => $page->where('csrfToken', session()->token()));
    }

    #[Test]
    public function a_token_is_issued_whether_or_not_the_address_has_an_account(): void
    {
        User::factory()->create(['email' => 'kim@example.com']);

        $this->actingAs($this->anna)->post('/be-nl/friends', ['email' => 'kim@example.com']);
        $this->actingAs($this->anna)->post('/be-nl/friends', ['email' => 'nobody@example.com']);

        $this->assertSame(['kim@example.com', 'nobody@example.com'], FriendInviteToken::query()->orderBy('email')->pluck('email')->all());
        // Only the hash is stored.
        $this->assertStringNotContainsString(substr($this->buttonFor('kim@example.com'), -64), (string) json_encode(DB::table('friend_invite_tokens')->get()));
    }

    #[Test]
    public function pruning_removes_used_and_expired_buttons(): void
    {
        $used = $this->invitedAs('bo@example.com');
        $this->invitedAs('kim@example.com');
        $this->post($used);

        $this->artisan('bc:prune-personal-data')->assertSuccessful();
        $this->assertSame(['kim@example.com'], FriendInviteToken::query()->pluck('email')->all());

        $this->travel((int) config('giftcoves.invites.accept_days') + 1)->days();
        $this->artisan('bc:prune-personal-data')->assertSuccessful();
        $this->assertSame(0, FriendInviteToken::query()->count());
    }

    /** Anna invites this address; the button's URL, opened signed out. */
    private function invitedAs(string $email): string
    {
        $this->actingAs($this->anna)->post('/be-nl/friends', ['email' => $email]);

        return $this->buttonFor($email);
    }

    private function buttonFor(string $email): string
    {
        $url = null;

        Mail::assertQueued(FriendInviteMail::class, function (FriendInviteMail $mail) use ($email, &$url) {
            if ($mail->hasTo($email)) {
                $url = $mail->url;

                return true;
            }

            return false;
        });

        // Signed out from here on: the reader of an email has no session here.
        $this->app['auth']->forgetGuards();

        return (string) $url;
    }
}

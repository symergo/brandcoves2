<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\Market;
use App\Models\ListInvitation;
use App\Models\LoginToken;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistCollaborator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Inviting somebody who does not have an account yet.
 *
 * Which is almost everybody: the person whose help you want is exactly the
 * person who has not signed up. Before this, that invitation did **nothing**
 * while telling the owner "they can see this list now".
 *
 * The property that must not regress is in
 * {@see the_response_is_identical_whether_or_not_the_address_has_an_account}.
 * If it does, the invite form becomes a way to discover which of somebody's
 * friends use the site.
 */
class ListInvitationTest extends TestCase
{
    use RefreshDatabase;

    private function giftList(User $owner): Wishlist
    {
        return Wishlist::factory()->create([
            'owner_user_id' => $owner->id,
            'recipient_id' => Recipient::factory()->create([
                'owner_user_id' => $owner->id,
                'name' => 'Mum',
            ])->id,
            'kind' => ListKind::ForSomeone,
            'market' => Market::BeNl,
        ]);
    }

    /**
     * An outstanding invitation, written directly.
     *
     * It used to come from `POST /lists/{id}/collaborators`, and that endpoint
     * is gone: sharing is a link now, so nothing sends invitations any more.
     * **Redemption is not gone**, and that is what this file is still for —
     * invitations already sent are sitting in real inboxes, and a link in an
     * email is followed whenever somebody gets round to it.
     *
     * So the row is made here rather than through a UI that no longer exists,
     * which is also the honest shape: these tests were always about what
     * happens *after* the mail, and the endpoint was scaffolding.
     */
    private function invitation(Wishlist $list, User $from, string $email, string $role = 'editor'): array
    {
        $token = (string) Str::uuid();

        $invitation = ListInvitation::create([
            'wishlist_id' => $list->id,
            'invited_by_user_id' => $from->id,
            // Lowercased on write: the lookup is case-insensitive, and storing
            // the typed casing would mean two invitations for one person.
            'email' => mb_strtolower($email),
            'role' => $role,
            'token' => $token,
            'expires_at' => now()->addDays(14),
        ]);

        return ['token' => $token, 'model' => $invitation];
    }

    /**
     * Sign in for real, by consuming a magic link.
     *
     * `actingAs()` would not do: the whole mechanism hangs off the `Login`
     * event, and a test that bypasses it would pass while nobody could ever
     * redeem an invitation.
     */
    private function signIn(string $email): User
    {
        ['token' => $token] = LoginToken::issue($email);

        $this->get("/be-nl/auth/magic/{$token}")->assertRedirect();

        return User::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->firstOrFail();
    }

    #[Test]
    public function signing_up_later_grants_access(): void
    {
        // The whole point: the invitation survives the person not existing yet.
        Mail::fake();

        $owner = User::factory()->create();
        $list = $this->giftList($owner);

        ['token' => $token] = $this->invitation($list, $owner, 'helper@example.test', 'editor');

        auth()->logout();

        $helper = $this->signIn('helper@example.test');

        $this->assertDatabaseHas('wishlist_collaborators', [
            'wishlist_id' => $list->id,
            'user_id' => $helper->id,
            'role' => 'editor',
        ]);

        // And they can actually open it.
        $this->actingAs($helper)->get("/be-nl/lists/{$list->id}")->assertOk();
    }

    #[Test]
    public function an_invitation_is_single_use(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $list = $this->giftList($owner);

        ['token' => $token] = $this->invitation($list, $owner, 'helper@example.test', 'viewer');

        auth()->logout();

        $this->signIn('helper@example.test');

        $this->assertNotNull(ListInvitation::query()->firstOrFail()->claimed_at);

        // Removing them again does not silently re-grant on the next sign-in.
        WishlistCollaborator::query()->delete();

        auth()->logout();
        $this->signIn('helper@example.test');

        $this->assertSame(0, WishlistCollaborator::query()->count());
    }

    #[Test]
    public function an_expired_invitation_grants_nothing(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $list = $this->giftList($owner);

        ['token' => $token] = $this->invitation($list, $owner, 'helper@example.test', 'viewer');

        ListInvitation::query()->update(['expires_at' => now()->subDay()]);

        auth()->logout();

        $this->signIn('helper@example.test');

        $this->assertSame(0, WishlistCollaborator::query()->count());
    }

    #[Test]
    public function following_the_link_signed_out_sends_you_to_sign_in(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $list = $this->giftList($owner);

        ['token' => $token] = $this->invitation($list, $owner, 'helper@example.test', 'viewer');

        auth()->logout();

        $token = ListInvitation::query()->firstOrFail()->token;

        $this->get("/be-nl/invitations/{$token}")->assertRedirect('/be-nl/login');
    }

    #[Test]
    public function following_the_link_signed_in_lands_on_the_list(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $list = $this->giftList($owner);

        ['token' => $token] = $this->invitation($list, $owner, 'helper@example.test', 'viewer');

        auth()->logout();

        $token = ListInvitation::query()->firstOrFail()->token;
        $helper = $this->signIn('helper@example.test');

        $this->actingAs($helper)
            ->get("/be-nl/invitations/{$token}")
            ->assertRedirect("/be-nl/lists/{$list->id}");
    }

    #[Test]
    public function an_unknown_token_is_a_404(): void
    {
        $this->get('/be-nl/invitations/'.Str::uuid())->assertNotFound();
    }

    #[Test]
    public function a_handed_over_list_does_not_re_admit_its_old_co_givers(): void
    {
        /*
         * Handing a list over purges its collaborators deliberately — they were
         * plotting about the person who now owns it. An invitation sent before
         * the handover must not put one of them straight back.
         */
        Mail::fake();

        $owner = User::factory()->create();
        $list = $this->giftList($owner);

        ['token' => $token] = $this->invitation($list, $owner, 'helper@example.test', 'viewer');

        // The list changes hands.
        $list->update(['owner_user_id' => User::factory()->create()->id, 'kind' => ListKind::Mine]);

        auth()->logout();
        $this->signIn('helper@example.test');

        $this->assertSame(0, WishlistCollaborator::query()->count());
        $this->assertNotNull(ListInvitation::query()->firstOrFail()->claimed_at);
    }
}

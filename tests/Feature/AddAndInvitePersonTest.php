<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\FriendInviteMail;
use App\Models\FriendInvite;
use App\Models\Recipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Iemand toevoegen" and "Nodig uit" as one form (owner, 2026-10-25): an
 * optional address, and "Toevoegen en uitnodigen" saves the person and
 * invites them, linked. See docs/features/my-people.md.
 */
class AddAndInvitePersonTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->me = User::factory()->create(['email' => 'anna@example.com']);
    }

    #[Test]
    public function add_and_invite_saves_the_person_and_invites_them_linked(): void
    {
        $this->add(['name' => 'Mama', 'birthday' => '2000-03-14', 'email' => 'Mama@Example.com', 'invite' => true])
            ->assertSessionHas('success', __('site.people.added_and_invited'));

        $mum = Recipient::query()->where('owner_user_id', $this->me->id)->sole();
        $this->assertSame('Mama', $mum->name);

        // Linked to the person just saved, so she stays one person once she joins.
        $invite = FriendInvite::query()->where('email', 'mama@example.com')->sole();
        $this->assertSame($mum->id, $invite->recipient_id);
        $this->assertSame([14, 3], [$invite->birthday_day, $invite->birthday_month]);
        Mail::assertQueued(FriendInviteMail::class, fn (FriendInviteMail $mail) => $mail->hasTo('mama@example.com'));
    }

    #[Test]
    public function plain_add_ignores_an_address_and_invites_nobody(): void
    {
        $this->add(['name' => 'Mama', 'email' => 'mama@example.com'])
            ->assertSessionHas('success', __('site.lists.recipient_added'));

        $this->assertSame(1, Recipient::query()->where('owner_user_id', $this->me->id)->count());
        $this->assertSame(0, FriendInvite::query()->count());
        Mail::assertNothingQueued();
    }

    #[Test]
    public function inviting_needs_an_address(): void
    {
        $this->add(['name' => 'Mama', 'invite' => true])->assertSessionHasErrors('email');

        $this->assertSame(0, Recipient::query()->count());
    }

    #[Test]
    public function a_refused_invitation_saves_nobody(): void
    {
        // Your own address: refused before anything is written or sent, so the
        // person rolls back and pressing the button again cannot save them twice.
        $this->add(['name' => 'Ikzelf', 'email' => 'anna@example.com', 'invite' => true])
            ->assertSessionHasErrors(['email' => __('site.people.invite_self')]);

        $this->assertSame(0, Recipient::query()->count());
        $this->assertSame(0, FriendInvite::query()->count());
        Mail::assertNothingQueued();
    }

    #[Test]
    public function a_guest_cannot_invite(): void
    {
        // Turned away before the controller (a redirect) or by it (403):
        // either way nothing is saved and nobody is mailed.
        $status = $this->from('/be-nl/people')
            ->post('/be-nl/recipients', ['name' => 'Mama', 'email' => 'mama@example.com', 'invite' => true])
            ->getStatusCode();

        $this->assertContains($status, [302, 403]);
        $this->assertSame(0, FriendInvite::query()->count());
        Mail::assertNothingQueued();
    }

    private function add(array $data): TestResponse
    {
        return $this->actingAs($this->me)->from('/be-nl/people')->post('/be-nl/recipients', $data);
    }
}

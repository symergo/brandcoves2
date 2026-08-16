<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SantaStatus;
use App\Mail\SecretSantaAssignmentMail;
use App\Models\SecretSantaGroup;
use App\Models\SecretSantaMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\SantaRepairTest;

/**
 * Removing somebody from a drawn group, and redrawing one pairing.
 *
 * The arithmetic is unit-tested in {@see SantaRepairTest}. What is
 * tested here is the part that costs real money and real embarrassment: **who
 * gets an email**. A repair that mails everybody is as bad as one that mails
 * nobody — the first tells eleven people something changed when it did not, and
 * the second leaves one person shopping for somebody who has moved.
 */
class SantaRepairFlowTest extends TestCase
{
    use RefreshDatabase;

    private function group(User $organiser): SecretSantaGroup
    {
        $this->actingAs($organiser)
            ->post('/be-nl/santa', ['title' => 'Office 2026', 'budget_max' => 25])
            ->assertRedirect();

        return SecretSantaGroup::query()->latest()->firstOrFail();
    }

    private function join(SecretSantaGroup $group, string $name, string $email): SecretSantaMember
    {
        $this->post("/be-nl/santa/{$group->id}/join/{$group->invite_token}", [
            'display_name' => $name,
            'email' => $email,
        ])->assertRedirect();

        return SecretSantaMember::query()->where('group_id', $group->id)->where('email', $email)->firstOrFail();
    }

    /**
     * A drawn group of four: the organiser plus three.
     *
     * The draw runs for real — status, timestamps and the first round of mail
     * all come from the endpoint — and then the pairings are overwritten with a
     * **known single cycle**.
     *
     * That last step is not decoration. A random derangement of four people is
     * sometimes one 4-cycle and sometimes two 2-cycles, and those are genuinely
     * different repairs: the first collapses with one write, the second leaves
     * the removed member's giver unattached and has to be spliced into the other
     * cycle. Left to chance, `removing_a_member_after_the_draw_emails_exactly_one_person`
     * passes about two runs in three — which is the worst kind of test, because
     * it is the *arithmetic* that varies and not the code under test.
     */
    private function drawnGroup(User $organiser): SecretSantaGroup
    {
        $group = $this->group($organiser);

        $this->join($group, 'Bob', 'bob@example.test');
        $this->join($group, 'Cara', 'cara@example.test');
        $this->join($group, 'Dan', 'dan@example.test');

        $this->actingAs($organiser)->post("/be-nl/santa/{$group->id}/draw")->assertRedirect();

        $group = $group->fresh();

        // One ring: each member gives to the next, and the last back to the
        // first. Written through the model so the `encrypted` cast applies.
        $members = $group->members()->orderBy('id')->get()->all();
        $count = count($members);

        foreach ($members as $i => $member) {
            $member->update(['assigned_member_id' => (string) $members[($i + 1) % $count]->id]);
        }

        return $group;
    }

    /** @return array<int, int> giver id => giftee id */
    private function assignments(SecretSantaGroup $group): array
    {
        $out = [];

        foreach ($group->allMembers()->get() as $member) {
            if ($member->assigned_member_id !== null) {
                $out[$member->id] = (int) $member->assigned_member_id;
            }
        }

        return $out;
    }

    #[Test]
    public function only_the_organiser_can_remove_a_member(): void
    {
        $organiser = User::factory()->create();
        $group = $this->drawnGroup($organiser);
        $victim = $group->members()->where('email', 'bob@example.test')->firstOrFail();

        $this->actingAs(User::factory()->create())
            ->delete("/be-nl/santa/{$group->id}/members/{$victim->id}")
            ->assertForbidden();

        $this->assertNull($victim->fresh()->removed_at);
    }

    #[Test]
    public function removing_a_member_before_the_draw_emails_nobody(): void
    {
        // No pairings exist, so nobody is affected. The ordinary case must not
        // go anywhere near the repair path.
        Mail::fake();

        $organiser = User::factory()->create();
        $group = $this->group($organiser);
        $bob = $this->join($group, 'Bob', 'bob@example.test');

        $this->actingAs($organiser)
            ->delete("/be-nl/santa/{$group->id}/members/{$bob->id}")
            ->assertRedirect();

        $this->assertNotNull($bob->fresh()->removed_at);
        Mail::assertNothingQueued();
    }

    #[Test]
    public function removing_a_member_after_the_draw_emails_exactly_one_person(): void
    {
        /*
         * The load-bearing assertion. Their giver takes on their giftee, so
         * exactly one person's assignment moved and exactly one person needs
         * telling — and everybody else is still holding a mail that is still
         * true.
         */
        $organiser = User::factory()->create();
        $group = $this->drawnGroup($organiser);

        $before = $this->assignments($group);
        $victim = $group->members()->where('email', 'bob@example.test')->firstOrFail();

        Mail::fake();

        $this->actingAs($organiser)
            ->delete("/be-nl/santa/{$group->id}/members/{$victim->id}")
            ->assertRedirect();

        Mail::assertQueuedCount(1);

        // And it went to the person whose giftee changed, not to the leaver.
        $giver = array_search($victim->id, $before, strict: true);
        $giverEmail = SecretSantaMember::query()->whereKey($giver)->firstOrFail()->email;

        Mail::assertQueued(
            SecretSantaAssignmentMail::class,
            fn (SecretSantaAssignmentMail $mail) => $mail->hasTo($giverEmail) && $mail->changed,
        );
    }

    #[Test]
    public function everybody_elses_pairing_survives_a_removal(): void
    {
        Mail::fake();

        $organiser = User::factory()->create();
        $group = $this->drawnGroup($organiser);

        $before = $this->assignments($group);
        $victim = $group->members()->where('email', 'bob@example.test')->firstOrFail();
        $giver = array_search($victim->id, $before, strict: true);

        $this->actingAs($organiser)->delete("/be-nl/santa/{$group->id}/members/{$victim->id}");

        $after = $this->assignments($group->fresh());

        foreach ($before as $id => $giftee) {
            if ($id === $victim->id || $id === $giver) {
                continue;
            }

            $this->assertSame($giftee, $after[$id] ?? null, "member {$id} was disturbed");
        }

        // The one that did move, moved to the leaver's giftee.
        $this->assertSame($before[$victim->id], $after[$giver]);
    }

    #[Test]
    public function a_removed_member_leaves_the_roster_but_keeps_their_row(): void
    {
        /*
         * `assigned_member_id` is encrypted ciphertext with no foreign key, so a
         * hard delete would leave somebody's page quietly naming nobody. The row
         * stays; the roster does not show it.
         */
        Mail::fake();

        $organiser = User::factory()->create();
        $group = $this->drawnGroup($organiser);
        $victim = $group->members()->where('email', 'bob@example.test')->firstOrFail();

        $this->actingAs($organiser)->delete("/be-nl/santa/{$group->id}/members/{$victim->id}");

        $this->assertSame(3, $group->fresh()->members()->count());
        $this->assertSame(4, $group->fresh()->allMembers()->count());
        $this->assertNotNull(SecretSantaMember::query()->whereKey($victim->id)->first());
    }

    #[Test]
    public function a_group_cannot_be_cut_below_two_people(): void
    {
        Mail::fake();

        $organiser = User::factory()->create();
        $group = $this->group($organiser);
        $bob = $this->join($group, 'Bob', 'bob@example.test');

        $this->actingAs($organiser)->post("/be-nl/santa/{$group->id}/draw");

        $this->actingAs($organiser)
            ->delete("/be-nl/santa/{$group->id}/members/{$bob->id}")
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($bob->fresh()->removed_at);
    }

    #[Test]
    public function a_redraw_emails_exactly_two_people(): void
    {
        // A transposition, which is what `santa.redrawn` has always said:
        // "Redrawn. Both people have been emailed."
        $organiser = User::factory()->create();
        $group = $this->drawnGroup($organiser);
        $subject = $group->members()->where('email', 'bob@example.test')->firstOrFail();

        Mail::fake();

        $this->actingAs($organiser)
            ->post("/be-nl/santa/{$group->id}/members/{$subject->id}/redraw")
            ->assertRedirect();

        Mail::assertQueuedCount(2);
    }

    #[Test]
    public function a_redraw_gives_that_person_somebody_new(): void
    {
        Mail::fake();

        $organiser = User::factory()->create();
        $group = $this->drawnGroup($organiser);

        $before = $this->assignments($group);
        $subject = $group->members()->where('email', 'bob@example.test')->firstOrFail();

        $this->actingAs($organiser)->post("/be-nl/santa/{$group->id}/members/{$subject->id}/redraw");

        $after = $this->assignments($group->fresh());

        $this->assertNotSame($before[$subject->id], $after[$subject->id]);
        $this->assertNotSame($subject->id, $after[$subject->id]);
        $this->assertNotNull($subject->fresh()->redrawn_at);
    }

    #[Test]
    public function a_group_that_has_not_been_drawn_cannot_be_redrawn(): void
    {
        $organiser = User::factory()->create();
        $group = $this->group($organiser);
        $bob = $this->join($group, 'Bob', 'bob@example.test');

        $this->assertSame(SantaStatus::Open, $group->fresh()->status);

        $this->actingAs($organiser)
            ->post("/be-nl/santa/{$group->id}/members/{$bob->id}/redraw")
            ->assertForbidden();
    }

    #[Test]
    public function the_organiser_still_never_learns_who_drew_whom_after_a_repair(): void
    {
        // The repair gives the organiser two new controls and must not give
        // them a new way to read the pairings.
        Mail::fake();

        $organiser = User::factory()->create();
        $group = $this->drawnGroup($organiser);
        $victim = $group->members()->where('email', 'bob@example.test')->firstOrFail();

        $this->actingAs($organiser)->delete("/be-nl/santa/{$group->id}/members/{$victim->id}");

        $props = $this->actingAs($organiser)
            ->get("/be-nl/santa/{$group->id}")
            ->assertOk()
            ->viewData('page')['props'];

        foreach ($props['members'] as $member) {
            $this->assertArrayNotHasKey('assigned', $member);
            $this->assertArrayNotHasKey('giftee', $member);
            $this->assertArrayNotHasKey('assignedMemberId', $member);
        }
    }
}

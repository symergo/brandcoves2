<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A birthday, as a day and a month.
 *
 * `recipients.birthday` has been read by `SendOccasionReminders` for a while
 * and was written by nothing anybody could reach — so it sat empty on almost
 * every row while the job that needs it ran every morning. Two places now ask:
 * the person creating a list for somebody, who is guessing, and the person the
 * list is about, who is not.
 *
 * **Never a year.** Every reader matches on month and day, because a birthday
 * recurs; a year is personal data with no use here, so it is not asked for and
 * not stored. `Recipient::BIRTHDAY_YEAR` is the placeholder that makes a date
 * out of the two halves.
 */
class RecipientBirthdayTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function creating_a_list_for_somebody_can_carry_their_birthday(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/be-nl/lists', [
                'title' => 'For Anna',
                'new_recipient' => 'Anna',
                'birthday_day' => 14,
                'birthday_month' => 6,
            ])
            ->assertRedirect();

        $recipient = Recipient::query()->where('name', 'Anna')->firstOrFail();

        $this->assertSame(14, $recipient->birthday->day);
        $this->assertSame(6, $recipient->birthday->month);
        $this->assertSame(Recipient::BIRTHDAY_YEAR, $recipient->birthday->year);
    }

    #[Test]
    public function the_birthday_is_optional(): void
    {
        // The commonest case: you are making a list, you do not know the date,
        // and being asked for one must not stop you.
        $this->actingAs(User::factory()->create())
            ->post('/be-nl/lists', ['title' => 'For Bob', 'new_recipient' => 'Bob'])
            ->assertRedirect();

        $this->assertNull(Recipient::query()->where('name', 'Bob')->firstOrFail()->birthday);
    }

    #[Test]
    public function half_a_date_is_rejected_rather_than_dropped(): void
    {
        // `required_with` each way. A half-filled pair is somebody who started
        // answering, and silently discarding it is how they find out months
        // later that no reminder came.
        $this->actingAs(User::factory()->create())
            ->post('/be-nl/lists', [
                'title' => 'For Cara',
                'new_recipient' => 'Cara',
                'birthday_day' => 14,
            ])
            ->assertSessionHasErrors('birthday_month');
    }

    #[Test]
    public function an_impossible_date_stores_nothing(): void
    {
        /*
         * 31 February passes the per-field ranges — 31 is a valid day, 2 is a
         * valid month — and is not a date. Accepting it would store 3 March and
         * remind somebody on a day nobody named.
         */
        $this->actingAs(User::factory()->create())
            ->post('/be-nl/lists', [
                'title' => 'For Dan',
                'new_recipient' => 'Dan',
                'birthday_day' => 31,
                'birthday_month' => 2,
            ])
            ->assertRedirect();

        $this->assertNull(Recipient::query()->where('name', 'Dan')->firstOrFail()->birthday);
    }

    #[Test]
    public function the_twenty_ninth_of_february_is_storable(): void
    {
        // Which is why the placeholder year is a leap year. Under 2001 Carbon
        // rolls this to 1 March, silently, and the reminder arrives on the
        // wrong day forever.
        $this->assertSame('2000-02-29', Recipient::birthdayFrom(29, 2));
    }

    #[Test]
    public function choosing_an_existing_person_leaves_their_details_alone(): void
    {
        /*
         * The birthday rides along with a *name*, on somebody being minted. A
         * blank field quietly overwriting a date entered months ago is an edit
         * nobody would ever find.
         */
        $me = User::factory()->create();
        $anna = Recipient::factory()->create([
            'owner_user_id' => $me->id,
            'name' => 'Anna',
            'birthday' => '2000-06-14',
        ]);

        $this->actingAs($me)->post('/be-nl/lists', [
            'title' => 'Another for Anna',
            'recipient_id' => $anna->id,
        ])->assertRedirect();

        $this->assertSame('2000-06-14', $anna->fresh()->birthday->toDateString());
    }

    // ── The person themselves ─────────────────────────────────────────────

    #[Test]
    public function the_recipient_can_give_their_own_birthday(): void
    {
        // The one person who definitely knows it. The giver is guessing, which
        // is why the column sat empty.
        $recipient = Recipient::factory()->create([
            'owner_user_id' => User::factory()->create()->id,
            'name' => 'Anna',
        ]);

        $this->post("/be-nl/for/{$recipient->share_token}", [
            'interests' => [],
            'values' => [],
            'birthday_day' => 2,
            'birthday_month' => 11,
        ])->assertRedirect();

        $this->assertSame('2000-11-02', $recipient->fresh()->birthday->toDateString());
    }

    #[Test]
    public function describing_yourself_without_a_date_does_not_erase_one(): void
    {
        /*
         * Somebody fills in their interests and skips the date. Absent means
         * "left blank", not "clear it" — otherwise answering the taste
         * questions would wipe a date the giver already knew.
         */
        $recipient = Recipient::factory()->create([
            'owner_user_id' => User::factory()->create()->id,
            'name' => 'Anna',
            'birthday' => '2000-06-14',
        ]);

        $this->post("/be-nl/for/{$recipient->share_token}", [
            'interests' => [],
            'values' => [],
        ])->assertRedirect();

        $this->assertSame('2000-06-14', $recipient->fresh()->birthday->toDateString());
    }
}

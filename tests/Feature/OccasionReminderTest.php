<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendOccasionReminders;
use App\Models\AnonymousIdentity;
use App\Models\Notification;
use App\Models\Recipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OccasionReminderTest extends TestCase
{
    use RefreshDatabase;

    private function recipientWithBirthdayIn(int $days, ?User $owner = null): Recipient
    {
        return Recipient::factory()->create([
            'owner_user_id' => ($owner ?? User::factory()->create())->id,
            'name' => 'Mum',
            // A year long past, to prove the match is on day and month.
            'birthday' => now()->addDays($days)->setYear(1961)->toDateString(),
        ]);
    }

    #[Test]
    public function a_birthday_two_weeks_out_produces_one_reminder(): void
    {
        $this->recipientWithBirthdayIn(14);

        (new SendOccasionReminders)->handle();

        $this->assertSame(1, Notification::query()->where('kind', 'occasion.birthday')->count());
    }

    #[Test]
    public function running_twice_does_not_notify_twice(): void
    {
        $this->recipientWithBirthdayIn(14);

        (new SendOccasionReminders)->handle();
        (new SendOccasionReminders)->handle();

        /*
         * A reminder that repeats gets muted, and once muted the *real* one is
         * muted too — so over-notifying does not fail as noise, it fails as
         * silence at the moment that matters. A redeploy replaying a window is
         * the ordinary way this happens.
         */
        $this->assertSame(1, Notification::query()->where('kind', 'occasion.birthday')->count());
    }

    #[Test]
    public function both_lead_times_fire_for_the_same_birthday(): void
    {
        $owner = User::factory()->create();
        $this->recipientWithBirthdayIn(14, $owner);

        (new SendOccasionReminders)->handle();

        // Move time forward so the same recipient falls in the three-day window.
        $this->travel(11)->days();
        (new SendOccasionReminders)->handle();

        // Two weeks is enough time to shop; three days is when people act.
        $this->assertSame(2, Notification::query()->where('kind', 'occasion.birthday')->count());
    }

    #[Test]
    public function a_birthday_on_an_unrelated_day_is_left_alone(): void
    {
        $this->recipientWithBirthdayIn(9);

        (new SendOccasionReminders)->handle();

        $this->assertSame(0, Notification::query()->count());
    }

    #[Test]
    public function an_anonymous_owner_gets_no_reminder(): void
    {
        // Notifications are delivered to an account. A cookie identity has
        // nowhere to receive one, exactly as with alerts.
        Recipient::factory()->ownedByAnonymous(
            AnonymousIdentity::factory()->create()->getKey()
        )->create(['birthday' => now()->addDays(14)->setYear(1961)->toDateString()]);

        (new SendOccasionReminders)->handle();

        $this->assertSame(0, Notification::query()->count());
    }
}

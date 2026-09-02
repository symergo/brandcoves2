<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\EventType;
use App\Enums\ListKind;
use App\Enums\Market;
use App\Jobs\SendOccasionReminders;
use App\Mail\OccasionReminderMail;
use App\Models\AnonymousIdentity;
use App\Models\Notification;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use App\Services\Settings\ReminderSettingsStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Your mother's birthday is in two weeks."
 *
 * The windows are a setting now rather than a constant, so every test that
 * depends on a particular one says which — pinning the config is part of the
 * assertion, not setup noise. The shipped default is 30/15/2.
 */
class OccasionReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Nothing here is asserting the transport. The two tests that are say
        // so themselves.
        Mail::fake();
    }

    private function windows(int ...$days): void
    {
        config(['giftcoves.reminders.lead_days' => $days]);
    }

    private function recipientWithBirthdayIn(int $days, ?User $owner = null): Recipient
    {
        return Recipient::factory()->create([
            'owner_user_id' => ($owner ?? User::factory()->create())->id,
            'name' => 'Mum',
            // A year long past, to prove the match is on day and month.
            'birthday' => now()->addDays($days)->setYear(1961)->toDateString(),
        ]);
    }

    private function listWithOccasionIn(int $days, ListKind $kind, ?User $owner = null): Wishlist
    {
        $owner ??= User::factory()->create();

        return Wishlist::factory()->create([
            'owner_user_id' => $owner->id,
            'recipient_id' => $kind === ListKind::Mine ? null : Recipient::factory()->create([
                'owner_user_id' => $owner->id,
                'name' => 'Dad',
            ])->id,
            'kind' => $kind,
            'market' => Market::BeNl,
            'event_type' => EventType::Graduation,
            'event_date' => now()->addDays($days)->toDateString(),
        ]);
    }

    // ── Birthdays ─────────────────────────────────────────────────────────

    #[Test]
    public function a_birthday_inside_a_window_produces_one_reminder(): void
    {
        $this->windows(30, 15, 2);
        $this->recipientWithBirthdayIn(15);

        (new SendOccasionReminders)->handle();

        $this->assertSame(1, Notification::query()->where('kind', 'occasion.birthday')->count());
    }

    #[Test]
    public function running_twice_does_not_notify_twice(): void
    {
        $this->windows(30, 15, 2);
        $this->recipientWithBirthdayIn(15);

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
    public function every_window_fires_for_the_same_birthday(): void
    {
        $this->windows(30, 15, 2);
        $owner = User::factory()->create();
        $this->recipientWithBirthdayIn(30, $owner);

        (new SendOccasionReminders)->handle();

        // Forward to each of the remaining windows in turn.
        $this->travel(15)->days();
        (new SendOccasionReminders)->handle();

        $this->travel(13)->days();
        (new SendOccasionReminders)->handle();

        // Thirty days is "there is time to find something good", fifteen is
        // "decide", two is "it is now".
        $this->assertSame(3, Notification::query()->where('kind', 'occasion.birthday')->count());
    }

    #[Test]
    public function a_birthday_on_an_unrelated_day_is_left_alone(): void
    {
        $this->windows(30, 15, 2);
        $this->recipientWithBirthdayIn(9);

        (new SendOccasionReminders)->handle();

        $this->assertSame(0, Notification::query()->count());
    }

    #[Test]
    public function an_anonymous_owner_gets_no_reminder(): void
    {
        // Notifications are delivered to an account. A cookie identity has
        // nowhere to receive one, exactly as with alerts.
        $this->windows(15);

        Recipient::factory()->ownedByAnonymous(
            AnonymousIdentity::factory()->create()->getKey()
        )->create(['birthday' => now()->addDays(15)->setYear(1961)->toDateString()]);

        (new SendOccasionReminders)->handle();

        $this->assertSame(0, Notification::query()->count());
    }

    // ── The windows are a setting ─────────────────────────────────────────

    #[Test]
    public function the_windows_come_from_config(): void
    {
        // They were a constant on the job, so changing them was a deploy.
        $this->windows(7);
        $this->recipientWithBirthdayIn(7);

        (new SendOccasionReminders)->handle();

        $this->assertSame(1, Notification::query()->count());
    }

    #[Test]
    public function a_zero_or_negative_window_reminds_nobody_about_today(): void
    {
        /*
         * Config is reachable from more than the admin screen, and a `0` here
         * would mean "remind everybody about today" — every day, forever, for
         * every date already past. The job filters what it reads rather than
         * trusting the store to have done it.
         */
        $this->windows(0, -5);
        $this->recipientWithBirthdayIn(0);

        (new SendOccasionReminders)->handle();

        $this->assertSame(0, Notification::query()->count());
    }

    #[Test]
    public function the_store_cleans_what_an_administrator_types(): void
    {
        // Descending, unique, positive, capped — so the field shows on reload
        // exactly what the job will read.
        $this->assertSame([30, 15, 2], ReminderSettingsStore::parseDays('2, 30, 15, 15'));
        $this->assertSame([], ReminderSettingsStore::parseDays('thirty'));
        $this->assertSame([9, 8, 7, 6, 5], ReminderSettingsStore::parseDays('9 8 7 6 5 4 3'));
    }

    // ── The occasion on a list ────────────────────────────────────────────

    #[Test]
    public function an_occasion_on_a_list_about_somebody_names_them(): void
    {
        // The date that had no reminder at all: typed in the Gelegenheid panel,
        // rendered on the shared page, and until now read by nothing.
        $this->windows(15);
        $this->listWithOccasionIn(15, ListKind::ForSomeone);

        (new SendOccasionReminders)->handle();

        $notification = Notification::query()->where('kind', 'occasion.list')->firstOrFail();

        $this->assertStringContainsString('Dad', $notification->title);
        // Resolved in the list's market language, not the app default — a
        // queued job has no request and therefore no locale.
        $this->assertStringContainsString('komt eraan', $notification->title);
    }

    #[Test]
    public function an_occasion_on_your_own_list_asks_about_the_list_instead(): void
    {
        /*
         * On a wish list of your own the occasion is your own event, so "buy
         * something for yourself in 15 days" is the wrong sentence. What is
         * useful there is "your list is about to matter — is it ready?".
         */
        $this->windows(15);
        $this->listWithOccasionIn(15, ListKind::Mine);

        (new SendOccasionReminders)->handle();

        $notification = Notification::query()->where('kind', 'occasion.list')->firstOrFail();

        $this->assertStringContainsString('Jouw', $notification->title);
    }

    #[Test]
    public function a_list_occasion_fires_once_per_window(): void
    {
        $this->windows(30, 15, 2);
        $this->listWithOccasionIn(15, ListKind::ForSomeone);

        (new SendOccasionReminders)->handle();
        (new SendOccasionReminders)->handle();

        $this->assertSame(1, Notification::query()->where('kind', 'occasion.list')->count());
    }

    #[Test]
    public function a_list_with_a_date_but_no_owner_account_is_skipped(): void
    {
        $this->windows(15);

        Wishlist::factory()->create([
            'owner_user_id' => null,
            'owner_anon_id' => AnonymousIdentity::factory()->create()->getKey(),
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
            'event_type' => EventType::Graduation,
            'event_date' => now()->addDays(15)->toDateString(),
        ]);

        (new SendOccasionReminders)->handle();

        $this->assertSame(0, Notification::query()->count());
    }

    // ── Email ─────────────────────────────────────────────────────────────

    #[Test]
    public function the_reminder_is_also_emailed(): void
    {
        // The inbox is read by somebody who came back to the site, and the
        // premise of a reminder is that they have not.
        $this->windows(15);
        $owner = User::factory()->create();
        $this->recipientWithBirthdayIn(15, $owner);

        (new SendOccasionReminders)->handle();

        Mail::assertQueued(
            OccasionReminderMail::class,
            fn (OccasionReminderMail $mail): bool => $mail->hasTo($owner->email),
        );
    }

    #[Test]
    public function the_second_run_emails_nothing(): void
    {
        /*
         * The notification row is the ledger for both channels: mail goes out
         * only on the pass that wrote it. Otherwise the first morning after
         * email was switched on would re-send every reminder ever recorded.
         */
        $this->windows(15);
        $this->recipientWithBirthdayIn(15);

        (new SendOccasionReminders)->handle();
        (new SendOccasionReminders)->handle();

        Mail::assertQueuedCount(1);
    }

    #[Test]
    public function email_can_be_switched_off_without_losing_the_reminder(): void
    {
        $this->windows(15);
        config(['giftcoves.reminders.email' => false]);
        $this->recipientWithBirthdayIn(15);

        (new SendOccasionReminders)->handle();

        Mail::assertNothingQueued();
        // The record survives; only the delivery is off.
        $this->assertSame(1, Notification::query()->count());
    }
}

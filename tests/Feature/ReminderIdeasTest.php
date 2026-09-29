<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\EventType;
use App\Enums\ListKind;
use App\Enums\Market;
use App\Jobs\SendOccasionReminders;
use App\Mail\OccasionReminderMail;
use App\Models\MailTemplate;
use App\Models\Notification;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Ai\AiClient;
use App\Services\Mail\MailTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reminders with ideas ready (docs/features/gift-history.md,
 * docs/features/occasion-reminders.md): about two weeks before a saved
 * person's birthday or the occasion on a list about them, the reminder email
 * brings three ideas; once per person, occasion and year; never to somebody
 * who turned the emails off; and never through AI.
 */
class ReminderIdeasTest extends TestCase
{
    use RefreshDatabase;

    private User $giver;

    private Recipient $mum;

    private Wishlist $list;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();

        // Invariant 1: the reminder job may not reach the model, however it runs.
        $this->mock(AiClient::class, function ($mock): void {
            $mock->shouldNotReceive('json');
            $mock->shouldNotReceive('chat');
        });

        // The shipped windows. Two weeks is nearest to fifteen days.
        config(['giftcoves.reminders.lead_days' => [30, 15, 2]]);

        $this->giver = User::factory()->create();
        $this->mum = Recipient::factory()->create([
            'owner_user_id' => $this->giver->id,
            'name' => 'Mum',
            'interests' => ['cooking'],
            'birthday' => now()->addDays(15)->setYear(2000)->toDateString(),
        ]);

        // A list about her puts her in a market, the way the job finds one.
        $this->list = Wishlist::factory()->create([
            'owner_user_id' => $this->giver->id,
            'recipient_id' => $this->mum->id,
            'kind' => ListKind::ForSomeone,
            'market' => Market::BeNl,
        ]);
    }

    private function cooking(): ProductGroup
    {
        return ProductGroup::factory()->priced(3000)->create([
            'gift_tags' => ['interest:cooking'],
            'title' => 'Kookgerei '.Str::random(6),
        ]);
    }

    /** @return list<OccasionReminderMail> */
    private function mails(): array
    {
        return Mail::queued(OccasionReminderMail::class)->values()->all();
    }

    /** @return list<int> */
    private function ideaIds(OccasionReminderMail $mail): array
    {
        return array_map(
            fn (array $idea) => (int) Str::between($idea['url'], '/p/', '/'),
            $mail->ideas,
        );
    }

    #[Test]
    public function two_weeks_out_the_birthday_reminder_brings_three_ideas_without_past_gifts(): void
    {
        $given = $this->cooking();
        $onHerList = $this->cooking();
        $fresh = collect(range(1, 5))->map(fn () => $this->cooking());

        // Bought off her own wish list: the giver's own claim, on a list that
        // is about her without naming her as its recipient, so only the gift
        // history (GiftHistory) can leave it out.
        $mumAccount = User::factory()->create();
        $this->mum->update(['user_id' => $mumAccount->id, 'status' => 'linked']);
        $wishList = Wishlist::factory()->create([
            'owner_user_id' => $mumAccount->id,
            'recipient_id' => null,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
        ]);
        WishlistItem::factory()->create([
            'wishlist_id' => $wishList->id,
            'group_id' => $given->id,
            'claimed_by_hash' => WishlistItem::identityHash('user:'.$this->giver->id),
            'claimed_at' => now(),
        ]);
        WishlistItem::factory()->create(['wishlist_id' => $this->list->id, 'group_id' => $onHerList->id]);

        (new SendOccasionReminders)->handle();

        [$mail] = $this->mails();
        $ids = $this->ideaIds($mail);

        $this->assertCount(3, $ids);
        $this->assertNotContains($given->id, $ids, 'Never what she was already given.');
        $this->assertNotContains($onHerList->id, $ids, 'Nor what is already on the list for her.');
        $this->assertEmpty(array_diff($ids, $fresh->pluck('id')->all()));

        // One click to Find a gift, one to put an idea on her list.
        $this->assertStringEndsWith("/be-nl/gift?for={$this->mum->id}", (string) $mail->ideasUrl);
        $this->assertStringEndsWith("/be-nl/people/{$this->mum->id}?add={$ids[0]}", $mail->ideas[0]['addUrl']);
        $this->assertSame('Mum', $mail->name);

        $notice = Notification::query()->where('kind', 'occasion.birthday')->sole();
        $this->assertSame("{$this->mum->id}:birthday:".now()->addDays(15)->year, $notice->payload['ideas_key']);
        $this->assertSame("/be-nl/gift?for={$this->mum->id}", $notice->url);
    }

    #[Test]
    public function the_other_windows_remind_without_ideas(): void
    {
        $this->mum->update(['birthday' => now()->addDays(30)->setYear(2000)->toDateString()]);
        collect(range(1, 4))->each(fn () => $this->cooking());

        (new SendOccasionReminders)->handle();

        [$mail] = $this->mails();
        $this->assertSame([], $mail->ideas, 'Thirty days out is "there is time"; the ideas come at two weeks.');
    }

    #[Test]
    public function the_ideas_go_once_per_person_occasion_and_year(): void
    {
        collect(range(1, 4))->each(fn () => $this->cooking());

        // Her birthday list, dated on her birthday: two reminders, one set of ideas.
        $this->list->update([
            'event_type' => EventType::Birthday,
            'event_date' => now()->addDays(15)->toDateString(),
        ]);

        (new SendOccasionReminders)->handle();
        (new SendOccasionReminders)->handle();

        $mails = $this->mails();
        $this->assertCount(2, $mails, 'The birthday and the list, each once, however often the job runs.');
        $this->assertSame(1, collect($mails)->filter(fn (OccasionReminderMail $m) => $m->ideas !== [])->count());
    }

    #[Test]
    public function a_wish_list_of_your_own_gets_no_ideas(): void
    {
        $this->mum->update(['birthday' => null]);
        collect(range(1, 4))->each(fn () => $this->cooking());

        Wishlist::factory()->create([
            'owner_user_id' => $this->giver->id,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
            'event_type' => EventType::Graduation,
            'event_date' => now()->addDays(15)->toDateString(),
        ]);

        (new SendOccasionReminders)->handle();

        [$mail] = $this->mails();
        $this->assertSame([], $mail->ideas);
    }

    #[Test]
    public function whoever_turned_the_emails_off_gets_none_and_keeps_the_inbox_row(): void
    {
        collect(range(1, 4))->each(fn () => $this->cooking());
        $this->giver->forceFill(['reminder_emails_off_at' => now()])->save();

        (new SendOccasionReminders)->handle();

        Mail::assertNothingQueued();

        $notice = Notification::query()->where('kind', 'occasion.birthday')->sole();
        $this->assertArrayNotHasKey('ideas_key', $notice->payload, 'No email, so no ideas were sent.');
    }

    #[Test]
    public function the_link_in_the_email_turns_them_off_in_one_click(): void
    {
        (new SendOccasionReminders)->handle();

        [$mail] = $this->mails();
        $url = (string) $mail->unsubscribeUrl;

        $this->assertStringContainsString("/be-nl/reminders/stop/{$this->giver->id}", $url);
        $this->assertSame(
            ['List-Unsubscribe' => "<{$url}>", 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'],
            $mail->headers()->text,
        );

        // Somebody else's id without the signature does nothing.
        $this->get("/be-nl/reminders/stop/{$this->giver->id}")->assertForbidden();
        $this->assertNull($this->giver->fresh()->reminder_emails_off_at);

        // The mail client's one-click POST, with no session.
        $this->post(Str::after($url, rtrim((string) config('app.url'), '/')))->assertRedirect();
        $this->assertNotNull($this->giver->fresh()->reminder_emails_off_at);
    }

    #[Test]
    public function the_notifications_page_turns_them_back_on(): void
    {
        $this->giver->forceFill(['reminder_emails_off_at' => now()])->save();

        $this->actingAs($this->giver)->get('/be-nl/notifications')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('reminderEmails', false));

        $this->actingAs($this->giver)->post('/be-nl/notifications/reminder-emails', ['on' => true])->assertRedirect();
        $this->assertNull($this->giver->fresh()->reminder_emails_off_at);

        $this->actingAs($this->giver)->post('/be-nl/notifications/reminder-emails', ['on' => false])->assertRedirect();
        $this->assertNotNull($this->giver->fresh()->reminder_emails_off_at);
    }

    #[Test]
    public function the_email_shows_the_ideas_and_the_way_out(): void
    {
        $groups = collect(range(1, 4))->map(fn () => $this->cooking());

        (new SendOccasionReminders)->handle();

        [$mail] = $this->mails();
        $html = $mail->render();

        foreach ($mail->ideas as $idea) {
            $this->assertStringContainsString(e($idea['title']), $html);
            $this->assertStringContainsString(e($idea['addUrl']), $html);
        }

        $this->assertStringContainsString(__('site.reminders.ideas_heading', ['name' => 'Mum'], 'nl'), $html);
        $this->assertStringContainsString(e((string) $mail->ideasUrl), $html);
        $this->assertStringContainsString(e((string) $mail->unsubscribeUrl), $html);
        $this->assertStringContainsString(__('site.reminders.mail_stop', [], 'nl'), $html);

        // Nothing from her list, and no claim state: the ideas are catalogue products.
        $this->assertStringNotContainsString($this->list->title, $html);
        $this->assertNotEmpty(array_intersect($this->ideaIds($mail), $groups->pluck('id')->all()));
    }

    #[Test]
    public function an_edited_reminder_keeps_the_ideas_and_the_way_out(): void
    {
        MailTemplate::create([
            'key' => 'occasion_reminder',
            'language' => 'nl',
            'subject' => 'Bijna jarig',
            'body' => 'Nog :days dagen voor :name.',
            'enabled' => true,
        ]);
        app(MailTemplates::class)->flush();

        collect(range(1, 4))->each(fn () => $this->cooking());

        (new SendOccasionReminders)->handle();

        [$mail] = $this->mails();
        $html = $mail->render();

        $this->assertSame('mail.templated', $mail->content()->markdown);
        $this->assertStringContainsString('Nog 15 dagen voor Mum.', $html);
        $this->assertCount(3, $mail->ideas);
        $this->assertStringContainsString(e($mail->ideas[0]['addUrl']), $html, 'The editor owns the words, never the ideas.');
        $this->assertStringContainsString(e((string) $mail->unsubscribeUrl), $html);
    }

    #[Test]
    public function signed_links_do_not_expire(): void
    {
        $url = URL::signedRoute('reminders.stop', ['market' => 'be-nl', 'user' => $this->giver->id]);

        $this->travel(400)->days();

        $this->get(Str::after($url, rtrim((string) config('app.url'), '/')))->assertRedirect();
        $this->assertNotNull($this->giver->fresh()->reminder_emails_off_at);
    }
}

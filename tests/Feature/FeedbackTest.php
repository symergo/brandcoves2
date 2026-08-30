<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\PrunePersonalDataCommand;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Telling us what is wrong.
 *
 * The properties worth holding: anyone can report without an account, a report
 * carries the page it is about, and nothing a bot or a flood does to this form
 * can be told apart from success — because an endpoint that reports its own
 * rejections is one a script tunes itself against.
 */
class FeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('feedback:'.sha1('127.0.0.1'));
    }

    #[Test]
    public function anyone_can_report_something_without_an_account(): void
    {
        $this->post('/be-nl/feedback', [
            'message' => 'The price on this page is twenty euro lower at the shop itself.',
            'path' => '/be-nl/p/1234/sony-wh-1000xm5',
        ])->assertRedirect();

        $row = Feedback::query()->sole();

        $this->assertSame('be-nl', $row->market->value);
        $this->assertSame('/be-nl/p/1234/sony-wh-1000xm5', $row->path);
        $this->assertNull($row->email);
        $this->assertNull($row->user_id);
        $this->assertNull($row->handled_at);
    }

    #[Test]
    public function it_records_the_account_when_there_is_one(): void
    {
        $user = User::create(['email' => 'reporter@example.test']);

        $this->actingAs($user)->post('/be-nl/feedback', [
            'message' => 'The Dutch translation on the gift finder reads like a machine wrote it.',
            'email' => 'reporter@example.test',
        ])->assertRedirect();

        $row = Feedback::query()->sole();

        $this->assertSame($user->id, $row->user_id);
        $this->assertSame('reporter@example.test', $row->email);
    }

    /** A scrap is not a report, and the form says so before it is sent. */
    #[Test]
    public function it_refuses_a_message_too_short_to_act_on(): void
    {
        $this->post('/be-nl/feedback', ['message' => 'broken'])
            ->assertSessionHasErrors('message');

        $this->assertSame(0, Feedback::query()->count());
    }

    /**
     * The honeypot. Anything in `website` was typed by something filling every
     * input on the page — a field no human is shown.
     */
    #[Test]
    public function it_drops_a_submission_that_filled_the_hidden_field(): void
    {
        $this->post('/be-nl/feedback', [
            'message' => 'Buy cheap watches at this completely unrelated address.',
            'website' => 'http://spam.test',
        ])->assertSessionHasErrors('website');

        $this->assertSame(0, Feedback::query()->count());
    }

    /**
     * Past the limit the answer is still "thank you".
     *
     * Telling a script which of its attempts landed is how it learns where the
     * line is; and a human who has hit it is better served by the same message
     * than by an error about a quota they did not know existed.
     */
    #[Test]
    public function a_flood_is_answered_exactly_like_a_report(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->post('/be-nl/feedback', [
                'message' => "Report number {$i}, long enough to pass validation.",
            ])->assertRedirect()->assertSessionHasNoErrors();
        }

        // Five stored, three swallowed, and no response said so.
        $this->assertSame(5, Feedback::query()->count());
    }

    /**
     * `Referer` is visitor-controlled. Without the host check an off-site link
     * could put any string it liked into a field the page renders back.
     */
    #[Test]
    public function it_prefills_the_page_only_from_our_own_referer(): void
    {
        $this->get('/be-nl/feedback', ['referer' => url('/be-nl/p/99/thing').'?q=x'])
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('path', '/be-nl/p/99/thing'));

        $this->get('/be-nl/feedback', ['referer' => 'https://evil.test/be-nl/p/99/thing'])
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('path', null));
    }

    #[Test]
    public function the_page_is_in_the_main_menu_and_the_sitemap(): void
    {
        // The nav is rendered client-side, so what is asserted here is the
        // route the menu points at — a menu entry to a 404 is the failure.
        $this->get('/be-nl/feedback')->assertOk();

        $this->get('/sitemap/be-nl/1.xml')
            ->assertOk()
            ->assertSee('/be-nl/feedback', escape: false);
    }

    /**
     * GDPR Article 5(1)(e). The message goes with the address, because a free
     * text field is whatever the person put in it.
     */
    #[Test]
    public function it_is_deleted_on_the_published_retention_clock(): void
    {
        $days = PrunePersonalDataCommand::RETENTION['feedback'];

        $old = Feedback::query()->create([
            'market' => 'be-nl',
            'message' => 'A report from long enough ago that nobody is going to act on it now.',
            'email' => 'someone@example.test',
        ]);
        $old->forceFill(['created_at' => now()->subDays($days + 1)])->save();

        $recent = Feedback::query()->create([
            'market' => 'be-nl',
            'message' => 'A report from this week, which is still worth acting on.',
        ]);

        $this->artisan('bc:prune-personal-data')->assertSuccessful();

        $this->assertNull(Feedback::query()->find($old->id));
        $this->assertNotNull(Feedback::query()->find($recent->id));
    }
}

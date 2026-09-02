<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Mail\MagicLinkMail;
use App\Models\MailTemplate;
use App\Services\Mail\MailTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The words in an email, editable without a deploy.
 *
 * The point of this file is the fallback. An overlay whose *default* path is
 * wrong breaks every email at once, silently, for everybody — so most of what is
 * asserted here is that an untouched install sends exactly what it always sent.
 */
class MailTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // The store caches for an hour; a leaked cache would make the next test
        // read this one's overrides.
        app(MailTemplates::class)->flush();

        parent::tearDown();
    }

    #[Test]
    public function an_untouched_install_sends_the_shipped_wording(): void
    {
        $mail = new MagicLinkMail('tok', Market::BeNl);

        $this->assertSame(
            __('site.auth.mail_subject', [], 'nl'),
            $mail->envelope()->subject,
        );

        // The shipped view, not the generic one.
        $this->assertSame('mail.magic-link', $mail->content()->markdown);
    }

    #[Test]
    public function an_override_replaces_the_subject_and_the_body(): void
    {
        MailTemplate::create([
            'key' => 'magic_link',
            'language' => 'nl',
            'subject' => 'Hier is je link',
            'body' => 'Klik hieronder om in te loggen.',
            'enabled' => true,
        ]);

        app(MailTemplates::class)->flush();

        $mail = new MagicLinkMail('tok', Market::BeNl);

        $this->assertSame('Hier is je link', $mail->envelope()->subject);
        // Rendered through the generic view, which keeps our button and the
        // fallback URL line — the parts an editor cannot break.
        $this->assertSame('mail.templated', $mail->content()->markdown);
        $this->assertSame('Klik hieronder om in te loggen.', $mail->content()->with['body']);
    }

    #[Test]
    public function switching_an_override_off_restores_the_shipped_wording(): void
    {
        // Off rather than deleted: an editor with second thoughts should not
        // have to reconstruct what they wrote from a screenshot.
        MailTemplate::create([
            'key' => 'magic_link',
            'language' => 'nl',
            'subject' => 'Hier is je link',
            'body' => 'Klik hieronder.',
            'enabled' => false,
        ]);

        app(MailTemplates::class)->flush();

        $mail = new MagicLinkMail('tok', Market::BeNl);

        $this->assertSame(__('site.auth.mail_subject', [], 'nl'), $mail->envelope()->subject);
        $this->assertSame('mail.magic-link', $mail->content()->markdown);
    }

    #[Test]
    public function an_override_is_per_language(): void
    {
        // Language sits on the row, so a Dutch rewrite does not silently become
        // the English one — the mistake a single shared body would make easy.
        MailTemplate::create([
            'key' => 'magic_link',
            'language' => 'nl',
            'subject' => 'Hier is je link',
            'body' => 'Klik hieronder.',
            'enabled' => true,
        ]);

        app(MailTemplates::class)->flush();

        $this->assertSame(
            __('site.auth.mail_subject', [], 'en'),
            (new MagicLinkMail('tok', Market::En))->envelope()->subject,
        );
    }

    #[Test]
    public function placeholders_are_filled_and_unknown_ones_are_left_visible(): void
    {
        /*
         * A visible `:whatever` is a bug somebody reports; a silent gap is one
         * nobody notices. So an unknown token stays on the page as typed.
         */
        $this->assertSame(
            'Anna is in for 3 days',
            MailTemplates::fill(':name is in for :days days', ['name' => 'Anna', 'days' => 3]),
        );

        $this->assertSame(
            'Hello :nobody',
            MailTemplates::fill('Hello :nobody', ['name' => 'Anna']),
        );
    }

    #[Test]
    public function a_template_that_is_not_in_the_registry_is_ignored(): void
    {
        // An allowlist, not a free-form bag: a stray row must not be able to
        // reach an email nobody declared as editable.
        MailTemplate::create([
            'key' => 'santa_assignment',
            'language' => 'nl',
            'subject' => 'Rewritten',
            'body' => 'Rewritten',
            'enabled' => true,
        ]);

        app(MailTemplates::class)->flush();

        $this->assertNull(app(MailTemplates::class)->for('santa_assignment', 'nl'));
    }

    #[Test]
    public function the_shipped_wording_is_read_from_the_language_files(): void
    {
        // So "what does this say now" is answered by the thing that actually
        // says it, rather than by a second copy that will drift.
        $shipped = app(MailTemplates::class)->shipped('magic_link', 'nl');

        $this->assertSame(__('site.auth.mail_subject', [], 'nl'), $shipped['subject']);
        $this->assertSame(__('site.auth.mail_body', [], 'nl'), $shipped['body']);
    }
}

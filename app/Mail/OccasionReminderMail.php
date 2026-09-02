<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Your mother's birthday is in two weeks."
 *
 * The same reminder the in-app inbox gets, sent where it will actually be read.
 * The inbox is opened by somebody who came back to the site, and the entire
 * premise of a reminder is that they have not — a notification nobody sees is
 * a notification that did not happen.
 *
 * ## It carries no list contents
 *
 * A title, a date, a lead time and a link. Never what is on the list, what has
 * been claimed off it, or who claimed it: a reminder is delivered to an inbox
 * that may be read on a shared screen, forwarded, or synced to a phone somebody
 * else picks up — and on a wish list the one person who must not learn what has
 * been bought is the person this email is addressed to. `ListInvitationMail`
 * refuses product data for the same reason and is worth reading beside this.
 *
 * ## The body is one sentence and a button
 *
 * A reminder that needs reading twice has failed. What it has to convey is
 * *when* and *what to do next*; everything else is on the page the button opens,
 * where it is current rather than frozen at send time.
 */
class OccasionReminderMail extends Mailable
{
    use Concerns\UsesTemplate;
    use Queueable;
    use SerializesModels;

    public function __construct(
        /** The subject line, already resolved in the recipient's language. */
        public readonly string $heading,
        public readonly string $body,
        public readonly string $url,
        /** BCP 47-ish language code for `app()->setLocale()` in the view. */
        public readonly string $language,
        /**
         * The facts behind the sentence, for an edited template to re-fill.
         *
         * The shipped copy arrives here already resolved; an override has its
         * own wording and needs the values, not the result.
         *
         * @var array<string, string|int>
         */
        public readonly array $tokens = [],
    ) {}

    /**
     * The editor's version, if there is one.
     *
     * The job has already resolved the subject and the body in the recipient's
     * language and filled the names into them, so an override here re-fills its
     * own placeholders from the same facts rather than receiving a finished
     * sentence: `:days` in an edited body has to mean the same thing it means
     * in the shipped one.
     *
     * @return array{subject: string, body: string}|null
     */
    private function edited(): ?array
    {
        return $this->template('occasion_reminder', $this->language, $this->tokens);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->edited()['subject'] ?? $this->heading);
    }

    public function content(): Content
    {
        $template = $this->edited();

        if ($template !== null) {
            return $this->templatedContent(
                $template,
                $this->language,
                $this->url,
                (string) __('site.reminders.mail_button', [], $this->language),
            );
        }

        return new Content(
            markdown: 'mail.occasion-reminder',
            with: [
                'language' => $this->language,
                'heading' => $this->heading,
                'body' => $this->body,
                'url' => $this->url,
            ],
        );
    }
}

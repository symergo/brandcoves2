<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
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
 * ## Ideas, about two weeks out, are not list contents
 *
 * On the window nearest two weeks, a reminder about somebody else carries three
 * ideas for them (SendOccasionReminders, ReminderIdeas). They come from the
 * catalogue, matched to the person, and say nothing about any list: not what
 * is on it, not what was claimed. The rule above is about lists, and holds.
 * They are only ever sent about somebody other than the reader.
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
        /*
         * Protected, not public, and that is the fix for a bug: a Mailable
         * hands every public property to its view, after and over what
         * `content()` passed. So an edited template's body (`with['body']`)
         * was replaced by this, the shipped sentence, in every email sent, and
         * rewording the reminder in the admin changed only the subject. Found
         * 2026-09-28 by rendering one (ReminderIdeasTest).
         */
        protected readonly string $body,
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
        /**
         * Up to three ideas for the person, already shaped for the view:
         * title, image, price (formatted), url, addUrl. Empty for most
         * reminders.
         *
         * @var list<array{title: string, image: string|null, price: string|null, url: string, addUrl: string}>
         */
        public readonly array $ideas = [],
        /** The Gift Finder, opened on this person. */
        public readonly ?string $ideasUrl = null,
        /** The person's name, for "Ideas for Mum". */
        public readonly ?string $name = null,
        /** The signed "stop these emails" link. */
        public readonly ?string $unsubscribeUrl = null,
    ) {}

    /**
     * One-click unsubscribe, in the headers as well as the footer, the way
     * CoveDigestMail does it. `List-Unsubscribe-Post` is what lets a mail
     * client offer its own button (RFC 8058).
     */
    public function headers(): Headers
    {
        if ($this->unsubscribeUrl === null) {
            return new Headers;
        }

        return new Headers(text: [
            'List-Unsubscribe' => "<{$this->unsubscribeUrl}>",
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

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

        /*
         * The ideas and the stop link go under an editor's wording as well as
         * under ours: the template owns the prose, never the structure
         * (UsesTemplate), and a reminder that lost its ideas or its way out
         * because somebody reworded a sentence would fail silently.
         */
        $extra = [
            'ideas' => $this->ideas,
            'ideasUrl' => $this->ideasUrl,
            'name' => (string) $this->name,
            'unsubscribeUrl' => $this->unsubscribeUrl,
        ];

        if ($template !== null) {
            $content = $this->templatedContent(
                $template,
                $this->language,
                $this->url,
                (string) __('site.reminders.mail_button', [], $this->language),
            );

            return $content->with($extra);
        }

        return new Content(
            markdown: 'mail.occasion-reminder',
            with: [
                'language' => $this->language,
                'heading' => $this->heading,
                'body' => $this->body,
                'url' => $this->url,
                ...$extra,
            ],
        );
    }
}

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
 * "Anna asks: what do I buy my mum?"
 *
 * A friend's published question, sent to the people linked to them
 * (QuestionToPeople). It carries the asker's name, the question's title and a
 * button, all of which are already public on the board. Nothing from any list.
 *
 * Unsubscribe in the headers and the footer, the way the reminders do it; the
 * link stops these emails only, and the question still reaches the inbox.
 */
class PeopleQuestionMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        /** The subject line, already in the receiver's language. */
        public readonly string $heading,
        public readonly string $askerName,
        public readonly string $question,
        public readonly string $url,
        public readonly string $language,
        public readonly string $unsubscribeUrl,
    ) {}

    /** RFC 8058 one-click unsubscribe, as `OccasionReminderMail` does it. */
    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => "<{$this->unsubscribeUrl}>",
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->heading);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.people-question');
    }
}

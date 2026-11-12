<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\Market;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Everything a Secret Friend group mails that is not the assignment itself:
 * a question, an answer, "your list was looked at", the weekly progress, the
 * invitation to do it again, a thank-you.
 *
 * One shape: a few sentences and one button to the member's own page. No
 * product names, images or prices, for the reason the assignment mail has
 * none (docs/features/secret-santa.md, "Email").
 *
 * `$muteUrl` is the member's one-click "no more of these": the assignment
 * mail is the game, these are the game being friendly, and somebody who does
 * not want them must be able to say so without leaving the group.
 */
class SantaNoteMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $subjectLine,
        public readonly string $groupTitle,
        public readonly array $lines,
        public readonly string $cta,
        public readonly string $url,
        public readonly Market $market,
        public readonly ?string $muteUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.santa-note',
            with: [
                'language' => $this->market->language(),
                'groupTitle' => $this->groupTitle,
                'lines' => $this->lines,
                'cta' => $this->cta,
                'url' => $this->url,
                'muteUrl' => $this->muteUrl,
            ],
        );
    }
}

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
 * "You have drawn Sam."
 *
 * The only place a pairing is ever put in writing, and it goes to exactly one
 * person. Everything else about the group — the member list, the progress
 * count — is deliberately aggregate, so this email is the single channel
 * through which the game can be spoiled.
 *
 * It carries no product data. The giftee's list is a link, which keeps the
 * Amazon rules out of the picture entirely (`Source::allowsEmail()`): a mail
 * with nothing to filter cannot be got wrong by a later edit.
 */
class SecretSantaAssignmentMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $gifteeName,
        public readonly string $groupTitle,
        public readonly Market $market,
        public readonly string $meUrl,
        public readonly ?string $budget = null,
        public readonly ?string $exchangeDate = null,
        public readonly bool $gifteeHasList = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('site.santa.email_subject', ['name' => $this->gifteeName], $this->market->language()),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.santa-assignment',
            with: [
                'language' => $this->market->language(),
                'gifteeName' => $this->gifteeName,
                'groupTitle' => $this->groupTitle,
                'meUrl' => $this->meUrl,
                'budget' => $this->budget,
                'exchangeDate' => $this->exchangeDate,
                'gifteeHasList' => $this->gifteeHasList,
            ],
        );
    }
}

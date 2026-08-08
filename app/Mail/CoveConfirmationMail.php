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
 * The one email an unconfirmed subscriber receives.
 *
 * It contains no products, which is not an oversight: an address that has not
 * confirmed may not have asked for anything at all, and sending content to it
 * before it does is the behaviour that costs a sending reputation.
 */
class CoveConfirmationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $token,
        public readonly Market $market,
        public readonly ?string $requestedFrom = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('site.cove_mail.confirm_subject', locale: $this->market->language()),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.cove-confirm',
            with: [
                'url' => url("/{$this->market->value}/coves/confirm/{$this->token}"),
                'market' => $this->market,
                'requestedFrom' => $this->requestedFrom,
                'language' => $this->market->language(),
            ],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\Market;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * The daily teaser.
 *
 * Everything in `$digest` has already been filtered by `DigestBuilder` — this
 * class renders and does not decide. That separation is deliberate: the
 * compliance rule about which products may be named lives in one place, is
 * tested there, and a second template added later inherits it by construction
 * rather than by someone remembering.
 */
class CoveDigestMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** @param array<string, mixed> $digest */
    public function __construct(
        public readonly array $digest,
        public readonly Market $market,
        public readonly string $unsubscribeToken,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('site.cove_mail.digest_subject', [
                'theme' => $this->digest['theme'],
            ], $this->market->language()),
        );
    }

    /**
     * One-click unsubscribe, in the headers.
     *
     * RFC 8058. Gmail and Yahoo require it of bulk senders, and the practical
     * effect is larger than the rule: a reader who cannot find the link marks the
     * mail as spam instead, and a spam complaint costs the domain far more than
     * an unsubscribe does.
     *
     * `List-Unsubscribe-Post` is what makes it one-click rather than a link the
     * client opens — without it the header is decorative.
     */
    public function headers(): Headers
    {
        $url = url("/{$this->market->value}/coves/unsubscribe/{$this->unsubscribeToken}");

        return new Headers(text: [
            'List-Unsubscribe' => "<{$url}>",
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.cove-digest',
            with: [
                'digest' => $this->digest,
                'market' => $this->market,
                'language' => $this->market->language(),
                'editionUrl' => url($this->digest['url']),
                'unsubscribeUrl' => url("/{$this->market->value}/coves/unsubscribe/{$this->unsubscribeToken}"),
            ],
        );
    }
}

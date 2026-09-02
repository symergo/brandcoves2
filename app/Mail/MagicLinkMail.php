<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\Market;
use App\Mail\Concerns\UsesTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MagicLinkMail extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesTemplate;

    public function __construct(
        public readonly string $token,
        public readonly Market $market,
        public readonly ?string $requestedFrom = null,
    ) {}

    public function envelope(): Envelope
    {
        // Localised: the market the person was browsing decides the language of
        // the email, not the app default.
        return new Envelope(
            subject: $this->template('magic_link', $this->market->language())['subject']
                ?? __('site.auth.mail_subject', locale: $this->market->language()),
        );
    }

    public function content(): Content
    {
        $template = $this->template('magic_link', $this->market->language());

        if ($template !== null) {
            return $this->templatedContent(
                $template,
                $this->market->language(),
                url("/{$this->market->value}/auth/magic/{$this->token}"),
                (string) __('site.auth.mail_button', [], $this->market->language()),
            );
        }

        return new Content(
            markdown: 'mail.magic-link',
            with: [
                'url' => url("/{$this->market->value}/auth/magic/{$this->token}"),
                'market' => $this->market,
                'requestedFrom' => $this->requestedFrom,
                'language' => $this->market->language(),
            ],
        );
    }
}

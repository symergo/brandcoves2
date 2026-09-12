<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Somebody new signed up."
 *
 * To the owner, not to the person who registered: they get no welcome mail
 * (a magic link already proved the mailbox, and Google already said hello).
 * English and unlocalised, because it goes to one known reader.
 *
 * Carries the address and the name because that is what the owner wants to
 * see, so it is personal data leaving the database by mail. The recipient is
 * whoever `REGISTRATION_NOTIFY_EMAIL` names, set per environment, and the
 * privacy page's retention rules apply to the mailbox it lands in like any
 * other admin export. No product data, so nothing to filter.
 */
class NewRegistrationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $method,
        public readonly string $market,
        public readonly int $total,
    ) {}

    public function envelope(): Envelope
    {
        $who = trim($this->name) !== '' ? $this->name : $this->email;

        return new Envelope(
            subject: "New GiftCoves account: {$who}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.new-registration',
            with: [
                'adminUrl' => rtrim((string) config('app.url'), '/').'/admin',
                'host' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: config('app.url'),
            ],
        );
    }
}

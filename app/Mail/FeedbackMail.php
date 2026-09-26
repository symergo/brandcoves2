<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Feedback;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Somebody used the form on /help."
 *
 * To the owner, never to the visitor. The form only ever wrote a row to the
 * `feedback` table, and the admin queue is not somewhere anybody looks daily,
 * so reports sat unread while the visitor believed they had been heard
 * (reported 2026-09-26 as "emails from the help page do not arrive" — there
 * had never been one). English and unlocalised, like NewRegistrationMail: one
 * known reader.
 *
 * When the visitor left an address it becomes the Reply-To, so answering them
 * is pressing Reply. That is the only thing the form says the address is for.
 * The message and the address are personal data leaving the database by mail;
 * see docs/features/feedback.md.
 */
class FeedbackMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly Feedback $feedback) {}

    public function envelope(): Envelope
    {
        $email = $this->feedback->email;

        return new Envelope(
            subject: 'GiftCoves feedback ('.$this->feedback->market->value.'): '
                .mb_strimwidth(preg_replace('/\s+/', ' ', trim($this->feedback->message)) ?? '', 0, 60, '…'),
            replyTo: is_string($email) && $email !== '' ? [new Address($email)] : [],
        );
    }

    public function content(): Content
    {
        $base = rtrim((string) config('app.url'), '/');

        return new Content(
            markdown: 'mail.feedback',
            with: [
                'body' => $this->feedback->message,
                'email' => $this->feedback->email,
                'market' => $this->feedback->market->value,
                'pageUrl' => $this->feedback->path !== null ? $base.$this->feedback->path : null,
                'signedIn' => $this->feedback->user_id !== null,
                'adminUrl' => $base.'/admin/feedback',
            ],
        );
    }
}

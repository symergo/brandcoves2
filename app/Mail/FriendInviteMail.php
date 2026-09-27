<?php

declare(strict_types=1);

namespace App\Mail;

use App\Services\Social\InviteMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * "Anna nodigt je uit op GiftCoves."
 *
 * Sent when a member invites an address from My people ({@see InviteMailer}).
 * Identical whether or not the address has an account: the button carries a
 * single-use accept token either way (2026-09-27), which creates and signs in
 * a new account and sends an existing one to the sign-in page, a difference
 * that shows only to the person who presses it. Anything in the email that
 * differed would tell the member which it was.
 *
 * Carries the member's name and nothing else about them: not their address,
 * not a list. The person reading it may never have heard of us.
 *
 * "This is spam" in the footer and as RFC 8058 one-click List-Unsubscribe, the
 * way the reminder emails carry their stop link. Not wired to the editable
 * email templates: the spam link is part of what this email must always say.
 */
class FriendInviteMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $inviterName,
        public readonly string $language,
        public readonly string $url,
        public readonly string $notWantedUrl,
    ) {}

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => "<{$this->notWantedUrl}>",
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('site.invite_mail.subject', ['name' => $this->inviterName], $this->language),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.friend-invite');
    }
}

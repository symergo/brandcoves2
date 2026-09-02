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
 * "Ann would like your help choosing something for Mum."
 *
 * Sent to an address whether or not it has an account, because the response to
 * the invite form must not differ between those two cases — otherwise the form
 * becomes a way to discover which of somebody's friends use the site.
 *
 * Carries **no product data**: a list title, who is asking, and a link. The
 * list is private research about a third person, and mailing any of its
 * contents to an address that has not yet proved it belongs to the invitee
 * would be publishing that research to whoever holds the inbox.
 */
class ListInvitationMail extends Mailable
{
    use Concerns\UsesTemplate;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $listTitle,
        public readonly string $fromName,
        public readonly Market $market,
        public readonly string $url,
        /** Who the list is for, when it is about somebody. */
        public readonly ?string $forName = null,
    ) {}

    /**
     * The editor's version, if there is one.
     *
     * Its placeholders are filled from the same facts the shipped copy uses, so
     * `:name` means the same thing in an edited body as in the one it replaced.
     *
     * @return array{subject: string, body: string}|null
     */
    private function edited(): ?array
    {
        return $this->template('list_invitation', $this->market->language(), [
            'name' => $this->fromName,
            'list' => $this->listTitle,
            'person' => $this->forName ?? '',
        ]);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->edited()['subject'] ?? __(
                'site.invitations.mail_subject',
                ['name' => $this->fromName],
                $this->market->language(),
            ),
        );
    }

    public function content(): Content
    {
        $template = $this->edited();

        if ($template !== null) {
            return $this->templatedContent(
                $template,
                $this->market->language(),
                $this->url,
                (string) __('site.invitations.mail_button', [], $this->market->language()),
            );
        }

        return new Content(
            markdown: 'mail.list-invitation',
            with: [
                'language' => $this->market->language(),
                'listTitle' => $this->listTitle,
                'fromName' => $this->fromName,
                'forName' => $this->forName,
                'url' => $this->url,
            ],
        );
    }
}

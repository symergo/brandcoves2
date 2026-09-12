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
 * The list price digest: what got cheaper or came back, per list, in one mail.
 *
 * Renders and does not decide. The sections arrive fully built from
 * App\Jobs\SendListPriceDigests, already filtered to trackable sources, so
 * nothing from a programme that forbids product data in email can reach the
 * template by construction. Links point at our own product and list pages,
 * never at a shop. See docs/features/list-price-watch.md.
 *
 * @phpstan-type Line array{title: string, url: string, was: int|null, now: int|null, percent: int|null}
 * @phpstan-type Section array{title: string, url: string, drops: list<Line>, back: list<Line>}
 */
class ListPriceDigestMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<Section>  $sections  one per list that had something to say
     */
    public function __construct(
        public readonly Market $market,
        public readonly array $sections,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: (string) __('site.list_watch.mail_subject', [], $this->market->language()),
        );
    }

    public function content(): Content
    {
        // One list: straight to it. Several: the overview, so the button does
        // not silently favour whichever list happened to sort first.
        $buttonUrl = count($this->sections) === 1
            ? $this->sections[0]['url']
            : url("/{$this->market->value}/lists");

        return new Content(
            markdown: 'mail.list-price-digest',
            with: [
                'market' => $this->market,
                'language' => $this->market->language(),
                'sections' => $this->sections,
                'buttonUrl' => $buttonUrl,
                'buttonKey' => count($this->sections) === 1
                    ? 'site.list_watch.mail_button'
                    : 'site.list_watch.mail_button_lists',
            ],
        );
    }
}

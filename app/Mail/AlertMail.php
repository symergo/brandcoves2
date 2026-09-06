<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A price drop or a restock, in the inbox.
 *
 * Alerts were in-app only, while the rules deciding which sources may carry
 * one argued from email delivery (`Source::allowsPriceAlerts()` requires
 * `allowsEmail()`). The channel the compliance case rests on now exists.
 *
 * What it carries is deliberately little: our own product page, the title,
 * the price and what it was. Never a merchant link and never a source's
 * product data — the price came from `RefreshWishlistedProducts::
 * trackablePrice()`, which reads trackable sources only, so nothing from a
 * programme that forbids email can reach this template by construction.
 */
class AlertMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  'price_drop'|'restock'  $kind
     * @param  int|null  $price  cents
     * @param  int|null  $was  cents, the baseline a drop is measured from
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly string $url,
        public readonly string $language,
        public readonly ?string $price = null,
        public readonly ?string $was = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) __(
            $this->kind === 'restock' ? 'site.alerts.mail_subject_restock' : 'site.alerts.mail_subject_drop',
            ['title' => $this->title],
            $this->language,
        ));
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.alert',
            with: [
                'language' => $this->language,
                'heading' => $this->title,
                'body' => $this->kind === 'restock'
                    ? __('site.alerts.mail_body_restock', [], $this->language)
                    : __('site.alerts.mail_body_drop', ['price' => $this->price, 'was' => $this->was], $this->language),
                'url' => $this->url,
            ],
        );
    }
}

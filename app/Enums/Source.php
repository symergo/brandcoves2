<?php

declare(strict_types=1);

namespace App\Enums;

enum Source: string
{
    case Awin = 'awin';
    case Bol = 'bol';
    case Amazon = 'amazon';
    case Manual = 'manual';

    /**
     * Feed sources are ingested on a schedule into our own index. Live sources
     * are queried per request and cached — never mirrored.
     */
    public function isFeed(): bool
    {
        return $this === self::Awin;
    }

    public function isLive(): bool
    {
        return in_array($this, [self::Bol, self::Amazon], true);
    }

    /*
    |--------------------------------------------------------------------------
    | Per-source usage rules
    |--------------------------------------------------------------------------
    |
    | Affiliate programmes restrict what may be done with their product data,
    | and Amazon's are by far the tightest. Encoding them as capabilities means
    | a feature asks "may I?" instead of every call site remembering to special
    | case Amazon — and a new source declares its own rules in one place.
    |
    | See docs/features/amazon-compliance.md for the clauses behind each of
    | these and for what still needs verifying against the current agreement.
    */

    /**
     * May the catalogue be mirrored?
     *
     * Amazon forbids it: we may store the decision (which ASIN, and why) but
     * title, price, image and availability must be re-fetched live at render.
     */
    public function allowsCatalogueStorage(): bool
    {
        return $this !== self::Amazon;
    }

    /**
     * May prices be recorded internally?
     *
     * Yes for every source, Amazon included. Storing a price is not the
     * restricted act — surfacing it as a tracking product is. Internal uses
     * (detecting that a feed's price moved, spotting a merchant with a
     * permanently inflated "was" price) stay allowed.
     */
    public function allowsPriceStorage(): bool
    {
        return true;
    }

    /**
     * May price movement be exposed to a visitor as a feature?
     *
     * This is the line Amazon draws, and it sits between storage and display:
     * we may hold prices, we may not build a price-tracking product on top of
     * them. Gates the sparkline, the "typical price" claim, discount badges
     * derived from history, and price-drop alerts.
     *
     * Storage is deliberately NOT gated on this — see allowsPriceStorage().
     */
    public function allowsPriceTracking(): bool
    {
        return $this !== self::Amazon;
    }

    /**
     * May a price-drop or back-in-stock alert be offered on this offer?
     *
     * An alert is price tracking with a delivery mechanism attached, so it
     * inherits that restriction — and is delivered by email, which Amazon
     * restricts separately.
     */
    public function allowsPriceAlerts(): bool
    {
        return $this->allowsPriceTracking() && $this->allowsEmail();
    }

    /**
     * May this source's product data or links appear in an email?
     *
     * Amazon prohibits Associates links and product content in email entirely.
     * This is the rule most likely to be broken by accident, because a price
     * alert, a daily digest and a shared-list notification are all email.
     */
    public function allowsEmail(): bool
    {
        return $this !== self::Amazon;
    }

    /**
     * Must a displayed price carry an "as of <time>" note and a disclaimer?
     *
     * Amazon requires it, because the price may have changed since it was
     * fetched.
     */
    public function requiresPriceTimestamp(): bool
    {
        return $this === self::Amazon;
    }

    /** Longest a price may be reused before it must be re-fetched, in seconds. */
    public function maxPriceAgeSeconds(): ?int
    {
        // 15 minutes rather than the permitted 24 hours: the cache exists to
        // absorb bursts, not to retain data, and a shorter window keeps us
        // clearly inside the rule rather than at its edge.
        return $this === self::Amazon ? 900 : null;
    }

    public function label(): string
    {
        return match ($this) {
            self::Awin => 'Awin',
            self::Bol => 'bol.com',
            self::Amazon => 'Amazon',
            self::Manual => 'Manual',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}

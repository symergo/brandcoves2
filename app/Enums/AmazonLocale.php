<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * An Amazon marketplace.
 *
 * ## Why this is not a Market
 *
 * `Market` is our catalogue: a currency, a language, a set of merchants, and a
 * scoped product identity. An Amazon locale is a storefront. They are related
 * but not the same, and conflating them would be wrong in both directions —
 * `be-nl` has no Amazon storefront of its own, and `amazon.de` serves several
 * of our markets.
 *
 * ## The ASIN is the identity, and it crosses locales
 *
 * The same ASIN is the same physical product on every storefront. That is the
 * fact this whole design turns on:
 *
 * - **Classification happens once.** Giftability and serendipity are properties
 *   of the product, not of the storefront, so they are computed per ASIN and
 *   reused everywhere. Re-running them per locale would cost five times as much
 *   to produce five identical answers — and worse, five *slightly different*
 *   ones, because the classifier reads the title and the title is translated.
 * - **Price and description do not.** Those are per storefront, differ by tax,
 *   shipping and stock, and are fetched live at render. Never stored:
 *   {@see Source::allowsCatalogueStorage()}.
 *
 * ## The trap
 *
 * Showing a visitor other locales is useful — "also on amazon.de for €40" is a
 * real answer when the local storefront is out of stock. Folding that price
 * into the market's cheapest-offer aggregate is not: it lets a foreign price,
 * with foreign tax and cross-border shipping, masquerade as the best local
 * deal. That is the same failure market-scoped identity exists to prevent, and
 * it is why a foreign locale is always a labelled, opt-in extra rather than a
 * row in the comparison table.
 */
enum AmazonLocale: string
{
    case Nl = 'amazon.nl';
    case Be = 'amazon.com.be';
    case De = 'amazon.de';
    case Fr = 'amazon.fr';
    case Es = 'amazon.es';
    case It = 'amazon.it';
    case Uk = 'amazon.co.uk';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $l) => $l->value, self::cases());
    }

    public function host(): string
    {
        return 'www.'.$this->value;
    }

    /** PA-API marketplace host, which is not the storefront host. */
    public function apiHost(): string
    {
        return 'webservices.'.$this->value;
    }

    public function region(): string
    {
        return match ($this) {
            self::Uk => 'eu-west-1',
            default => 'eu-west-1',
        };
    }

    public function currency(): string
    {
        return match ($this) {
            self::Uk => 'GBP',
            default => 'EUR',
        };
    }

    /**
     * The locale a visitor in this market sees first.
     *
     * Belgium is the awkward one and the reason this method exists rather than
     * a flat map: `amazon.com.be` is a real storefront but a thin one, and a
     * Belgian shopper is usually better served by `amazon.nl` or `amazon.fr`
     * depending on which language they read. Preferring the language-matched
     * neighbour is the honest default; the selector lets them disagree.
     */
    public static function primaryFor(Market $market): self
    {
        return match ($market) {
            Market::NlNl => self::Nl,
            Market::BeNl => self::Nl,
            Market::BeFr => self::Fr,
            Market::Es => self::Es,
            Market::En => self::Uk,
        };
    }

    /**
     * Every locale offered to a visitor in this market, primary first.
     *
     * All of them by default, deliberately. A shopper who reads French and
     * lives in Belgium may still want the German price, and hiding it because
     * we decided it was not "their" storefront is us guessing on their behalf
     * about something they can see for themselves.
     *
     * Individual locales can be switched off per market via
     * `config('giftcoves.connectors.amazon.hidden_locales')` — useful when a
     * storefront turns out to ship badly to a market, or when an account is not
     * approved for it. **The primary can never be hidden**: a market with no
     * Amazon storefront at all would be a selector with nothing in it, and the
     * config is an editorial preference, not a way to break the feature.
     *
     * @return list<self>
     */
    public static function selectableFor(Market $market): array
    {
        $primary = self::primaryFor($market);
        $hidden = array_map(
            'strval',
            (array) config("giftcoves.connectors.amazon.hidden_locales.{$market->value}", []),
        );

        $rest = array_values(array_filter(
            self::cases(),
            fn (self $locale) => $locale !== $primary && ! in_array($locale->value, $hidden, true),
        ));

        return [$primary, ...$rest];
    }

    public function isHiddenIn(Market $market): bool
    {
        return ! in_array($this, self::selectableFor($market), true);
    }

    /**
     * Whether a price from this locale may enter the market's comparison.
     *
     * Only the primary. Everything else is a labelled extra — see the class
     * docblock for why a foreign price must never win "cheapest".
     */
    public function isComparableIn(Market $market): bool
    {
        return $this === self::primaryFor($market);
    }

    public function label(): string
    {
        return $this->value;
    }
}

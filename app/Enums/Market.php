<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Every market the site serves. Single source of truth.
 *
 * Deliberately called "market", not "locale": Laravel already has an app locale
 * for framework strings, and conflating the two is a footgun. `be-nl` and
 * `nl-nl` are both Dutch but they are different catalogues with different
 * merchants, prices, tax and delivery.
 *
 * Product identity is scoped to this value for exactly that reason — an offer
 * from one market is never a valid "cheapest price" for another.
 */
enum Market: string
{
    case BeNl = 'be-nl';
    case BeFr = 'be-fr';
    case En = 'en';
    case Es = 'es';
    case NlNl = 'nl-nl';

    public static function default(): self
    {
        return self::BeNl;
    }

    /**
     * Whether this market is offered to the public.
     *
     * A market is a promise of somewhere to buy, and `es` cannot keep it: Awin
     * reports no advertiser coverage for Spain, and bol does not operate there
     * either (see {@see self::bolCountry()}), so it has no supply at all rather
     * than a thin catalogue. Shipping it anyway would put an empty shop in the
     * switcher and five market sitemaps in front of a crawler, one of which
     * leads to nothing.
     *
     * Unpublished is *unadvertised*, not removed. `/es/` still routes, so the
     * copy bank, guides and Cove plans can be prepared before it opens and the
     * whole thing reverses by flipping this one arm. What it does not do is
     * appear in the switcher, the sitemap, the hreflang set, or language
     * negotiation.
     */
    public function isPublished(): bool
    {
        return match ($this) {
            self::Es => false,
            default => true,
        };
    }

    /**
     * Markets a visitor may be shown or sent to.
     *
     * Use this for anything public-facing. Admin and console keep {@see
     * self::cases()}: an editor still needs to build the market that has not
     * opened yet.
     *
     * @return list<self>
     */
    public static function published(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $market): bool => $market->isPublished(),
        ));
    }

    /** Short label for the market switcher. */
    public function label(): string
    {
        return match ($this) {
            self::BeNl => 'BE/NL',
            self::BeFr => 'BE/FR',
            self::En => 'EU/EN',
            self::Es => 'ES/ES',
            self::NlNl => 'NL/NL',
        };
    }

    /** Name in its own language, for the switcher menu. */
    public function nativeName(): string
    {
        return match ($this) {
            self::BeNl => 'België (Nederlands)',
            self::BeFr => 'Belgique (Français)',
            self::En => 'Europe (English)',
            self::Es => 'España (Español)',
            self::NlNl => 'Nederland (Nederlands)',
        };
    }

    /** ISO 639-1 — which translation file to load. */
    public function language(): string
    {
        return match ($this) {
            self::BeNl, self::NlNl => 'nl',
            self::BeFr => 'fr',
            self::En => 'en',
            self::Es => 'es',
        };
    }

    /** BCP 47 tag for hreflang, <html lang> and Intl formatting. */
    public function hrefLang(): string
    {
        return match ($this) {
            self::BeNl => 'nl-BE',
            self::BeFr => 'fr-BE',
            self::En => 'en',
            self::Es => 'es-ES',
            self::NlNl => 'nl-NL',
        };
    }

    public function currency(): string
    {
        return 'EUR';
    }

    /** bol.com country code. Null means bol has no catalogue for this market. */
    public function bolCountry(): ?string
    {
        return match ($this) {
            self::BeNl, self::BeFr, self::En => 'BE',
            self::NlNl => 'NL',
            // bol does not operate in Spain; this market is Awin-only for now.
            self::Es => null,
        };
    }

    /**
     * The bol Partner site id that earns on a click from this market.
     *
     * Follows the country, not the language: Belgium and the Netherlands are
     * separate partner accounts with separate ids, so `be-fr` and `be-nl` share
     * one and `nl-nl` has its own. A market bol does not serve has none.
     */
    public function bolPartnerSiteId(): ?string
    {
        $country = $this->bolCountry();

        if ($country === null) {
            return null;
        }

        $id = config("giftcoves.connectors.bol.partner_site_id.{$country}");

        return blank($id) ? null : (string) $id;
    }

    /**
     * Accept-Language sent to bol.
     *
     * bol has no English catalogue, so the English market receives Dutch product
     * names rather than no results at all.
     */
    public function bolAcceptLanguage(): ?string
    {
        return match ($this) {
            self::BeNl => 'nl-BE',
            self::BeFr => 'fr-BE',
            self::En => 'nl',
            self::NlNl => 'nl-NL',
            self::Es => null,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $m) => $m->value, self::cases());
    }

    /**
     * Best-effort market for an incoming Accept-Language header.
     *
     * Deliberately conservative: a wrong guess shows the wrong currency and the
     * wrong merchants, so anything unrecognised falls back to the default market
     * rather than being approximated.
     */
    public static function fromAcceptLanguage(?string $header): self
    {
        if ($header === null || trim($header) === '') {
            return self::default();
        }

        $tags = [];
        foreach (explode(',', $header) as $part) {
            $bits = explode(';', trim($part));
            $tag = strtolower(trim($bits[0] ?? ''));
            if ($tag === '') {
                continue;
            }
            $quality = 1.0;
            foreach (array_slice($bits, 1) as $param) {
                if (str_starts_with(trim($param), 'q=')) {
                    $quality = (float) substr(trim($param), 2);
                }
            }
            $tags[] = ['tag' => $tag, 'q' => $quality];
        }

        usort($tags, fn (array $a, array $b) => $b['q'] <=> $a['q']);

        // Resolve each tag fully — exact match, then language — before moving to
        // the next one. Doing all the exact matches first looks equivalent but
        // is not: "fr,en;q=0.5" would then match `en` exactly and return the
        // English market, ignoring that the visitor asked for French first.
        // Published only. Sending a Spanish speaker to a market with nothing in
        // it is a worse answer than the default, which at least has a catalogue.
        foreach ($tags as $t) {
            // Exact tag wins: nl-BE is a better answer than "some Dutch market".
            foreach (self::published() as $market) {
                if (strtolower($market->hrefLang()) === $t['tag']) {
                    return $market;
                }
            }

            $language = explode('-', $t['tag'])[0];
            foreach (self::published() as $market) {
                if ($market->language() === $language) {
                    return $market;
                }
            }
        }

        return self::default();
    }
}

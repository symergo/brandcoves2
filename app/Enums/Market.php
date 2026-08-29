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

    /**
     * Where a visitor lands when nothing tells us anything better.
     *
     * The international market, not Belgium. This is only ever reached after
     * `fromAcceptLanguage()` has failed to match a language at all — so the one
     * thing we know about this visitor is that they read neither Dutch, French
     * nor Spanish. Sending them to `be-nl` answers that by guessing Dutch,
     * which is the single worst guess available given what we just learned.
     *
     * It also changes what an unrecognised header costs: landing on `en` is a
     * language they can probably read, on the Belgian catalogue, one flag click
     * from anywhere else. Landing on `be-nl` is a page they cannot read at all.
     */
    public static function default(): self
    {
        return self::En;
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

    /** Name in its own language. */
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

    /**
     * The country or region whose catalogue this market sells from.
     *
     * The axis the switcher's flags are on. A market value already encodes this
     * — `be-nl` is Belgium in Dutch — but the two halves have to be separable
     * before a visitor can be offered one of them at a time, and `explode('-')`
     * would call the English market's country "en".
     *
     * `EU` is not a country and is not pretending to be one. The English market
     * has no single home: it exists so that somebody who reads neither Dutch nor
     * French can still use the site, and it buys from the Belgian catalogue
     * because that is where the supply is.
     */
    public function country(): string
    {
        return match ($this) {
            self::BeNl, self::BeFr => 'BE',
            self::NlNl => 'NL',
            self::En => 'INT',
            self::Es => 'ES',
        };
    }

    /**
     * Published countries, in the order the switcher shows them.
     *
     * Explicit rather than derived from `cases()`, because the order that
     * matters here is editorial: the home market first, then the neighbour, then
     * the catch-all — and EU last is the point, since it is where a visitor ends
     * up when neither of the real countries fits.
     *
     * @return list<string>
     */
    public static function countries(): array
    {
        $published = array_map(fn (self $m): string => $m->country(), self::published());

        return array_values(array_filter(
            ['BE', 'NL', 'INT', 'ES'],
            fn (string $country): bool => in_array($country, $published, true),
        ));
    }

    /**
     * This market's language, named in that language.
     *
     * Always in its own language, never translated: a visitor looking for their
     * language scans for the word they would write, and "Dutch" is invisible to
     * someone who only reads Dutch.
     */
    public function languageName(): string
    {
        return match ($this->language()) {
            'nl' => 'Nederlands',
            'fr' => 'Français',
            'es' => 'Español',
            default => 'English',
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

    /**
     * Every language this application speaks, each once.
     *
     * Five markets, four languages — `be-nl` and `nl-nl` share one. Anything
     * that has to be produced in *all* of them (a stored string translated
     * after the fact, a language-independent comparison) iterates this rather
     * than `cases()`, which would do the Dutch work twice.
     *
     * @return list<string>
     */
    public static function languages(): array
    {
        return array_values(array_unique(
            array_map(fn (self $m): string => $m->language(), self::cases()),
        ));
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

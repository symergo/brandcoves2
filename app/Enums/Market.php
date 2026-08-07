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
        foreach ($tags as $t) {
            // Exact tag wins: nl-BE is a better answer than "some Dutch market".
            foreach (self::cases() as $market) {
                if (strtolower($market->hrefLang()) === $t['tag']) {
                    return $market;
                }
            }

            $language = explode('-', $t['tag'])[0];
            foreach (self::cases() as $market) {
                if ($market->language() === $language) {
                    return $market;
                }
            }
        }

        return self::default();
    }
}

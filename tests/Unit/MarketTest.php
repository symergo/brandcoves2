<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\Market;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MarketTest extends TestCase
{
    #[Test]
    #[DataProvider('acceptLanguageCases')]
    public function it_negotiates_a_market(string $header, Market $expected): void
    {
        $this->assertSame($expected, Market::fromAcceptLanguage($header));
    }

    public static function acceptLanguageCases(): array
    {
        return [
            'exact tag wins' => ['nl-BE,nl;q=0.9', Market::BeNl],
            'exact tag, Dutch NL' => ['nl-NL,nl;q=0.9', Market::NlNl],
            'exact tag, French BE' => ['fr-BE,fr;q=0.8', Market::BeFr],
            'language-only French' => ['fr,en;q=0.5', Market::BeFr],
            // Spanish resolves to `es`, published since 2026-11-22 (it went to the
            // default while Spain had no supply).
            'language-only Spanish' => ['es', Market::Es],
            'English' => ['en-GB,en;q=0.9', Market::En],
            // Anything unrecognised falls back rather than being approximated —
            // a wrong guess shows the wrong currency and the wrong merchants.
            'unknown language' => ['ja-JP,ja;q=0.9', Market::default()],
            'empty' => ['', Market::default()],
        ];
    }

    #[Test]
    #[DataProvider('countryCases')]
    public function it_finds_the_market_of_a_country(?string $country, ?string $language, ?Market $expected): void
    {
        $this->assertSame($expected, Market::fromCountry($country, $language));
    }

    public static function countryCases(): array
    {
        return [
            // The case geolocation was added for: a Belgian whose browser says "Nederlands (Nederland)"
            'Belgium, Dutch browser set to the Netherlands' => ['BE', 'nl-NL,nl;q=0.9', Market::BeNl],
            'Belgium, French browser' => ['BE', 'fr-FR,fr;q=0.9,nl;q=0.5', Market::BeFr],
            'Belgium, French preferred by quality' => ['BE', 'nl;q=0.4,fr;q=0.9', Market::BeFr],
            'Belgium, a language we have no Belgian market in' => ['BE', 'de-DE,de;q=0.9', Market::BeNl],
            // Owner, 2026-11-15: "Belgium - english can go to be-nl". The shops are Belgian; not the English market
            'Belgium, English browser' => ['BE', 'en-GB,en;q=0.9', Market::BeNl],
            'Belgium, no language at all' => ['BE', null, Market::BeNl],
            'the Netherlands, whatever the browser says' => ['NL', 'fr-BE,fr;q=0.9', Market::NlNl],
            'lower case' => ['nl', null, Market::NlNl],
            // No market of its own: the caller falls back to the language
            'France' => ['FR', 'fr-FR', null],
            'the United States' => ['US', 'en-US', null],
            // Spain has a market, published since 2026-11-22
            'Spain' => ['ES', 'es-ES', Market::Es],
            'unknown to Cloudflare' => ['XX', 'nl-BE', null],
            'Tor' => ['T1', 'nl-BE', null],
            // The English market has no country; its internal code must not be selectable
            'the international code' => ['INT', 'en', null],
            'no header' => [null, 'nl-BE', null],
            'empty' => ['', 'nl-BE', null],
        ];
    }

    #[Test]
    public function quality_values_are_respected(): void
    {
        // The lower-q French tag must not beat the higher-q Dutch one.
        $this->assertSame(
            Market::NlNl,
            Market::fromAcceptLanguage('fr-BE;q=0.2,nl-NL;q=0.9'),
        );
    }

    #[Test]
    public function null_header_falls_back(): void
    {
        $this->assertSame(Market::default(), Market::fromAcceptLanguage(null));
    }

    #[Test]
    public function bol_does_not_serve_the_english_market(): void
    {
        /*
         * It did, on Belgium's catalogue, and that is exactly what went wrong:
         * bol has no English catalogue, so every stored `en` title came back in
         * Dutch. An English market it cannot describe in English is not one it
         * serves.
         *
         * Both arms are asserted because they must move together. Leaving a
         * language behind for a market with no country is how somebody later
         * reads this arm as evidence the market was supported and turns it back
         * on.
         */
        $this->assertNull(Market::En->bolCountry());
        $this->assertNull(Market::En->bolAcceptLanguage());

        // And with no country there is no site id, so nothing can quietly earn
        // on a market we are not asking bol about.
        $this->assertNull(Market::En->bolPartnerSiteId());
    }

    #[Test]
    public function bol_does_not_operate_in_spain(): void
    {
        // The Spanish market is Awin-only. Code that fans out to bol must treat
        // a null country as "skip", not as a default.
        $this->assertNull(Market::Es->bolCountry());
        $this->assertNull(Market::Es->bolAcceptLanguage());
    }

    #[Test]
    public function dutch_markets_share_a_language_but_stay_distinct(): void
    {
        $this->assertSame('nl', Market::BeNl->language());
        $this->assertSame('nl', Market::NlNl->language());

        // Same language, different market — this is the distinction the whole
        // routing layer exists to preserve.
        $this->assertNotSame(Market::BeNl->hrefLang(), Market::NlNl->hrefLang());
    }
}

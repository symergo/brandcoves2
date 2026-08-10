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
            // Spanish resolves to the default, not to `es`. That market is
            // unpublished — no Awin coverage for Spain and bol does not operate
            // there — so negotiating a visitor into it would land them on an
            // empty catalogue. The default at least has products.
            'language-only Spanish' => ['es', Market::default()],
            'English' => ['en-GB,en;q=0.9', Market::En],
            // Anything unrecognised falls back rather than being approximated —
            // a wrong guess shows the wrong currency and the wrong merchants.
            'unknown language' => ['ja-JP,ja;q=0.9', Market::default()],
            'empty' => ['', Market::default()],
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
    public function bol_has_no_english_catalogue_so_english_falls_back_to_dutch(): void
    {
        // Sending Accept-Language: en to bol returns nothing useful; Dutch
        // product names beat no results.
        $this->assertSame('nl', Market::En->bolAcceptLanguage());
        $this->assertSame('BE', Market::En->bolCountry());
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

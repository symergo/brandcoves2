<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Support\MarketSwitcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The country/language switcher.
 *
 * The properties worth holding are about reachability. A market missing from
 * this payload is a market no visitor can navigate to, and a payload pointing at
 * an unpublished one sends them to a shop with nothing in it — neither shows up
 * as an error anywhere.
 */
class MarketSwitcherTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_switcher_offers_every_published_market_once_and_nothing_else(): void
    {
        /*
         * A switcher entry is a promise that there is a shop on the other end.
         * `es` routes and has no supply at all — Awin reports no advertiser
         * coverage and bol does not operate there — so offering it would be a
         * link to an empty catalogue.
         *
         * Read the props rather than the HTML: the payload is JSON-encoded, so
         * "España" and "Français" arrive as \u escapes and a string match on
         * the document would pass for the wrong reason. The payload is grouped
         * by country (a flag, then its languages), so the markets are one
         * level down.
         */
        $markets = collect($this->get('/be-nl')->viewData('page')['props']['markets'])
            ->flatMap(fn (array $country): array => array_column($country['languages'], 'market'))
            ->all();

        $this->assertNotContains('es', $markets);
        $this->assertContains('be-fr', $markets);

        /*
         * Same set, and each exactly once — a market offered under two flags
         * would be a market whose catalogue depends on how you got to it, so
         * there is deliberately no array_unique here.
         *
         * Compared as strings: PHP cannot order enum cases, so sorting two
         * lists of them only happened to agree.
         */
        $expected = array_map(fn (Market $market): string => $market->value, Market::published());
        sort($expected);
        sort($markets);

        $this->assertSame($expected, $markets);
    }

    #[Test]
    public function english_is_always_one_click_away(): void
    {
        /*
         * English is a flag, not an option repeated under every country. The
         * visitor it exists for — someone who reads neither Dutch nor French —
         * is precisely the one who cannot read their way out of a Dutch menu to
         * find it, so what matters is that the country carrying it is always on
         * screen. Every flag is always rendered, so this is the whole condition.
         */
        $english = array_values(array_filter(
            app(MarketSwitcher::class)->payload(),
            fn (array $country): bool => in_array('en', array_column($country['languages'], 'language'), true),
        ));

        $this->assertCount(1, $english, 'English should live in exactly one country');
        $this->assertSame(Market::En->country(), $english[0]['country']);
        $this->assertSame('English', $english[0]['languages'][0]['name']);
    }

    #[Test]
    public function every_language_under_a_flag_is_served_by_that_country(): void
    {
        /*
         * What keeps the flags honest, and what makes an impossible pair
         * unaskable rather than merely rejected: a country offers its own
         * markets and no others, so nothing under the Dutch flag can quietly
         * move the visitor to a different catalogue.
         */
        foreach (app(MarketSwitcher::class)->payload() as $country) {
            foreach ($country['languages'] as $language) {
                $market = Market::from($language['market']);

                $this->assertSame(
                    $country['country'],
                    $market->country(),
                    "{$language['market']} is offered under {$country['country']} but does not belong to it",
                );
                $this->assertSame($market->language(), $language['language']);
            }
        }
    }

    #[Test]
    public function the_country_names_are_in_the_language_being_read(): void
    {
        // Country names are the half of the label that is a fact about our site
        // rather than a language naming itself, so they follow the reader.
        $this->get('/be-fr')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where(
                'markets.0.name',
                'Belgique',
            ));

        $this->get('/nl-nl')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where(
                'markets.0.name',
                'België',
            ));
    }

    #[Test]
    public function the_language_names_are_always_in_their_own_language(): void
    {
        // "Dutch" is invisible to somebody who only reads Dutch.
        $this->get('/be-fr')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('markets.0.languages.0.name', 'Nederlands'));
    }

    #[Test]
    public function every_language_carries_the_country_names(): void
    {
        foreach (['en', 'nl', 'fr', 'es'] as $language) {
            $countries = (require lang_path("{$language}/site.php"))['nav']['countries'] ?? [];

            foreach (['be', 'nl', 'int'] as $key) {
                $this->assertArrayHasKey($key, $countries, "{$language} is missing the {$key} country name");
                $this->assertNotSame('', trim((string) $countries[$key]));
            }
        }
    }
}

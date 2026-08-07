<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Support\CurrentMarket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every market must be served in its own language.
 *
 * The market decides the catalogue; its language decides the words. Getting
 * this wrong is not cosmetic — a French shopper served Dutch copy will leave,
 * and hreflang pointing at a page in the wrong language is an SEO liability.
 */
class LocalisationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function each_market_is_served_in_its_own_language(): void
    {
        $expected = [
            'be-nl' => 'Cadeauzoeker',
            'nl-nl' => 'Cadeauzoeker',
            'be-fr' => 'Trouver un cadeau',
            'es' => 'Buscador de regalos',
            'en' => 'Gift Finder',
        ];

        foreach ($expected as $market => $phrase) {
            $this->get("/{$market}")
                ->assertOk()
                ->assertSee($phrase, escape: false);
        }
    }

    #[Test]
    public function the_two_dutch_markets_share_copy_but_not_identity(): void
    {
        // be-nl and nl-nl are one language and two markets. Same words,
        // different hreflang — search engines need the distinction.
        $be = $this->get('/be-nl')->assertOk();
        $nl = $this->get('/nl-nl')->assertOk();

        $be->assertSee('Cadeauzoeker', escape: false);
        $nl->assertSee('Cadeauzoeker', escape: false);

        $be->assertSee('nl-BE', escape: false);
        $nl->assertSee('nl-NL', escape: false);
    }

    #[Test]
    public function no_english_leaks_into_a_translated_market(): void
    {
        // A missing key renders as the key itself, which is loud on purpose.
        // This catches a translation file that fell out of sync with the UI.
        foreach (['be-nl', 'be-fr', 'es'] as $market) {
            $response = $this->get("/{$market}")->assertOk();

            $response->assertDontSee('nav.', escape: false);
            $response->assertDontSee('home.', escape: false);
            $response->assertDontSee('footer.', escape: false);
        }
    }

    #[Test]
    public function every_language_defines_every_key(): void
    {
        // The English file is the reference. A market whose file is missing a
        // key would show that key to real users.
        $reference = $this->flatten(require lang_path('en/site.php'));

        foreach (['nl', 'fr', 'es'] as $language) {
            $translated = $this->flatten(require lang_path("{$language}/site.php"));

            $missing = array_diff(array_keys($reference), array_keys($translated));
            $this->assertSame([], array_values($missing), "{$language} is missing: ".implode(', ', $missing));

            $extra = array_diff(array_keys($translated), array_keys($reference));
            $this->assertSame([], array_values($extra), "{$language} has stale keys: ".implode(', ', $extra));
        }
    }

    #[Test]
    public function every_page_declares_its_alternates(): void
    {
        // Without hreflang the five market versions of a page compete with each
        // other, and the wrong language can rank in the wrong country.
        $response = $this->get('/be-nl')->assertOk();

        foreach (Market::cases() as $market) {
            $response->assertSee('hreflang="'.$market->hrefLang().'"', escape: false);
            $response->assertSee('/'.$market->value.'"', escape: false);
        }

        // For everyone we do not serve directly.
        $response->assertSee('hreflang="x-default"', escape: false);
    }

    #[Test]
    public function alternates_keep_the_rest_of_the_path(): void
    {
        // Only the market segment is swapped; /be-nl/guides/x must map to
        // /be-fr/guides/x, not back to the homepage.
        $this->assertSame(
            '/be-fr/guides/beste-koptelefoons',
            CurrentMarket::swapMarketInPath('be-nl/guides/beste-koptelefoons', Market::BeFr),
        );

        // An unprefixed path gains a market rather than losing its first segment.
        $this->assertSame('/es/health', CurrentMarket::swapMarketInPath('health', Market::Es));
        $this->assertSame('/en', CurrentMarket::swapMarketInPath('/', Market::En));
    }

    #[Test]
    public function every_market_resolves_to_a_language_file(): void
    {
        foreach (Market::cases() as $market) {
            $this->assertFileExists(
                lang_path($market->language().'/site.php'),
                "{$market->value} resolves to language \"{$market->language()}\", which has no site.php",
            );
        }
    }

    /** @return array<string, string> */
    private function flatten(array $items, string $prefix = ''): array
    {
        $flat = [];
        foreach ($items as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            if (is_array($value)) {
                $flat += $this->flatten($value, $path);
            } else {
                $flat[$path] = (string) $value;
            }
        }

        return $flat;
    }
}

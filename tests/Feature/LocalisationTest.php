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

    /**
     * Words that only exist in French or Spanish with a diacritic or an
     * apostrophe. Their bare forms are not spellings — they are typos.
     *
     * @var array<string, list<string>>
     */
    private const UNACCENTED = [
        'fr' => [
            'quelquun', 'cest', 'jai', 'dabord', 'damis', 'dautre', 'lavez', 'quon',
            'idees', 'deja', 'apres', 'etre', 'ecrirons', 'recu', 'prevenues',
            'repondez', 'decrivez', 'preparez', 'selection', 'apparaitront',
            'hesitez', 'gouts', 'sappelle', 'depenser', 'dinvitation', 'denviron',
            'lorganisateur', 'oeil', 'nont', 'laveugle', 'reponses', 'premiere',
            'privee', 'creer', 'partagee', 'theme', 'echangez',
        ],
        'es' => [
            'describela', 'todavia', 'intentalo', 'preseleccion', 'anadir', 'anade',
            'publica', 'copialo', 'pegalo', 'alli', 'gustaria', 'gustarian',
            'apareceran', 'preguntaselo', 'invitacion', 'enviaselo', 'unete',
            'perderan', 'recibira', 'diselo', 'puntuacion', 'ensena', 'echale',
            'cumpleanos', 'proxima', 'veran', 'hareis',
        ],
    ];

    #[Test]
    public function no_language_file_declares_a_top_level_key_twice(): void
    {
        /*
         * PHP takes the last value for a duplicate array key and says nothing.
         *
         * A second `'cove' => [...]` block, added months after the first,
         * replaced the Daily Cove subscription copy outright — so every
         * `cove.subscribe_*` lookup resolved to nothing, in all four languages,
         * English included, and the signup card rendered blank labels. Nothing
         * failed: the file parsed, the keys "existed", and only the eye caught it.
         */
        foreach (['en', 'nl', 'fr', 'es'] as $language) {
            $source = (string) file_get_contents(base_path("lang/{$language}/site.php"));

            preg_match_all("/^    '([a-z0-9_]+)' => \[$/m", $source, $matches);

            $counts = array_count_values($matches[1]);
            $duplicates = array_keys(array_filter($counts, fn (int $n) => $n > 1));

            $this->assertSame(
                [],
                $duplicates,
                "lang/{$language}/site.php declares these top-level keys more than once: "
                .implode(', ', $duplicates).'. The later block silently replaces the earlier one.',
            );
        }
    }

    #[Test]
    public function no_french_or_spanish_copy_ships_without_its_accents(): void
    {
        /*
         * A whole phase of gifting copy was written in plain ASCII — "Offrir a
         * quelquun", "Cest bon", "Cuanto los conoces?" — and shipped. Nothing
         * caught it, because every key existed and every placeholder resolved:
         * the strings were present, correct in structure, and wrong in the only
         * way a reader notices.
         *
         * A word list rather than a spell check, so it stays deterministic and
         * cannot start failing when a dictionary is updated.
         */
        foreach (self::UNACCENTED as $language => $words) {
            $lines = $this->flatten(require base_path("lang/{$language}/site.php"));

            foreach ($lines as $key => $value) {
                // Placeholders are not prose. `:theme` is a variable name, and
                // what it is called in French is nobody's business.
                $prose = (string) preg_replace('/:[a-z_]+/', ' ', $value);

                foreach ($words as $word) {
                    $this->assertDoesNotMatchRegularExpression(
                        '/\b'.preg_quote($word, '/').'\b/iu',
                        $prose,
                        "lang/{$language}/site.php: {$key} contains \"{$word}\" — it is missing an accent or an apostrophe.",
                    );
                }
            }
        }
    }

    #[Test]
    public function every_spanish_question_opens_with_an_inverted_mark(): void
    {
        // Spanish opens a question as well as closing it. A bare "?" at the end
        // reads as copy translated by somebody who does not speak it.
        foreach ($this->flatten(require base_path('lang/es/site.php')) as $key => $value) {
            if (! str_ends_with(rtrim($value), '?')) {
                continue;
            }

            $this->assertStringContainsString(
                '¿',
                $value,
                "lang/es/site.php: {$key} ends in '?' without an opening '¿'.",
            );
        }
    }

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

            $missing = array_diff(
                array_keys($reference),
                array_keys($translated),
                $this->optional(array_keys($reference)),
            );
            $this->assertSame([], array_values($missing), "{$language} is missing: ".implode(', ', $missing));

            $extra = array_diff(array_keys($translated), array_keys($reference));
            $this->assertSame([], array_values($extra), "{$language} has stale keys: ".implode(', ', $extra));
        }
    }

    /**
     * Keys a language is allowed to omit.
     *
     * The Cove calendar carries ~165 themes. A title is mandatory — without one
     * the day loses its theme entirely — but the one-line blurb under it is not,
     * and `Observance::blurb()` deliberately does NOT fall back to English: a
     * Dutch heading with an English sentence under it looks broken in a way a
     * missing sentence does not. So a blurb may be absent, and the editorial
     * pass fills it in when it runs.
     *
     * Scoped to that one shape on purpose. Everything else is still required,
     * because everything else is chrome that a reader sees on every page.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function optional(array $keys): array
    {
        return array_values(array_filter(
            $keys,
            fn (string $key) => (bool) preg_match('/^daily\.(observances|day_themes)\.[a-z0-9_]+\.blurb$/', $key),
        ));
    }

    #[Test]
    public function every_page_declares_its_alternates(): void
    {
        // Without hreflang the market versions of a page compete with each
        // other, and the wrong language can rank in the wrong country.
        //
        // Published markets only: declaring an unpublished one tells a crawler
        // there is an equivalent worth indexing, which is the opposite of
        // hiding it.
        $response = $this->get('/be-nl')->assertOk();

        foreach (Market::published() as $market) {
            $response->assertSee('hreflang="'.$market->hrefLang().'"', escape: false);
            $response->assertSee('/'.$market->value.'"', escape: false);
        }

        $hidden = array_filter(Market::cases(), fn (Market $m) => ! $m->isPublished());

        foreach ($hidden as $market) {
            $response->assertDontSee('hreflang="'.$market->hrefLang().'"', escape: false);
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

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The page that says what the search box accepts and how the camera works.
 *
 * Nothing here is cosmetic. The page is only useful if it renders in the
 * language of the market reading it, and it is only reachable if the two
 * surfaces with a search field on them still link to it — both of which are
 * silent failures. A missing translation key renders as the key itself, and a
 * removed link leaves a page that exists and that nobody can find.
 */
class SearchHelpPageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_renders_in_every_market(): void
    {
        foreach (Market::values() as $market) {
            $this->get("/{$market}/search-help")
                ->assertOk("/{$market}/search-help did not render")
                ->assertInertia(fn ($page) => $page
                    ->component('SearchHelp')
                    ->has('urls.search')
                    ->has('urls.scan')
                );
        }
    }

    #[Test]
    public function every_language_carries_every_key(): void
    {
        /*
         * The reason this page's copy lives in the language files rather than in
         * markdown on disk: it had to exist in all four the day it shipped.
         * Someone standing in a shop, mid-task, served English instructions for
         * a Dutch search box is a worse failure than the same substitution on a
         * privacy policy — and a key missing from one file renders as the bare
         * key, which no one notices in a language they do not read.
         */
        $reference = (require lang_path('en/site.php'))['search_help'] ?? [];

        $this->assertNotEmpty($reference, 'the English search_help block is missing');

        foreach (['nl', 'fr', 'es'] as $language) {
            $block = (require lang_path("{$language}/site.php"))['search_help'] ?? [];

            $this->assertSame(
                [],
                array_diff(array_keys($reference), array_keys($block)),
                "{$language} is missing search_help keys",
            );

            foreach ($block as $key => $value) {
                $this->assertNotSame(
                    $reference[$key] ?? null,
                    $value,
                    "{$language}.search_help.{$key} is still the English string",
                );
            }
        }
    }

    #[Test]
    public function the_search_page_and_the_footer_link_to_it(): void
    {
        /*
         * A source check, for the reason LegalPagesTest gives for the footer:
         * SSR does not run in the suite, so the links are not in the rendered
         * HTML and asserting on the page would pass for the wrong reason.
         *
         * These two are the whole of its discoverability: the search page
         * offers it where a search has just come back wrong, and the footer
         * carries it everywhere else. The homepage hero linked it too until
         * 2026-09-04 — see docs/features/search-help.md. It is deliberately not
         * in the header.
         */
        foreach (['js/Pages/Search.tsx', 'js/Layouts/SiteLayout.tsx'] as $page) {
            $this->assertStringContainsString(
                '/search-help',
                file_get_contents(resource_path($page)),
                "{$page} no longer links to the search help page",
            );
        }
    }

    #[Test]
    public function it_is_indexable_and_in_the_sitemap(): void
    {
        // "How do I scan a barcode to compare prices" is a real query, and
        // /search cannot answer it — it is empty until somebody types.
        $this->get('/be-nl/search-help')
            ->assertOk()
            ->assertDontSee('content="noindex, follow"', escape: false);

        $this->get('/sitemap/be-nl/1.xml')
            ->assertOk()
            ->assertSee('/be-nl/search-help', escape: false);
    }
}

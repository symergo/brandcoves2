<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * About, privacy and terms.
 *
 * The properties worth holding are legal rather than cosmetic: the operator's
 * details have to be present on every market, an unfilled one has to be visible
 * rather than absent, and the route must not let a URL segment reach the
 * filesystem.
 */
class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_document_renders_in_every_market(): void
    {
        foreach (Market::values() as $market) {
            foreach (['about', 'privacy', 'terms'] as $page) {
                $this->get("/{$market}/{$page}")->assertOk("/{$market}/{$page} did not render");
            }
        }
    }

    #[Test]
    public function the_enterprise_number_appears_on_all_three(): void
    {
        // Belgian law requires it, and it is the one identifier we actually
        // have — so it must never quietly go missing behind a placeholder.
        foreach (['about', 'privacy', 'terms'] as $page) {
            $this->get("/be-nl/{$page}")
                ->assertOk()
                ->assertSee(config('brandcoves.company.number'), escape: false);
        }
    }

    #[Test]
    public function a_missing_company_detail_is_visible_rather_than_absent(): void
    {
        /*
         * The whole reason placeholders render as a marker. An imprint that
         * silently drops the registered address is a compliance gap nobody
         * notices; one that says the address is missing gets fixed.
         */
        config(['brandcoves.company.address' => '']);

        // Asserted on the prop rather than the HTML: Inertia ships the page as
        // JSON in an attribute, where the em dash in the marker is escaped to
        // — and a string search for it silently never matches.
        $this->get('/be-nl/about')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where(
                'html',
                fn (string $html) => str_contains($html, 'address — to be completed'),
            ));
    }

    #[Test]
    public function a_filled_detail_replaces_the_marker(): void
    {
        config(['brandcoves.company.name' => 'Testbedrijf BV']);

        $this->get('/be-nl/about')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where(
                'html',
                fn (string $html) => str_contains($html, 'Testbedrijf BV')
                    && ! str_contains($html, 'name — to be completed'),
            ));
    }

    #[Test]
    public function an_untranslated_market_gets_english_and_is_told_so(): void
    {
        // A legal page silently served in the wrong language reads as an
        // oversight. Saying which text applies is the honest version.
        $this->get('/es/privacy')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('untranslated', true)
                ->where('title', 'Privacy policy')
            );

        $this->get('/be-nl/privacy')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('untranslated', false)
                ->where('title', 'Privacybeleid')
            );
    }

    #[Test]
    public function the_page_segment_cannot_reach_the_filesystem(): void
    {
        /*
         * `{page}` comes from a URL and is used to build a file path. Without the
         * allowlist, `../../.env` is a readable file. Rejected at the router, so
         * it never reaches the controller at all.
         */
        foreach (['../.env', '..%2F..%2F.env', 'nonsense', 'admin'] as $probe) {
            $this->get('/be-nl/'.$probe)->assertNotFound();
        }
    }

    #[Test]
    public function they_are_indexable_and_in_the_sitemap(): void
    {
        // An about page is a trust signal, and a privacy policy nobody can find
        // is a privacy policy nobody believes.
        $this->get('/be-nl/about')
            ->assertOk()
            ->assertDontSee('content="noindex, follow"', escape: false);

        $this->get('/sitemap/be-nl/1.xml')
            ->assertOk()
            ->assertSee('/be-nl/about', escape: false)
            ->assertSee('/be-nl/privacy', escape: false)
            ->assertSee('/be-nl/terms', escape: false);
    }

    #[Test]
    public function the_layout_links_to_all_three(): void
    {
        /*
         * A source check, deliberately.
         *
         * The imprint has to be reachable from every page and the footer is what
         * makes it so — but the footer is a React component and SSR does not run
         * in the test suite, so it is simply not in the HTML here. Asserting on
         * the rendered page would pass for the wrong reason on a layout that had
         * lost the links entirely.
         *
         * This catches the regression that actually matters: someone tidying the
         * footer and removing them.
         */
        $layout = file_get_contents(resource_path('js/Layouts/SiteLayout.tsx'));

        foreach (['about', 'privacy', 'terms'] as $page) {
            $this->assertStringContainsString("/\${market.key}/{$page}", $layout);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\PrunePersonalDataCommand;
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
    public function the_published_retention_windows_are_the_ones_the_code_enforces(): void
    {
        /*
         * GDPR Article 5(1)(e). A retention period published in a privacy notice
         * and not enforced anywhere is not a retention period, it is a sentence.
         * These two lists drifting apart makes the published policy false, which
         * is the kind of wrong that only surfaces during a complaint.
         */
        $windows = PrunePersonalDataCommand::RETENTION;

        foreach (['en' => 'privacy', 'nl' => 'privacy'] as $language => $page) {
            $text = file_get_contents(resource_path("legal/{$language}/{$page}.md"));

            $this->assertStringContainsString(
                $windows['events'].' days',
                $language === 'en' ? $text : str_replace(' dagen', ' days', $text),
                "the {$language} policy does not state the interaction-log window the pruner uses",
            );

            $this->assertStringContainsString(
                (string) $windows['unconfirmed_subscribers'],
                $text,
                "the {$language} policy does not state the unconfirmed-subscriber window",
            );
        }
    }

    #[Test]
    public function the_privacy_policy_names_a_legal_basis_for_every_category(): void
    {
        // Article 13(1)(c): a privacy notice has to state the basis, not just the
        // purpose. A table of purposes with no bases is the most common gap in a
        // notice that otherwise looks complete.
        $text = file_get_contents(resource_path('legal/en/privacy.md'));

        foreach (['Art. 6(1)(a)', 'Art. 6(1)(b)', 'Art. 6(1)(f)'] as $basis) {
            $this->assertStringContainsString($basis, $text);
        }

        // And the right to object, which only means anything where legitimate
        // interests are relied on.
        $this->assertStringContainsString('Art. 21', $text);
    }

    #[Test]
    public function the_terms_disclose_how_results_are_ranked(): void
    {
        /*
         * Required of a comparison service in the EU: the main parameters
         * determining ranking and their relative importance, plus whether anyone
         * pays for placement. Omnibus Directive (EU) 2019/2161.
         */
        foreach (['en', 'nl'] as $language) {
            $text = file_get_contents(resource_path("legal/{$language}/terms.md"));

            $this->assertMatchesRegularExpression(
                '/rank|gerangschikt/i',
                $text,
                "the {$language} terms do not explain ranking",
            );

            $this->assertMatchesRegularExpression(
                '/pays for placement|betaalt voor plaatsing/i',
                $text,
                "the {$language} terms do not address paid placement",
            );
        }
    }

    #[Test]
    public function no_page_links_the_discontinued_odr_platform(): void
    {
        // The European Commission's ODR platform stopped operating on 20 July
        // 2025. Plenty of sites still link it, and a dead dispute-resolution
        // route in a terms page is worse than none.
        foreach (glob(resource_path('legal/*/*.md')) as $path) {
            $this->assertStringNotContainsString(
                'ec.europa.eu/consumers/odr',
                file_get_contents($path),
                basename(dirname($path)).'/'.basename($path).' links the dead ODR platform',
            );
        }
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

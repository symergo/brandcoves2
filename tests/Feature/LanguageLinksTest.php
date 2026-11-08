<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every page links to itself in the other markets with plain links, and each
 * market's home page is in its sitemap (owner, 2026-11-08). The switcher is
 * buttons, so before this no crawlable link led from a page to its twin.
 */
class LanguageLinksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    #[Test]
    public function the_footer_links_are_the_hreflang_twins_without_the_current_market(): void
    {
        $response = $this->get('/be-nl')->assertOk();

        $expected = collect(Market::published())
            ->reject(fn (Market $m) => $m === Market::BeNl)
            ->map(fn (Market $m) => [
                'market' => $m->value,
                'name' => $m->nativeName(),
                'lang' => $m->hrefLang(),
                'href' => url("/{$m->value}"),
            ])
            ->values()
            ->all();

        $response->assertInertia(fn (AssertableInertia $page) => $page->where('languageLinks', $expected));

        // The same addresses the head declares, so the two cannot disagree.
        foreach ($expected as $link) {
            $response->assertSee('hreflang="'.$link['lang'].'" href="'.$link['href'].'"', false);
        }
    }

    #[Test]
    public function each_market_sitemap_submits_its_home_page_with_its_alternates(): void
    {
        $xml = $this->get('/sitemap/be-fr/1.xml')->assertOk()->getContent();

        $this->assertStringContainsString('<loc>'.url('/be-fr').'</loc>', $xml);
        $this->assertStringContainsString('hreflang="nl-BE" href="'.url('/be-nl').'"', $xml);
    }
}

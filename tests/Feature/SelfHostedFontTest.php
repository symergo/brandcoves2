<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Inter comes from our own build, not from a third-party stylesheet
 * (docs/features/speed.md, "Inter is served from our own origin").
 */
class SelfHostedFontTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_page_preloads_our_own_inter_and_asks_no_font_host(): void
    {
        if (app(Vite::class)->isRunningHot()) {
            $this->markTestSkipped('Vite is running hot; this asserts what the manifest build emits.');
        }

        $html = (string) $this->get('/be-nl')->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('#fonts\.(bunny\.net|googleapis\.com|gstatic\.com)#', $html);
        $this->assertMatchesRegularExpression(
            '#<link rel="preload" href="[^"]*/build/assets/inter-latin-400-normal-[^"]+\.woff2" as="font" type="font/woff2" crossorigin>#',
            $html,
        );
    }
}

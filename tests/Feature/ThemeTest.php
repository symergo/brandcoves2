<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The site is light, and dark is kept for a switch that is not built yet.
 *
 * Dark used to arrive from `prefers-color-scheme` alone, so roughly half of all
 * visitors saw a palette they had never asked this site for — and it changed
 * between two visits, because many phones move that setting on a schedule, for a
 * reason the visitor could not see and had no control over.
 *
 * Both properties below break silently. Reinstating the media query hands the
 * decision back to the OS and nothing fails; deleting the dormant `data-theme`
 * rule as dead code costs five palette values that have already been checked,
 * and nothing fails there either — until somebody re-derives them and dark mode
 * comes back as grey cards on a black page.
 */
class ThemeTest extends TestCase
{
    use RefreshDatabase;

    private function stylesheet(): string
    {
        return (string) file_get_contents(resource_path('css/app.css'));
    }

    #[Test]
    public function the_operating_system_does_not_choose_the_theme(): void
    {
        /*
         * The one rule that would quietly hand the decision back. It is an easy
         * thing to add — it looks like an accessibility improvement and it is
         * the default advice — so it is worth a test that says no.
         *
         * Matched as the at-rule rather than as the bare word, because the
         * comment above the dark tokens explains at some length why the query is
         * gone, and a substring search finds that explanation.
         */
        $this->assertDoesNotMatchRegularExpression(
            '/@media[^{]*prefers-color-scheme\s*:\s*dark/',
            $this->stylesheet(),
            'the OS preference is deciding the theme again',
        );
    }

    #[Test]
    public function the_dark_palette_is_kept_and_switched_on_by_nothing(): void
    {
        $css = $this->stylesheet();

        // Kept, because it is the expensive half and it is already right.
        $this->assertStringContainsString(":root[data-theme='dark']", $css);
        $this->assertStringContainsString('--color-cream: #17130f;', $css);

        /*
         * And light is never spelled out. Light is the absence of the attribute,
         * so a `[data-theme='light']` rule would be a second way of saying the
         * default — which is how two definitions of one thing drift apart.
         */
        $this->assertStringNotContainsString("data-theme='light'", $css);
    }

    #[Test]
    public function nothing_sets_the_attribute_yet(): void
    {
        // The switch is undecided. Until it exists, a page must render with no
        // theme attribute at all — anything else means a half-wired feature is
        // already deciding for visitors.
        $html = $this->get('/be-nl')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-theme', (string) $html);
    }

    #[Test]
    public function the_browser_chrome_is_a_colour_the_page_actually_paints(): void
    {
        // It was #12232B, a dark teal that appears nowhere in the palette, so
        // Android's toolbar and the iOS status bar were a colour the site does
        // not contain.
        $this->get('/be-nl')
            ->assertOk()
            ->assertSee('<meta name="theme-color" content="#f7f4ef">', escape: false);

        $this->assertStringContainsString('--color-cream: #f7f4ef;', $this->stylesheet());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The page that explains how lists work.
 *
 * The assertions worth having are about the screenshots rather than the prose:
 * the images are files on disk referenced by a computed path, which is the one
 * arrangement that can break silently. A missing translation shows up as a
 * visible key; a missing image shows up as a gap somebody has to notice.
 */
class ListHelpPageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_renders_for_a_visitor_with_no_account(): void
    {
        // No sign-in required. Somebody deciding whether this site is worth
        // making an account for is exactly who needs to read it.
        $this->get('/be-nl/lists-help')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Lists/Help'));
    }

    #[Test]
    public function each_market_is_shown_screenshots_in_its_own_language(): void
    {
        foreach (['be-nl' => 'nl', 'nl-nl' => 'nl', 'be-fr' => 'fr', 'en' => 'en'] as $market => $language) {
            $this->get("/{$market}/lists-help")
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('shots.find', "/help/lists/{$language}/1-find.png")
                    ->where('shots.choose', "/help/lists/{$language}/2-choose-list.png")
                    ->where('shots.lists', "/help/lists/{$language}/3-your-lists.png"));
        }
    }

    #[Test]
    public function spanish_falls_back_to_the_english_screenshots(): void
    {
        /*
         * Deliberate: `es` has no catalogue, so there is no product page in
         * that market to photograph. English images are wrong-language and a
         * step with no image at all is a page that looks broken — this is the
         * lesser of the two, and it is recorded rather than accidental.
         */
        $this->get('/es/lists-help')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('shots.find', '/help/lists/en/1-find.png'));
    }

    #[Test]
    public function every_screenshot_it_points_at_exists(): void
    {
        foreach (['nl', 'fr', 'en'] as $language) {
            foreach (['1-find', '2-choose-list', '3-your-lists'] as $shot) {
                $path = public_path("help/lists/{$language}/{$shot}.png");

                $this->assertFileExists(
                    $path,
                    "Missing {$language}/{$shot}. Re-take them: node scripts/help-screenshots.mjs",
                );
            }
        }
    }

    #[Test]
    public function the_lists_page_has_what_it_needs_to_link_here(): void
    {
        /*
         * The link is built in the browser from the market key, so it is not in
         * the server's HTML and there is no URL here to assert on. What *is*
         * assertable is the label: without it the anchor renders as the raw key
         * `lists_help.link`, which is the failure this catches.
         */
        $this->get('/be-nl/lists')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('translations.lists_help.link'));
    }

    #[Test]
    public function it_is_indexable(): void
    {
        /*
         * "How do I make a wish list" is a real query, and a noindex here would
         * make the page answer nobody who has not already found the site.
         *
         * The flag has to be turned on for the assertion: the suite runs with
         * `robots_allow` false so that a test environment can never be indexed,
         * which makes every page noindex regardless of what its controller
         * asked for.
         */
        config()->set('giftcoves.robots_allow', true);

        $this->get('/be-nl/lists-help')
            ->assertOk()
            ->assertSee('index, follow', escape: false);
    }
}

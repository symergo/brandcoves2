<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * One page for "how does this work" and "this is broken".
 *
 * The how-to pages were each reachable only from the screen they explain, so
 * somebody who had already given up on that screen had nowhere to go, and the
 * report form sat on a page of its own they had to know to look for.
 */
class HelpPageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_gathers_the_how_to_pages(): void
    {
        $this->get('/be-nl/help')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Help')
                ->where('guides.0.url', '/be-nl/search-help')
                ->where('guides.1.url', '/be-nl/lists-help'));
    }

    #[Test]
    public function every_page_it_offers_actually_exists(): void
    {
        // A help page listing a 404 is worse than one listing nothing: it sends
        // somebody already stuck to a dead end with our name on it.
        $urls = $this->get('/be-nl/help')->assertOk()->viewData('page')['props']['guides'];

        foreach ($urls as $guide) {
            $this->get($guide['url'])->assertOk();
        }
    }

    #[Test]
    public function it_carries_the_report_form(): void
    {
        /*
         * The form is `/feedback`'s own component, so this asserts the page
         * reaches the same endpoint rather than that a second form exists.
         * Two forms posting to one endpoint is how a honeypot ends up on one of
         * them and not the other.
         */
        $this->get('/be-nl/help')->assertOk();

        $this->post('/be-nl/feedback', [
            'message' => 'De prijs klopt niet meer.',
            'path' => '/be-nl/help',
        ])->assertRedirect();

        $this->assertDatabaseHas('feedback', ['message' => 'De prijs klopt niet meer.']);
    }

    #[Test]
    public function it_is_reachable_from_every_page(): void
    {
        // A source check: SSR does not run in the suite, so asserting on
        // rendered HTML would pass for the wrong reason.
        $this->assertStringContainsString(
            '/help',
            file_get_contents(resource_path('js/Layouts/SiteLayout.tsx')),
            'the footer no longer links to the help page',
        );
    }

    #[Test]
    public function it_is_indexable(): void
    {
        config()->set('giftcoves.robots_allow', true);

        $this->get('/be-nl/help')->assertOk()->assertSee('index, follow', escape: false);
    }
}

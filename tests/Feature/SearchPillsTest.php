<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Narrowing a search has to be reversible.
 *
 * A suggestion disappears once its word is in the query, which is right - it
 * would do nothing if clicked again - but it made narrowing a one-way door:
 * three clicks took "koptelefoon" to "koptelefoon Tune draadloos" and the only
 * way back was the browser's back button.
 */
class SearchPillsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function one_word_offers_nothing_to_remove(): void
    {
        // Nothing has been narrowed yet, so a row of removable pills would be
        // a control for undoing something the visitor has not done.
        $this->get('/be-nl/search?q=koptelefoon')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('activeTerms', []));
    }

    #[Test]
    public function each_narrowing_word_carries_the_query_without_it(): void
    {
        $this->get('/be-nl/search?q=koptelefoon+draadloos')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('activeTerms.0.term', 'koptelefoon')
                ->where('activeTerms.1.term', 'draadloos')
                // Removing one leaves the other, rather than clearing the search,
                // and the way off stays a readable address (search-urls.md):
                // this is the pill that broke the URL on 2026-09-12.
                ->where('activeTerms.0.url', '/be-nl/zoek/draadloos')
                ->where('activeTerms.1.url', '/be-nl/zoek/koptelefoon'));
    }

    #[Test]
    public function the_visitors_own_choices_survive_a_removal(): void
    {
        /*
         * The same rule the adding pills follow: filters, sort and view are the
         * visitor's, so taking a word off must not also put them back to the
         * default. Both directions go through `withTerm()` for that reason.
         */
        $this->get('/be-nl/search?q=koptelefoon+draadloos&sort=price_asc')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('activeTerms.0.url', fn (string $url) => str_contains($url, 'sort=price_asc')));
    }
}

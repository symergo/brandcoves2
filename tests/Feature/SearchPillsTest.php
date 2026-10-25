<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The green pills that showed the words narrowing a search, each removable,
 * went on 2026-10-25 (owner). The search box holds the words and is where
 * they are changed; the suggestion pills that add a word stay.
 */
class SearchPillsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_search_page_carries_no_active_term_pills(): void
    {
        $this->get('/be-nl/search?q='.urlencode('koptelefoon draadloos'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->missing('activeTerms'));
    }
}

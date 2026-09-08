<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Models\SearchLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the search log refuses to remember.
 *
 * The log is the demand signal behind the buying guides and the popular
 * searches page, and on 2026-09-08 five in six of its rows were crawler-minted
 * strings of product-title fragments. The rule that keeps them out is the
 * model's, so every caller gets it.
 */
class SearchLogTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_ordinary_query_is_logged(): void
    {
        SearchLog::record('draadloze koptelefoon', Market::BeNl, 12);

        $this->assertDatabaseHas('search_log', ['query' => 'draadloze koptelefoon', 'market' => 'be-nl']);
    }

    #[Test]
    public function a_six_word_query_is_still_a_query(): void
    {
        // "cadeau voor mijn moeder van 70" is what a person types.
        SearchLog::record('cadeau voor mijn moeder van 70', Market::BeNl, 40);

        $this->assertDatabaseCount('search_log', 1);
    }

    #[Test]
    public function a_query_over_sixty_characters_is_not_logged(): void
    {
        SearchLog::record(str_repeat('koptelefoon ', 6), Market::En, 3);

        $this->assertDatabaseCount('search_log', 0);
    }

    #[Test]
    public function a_query_of_more_than_six_words_is_not_logged(): void
    {
        // Under sixty characters, so only the word rule catches it.
        SearchLog::record('apple find bluetooth tracker ipx7 waterproof grey', Market::En, 9);

        $this->assertDatabaseCount('search_log', 0);
    }

    #[Test]
    public function the_search_page_does_not_log_a_long_query_either(): void
    {
        $this->get('/en/search?q='.urlencode('koptelefoon sound draadloze uur hoofdtelefoon hoofdtelefoons earpads'))
            ->assertOk();

        $this->assertDatabaseCount('search_log', 0);
    }
}

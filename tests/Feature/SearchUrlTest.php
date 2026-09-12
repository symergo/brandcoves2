<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Support\SearchUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A search has a readable address: /be-nl/zoek/draadloze-koptelefoon.
 *
 * The property this file holds is that there is exactly one canonical URL per
 * term per market, whichever door the request came through: the path, the
 * old ?q= form, a segment borrowed from another language, or a filtered
 * variant. See App\Support\SearchUrl for why ?q= is kept rather than redirected.
 */
class SearchUrlTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_helper_builds_a_path_for_a_clean_term_and_keeps_the_query_form_otherwise(): void
    {
        $this->assertSame('/be-nl/zoek/draadloze-koptelefoon', SearchUrl::for(Market::BeNl, '  Draadloze   Koptelefoon '));
        $this->assertSame('/nl-nl/zoek/philips', SearchUrl::for(Market::NlNl, 'Philips'));
        $this->assertSame('/be-fr/recherche/casque', SearchUrl::for(Market::BeFr, 'casque'));
        $this->assertSame('/en/search/headphones', SearchUrl::for(Market::En, 'headphones'));

        // Anything the slug could not turn back into the same term stays exact.
        $this->assertSame('/be-nl/search?q=iphone+6.1', SearchUrl::for(Market::BeNl, 'iphone 6.1'));
        $this->assertSame('/be-nl/search?q=caf%C3%A9', SearchUrl::for(Market::BeNl, 'café'));
        $this->assertSame('/be-nl/search?q=wh-1000xm5', SearchUrl::for(Market::BeNl, 'wh-1000xm5'));

        // Other parameters ride along in the query; a stray q is dropped.
        $this->assertSame('/be-nl/zoek/koptelefoon?max=50.00', SearchUrl::for(Market::BeNl, 'koptelefoon', ['q' => 'ignored', 'max' => '50.00']));
        $this->assertSame('/be-nl/search', SearchUrl::for(Market::BeNl, '   '));

        $this->assertSame('draadloze koptelefoon', SearchUrl::term('Draadloze-Koptelefoon'));
    }

    #[Test]
    public function a_term_in_the_path_is_the_search_and_its_own_canonical(): void
    {
        $this->get('/be-nl/zoek/draadloze-koptelefoon')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Search')->where('q', 'draadloze koptelefoon'))
            ->assertSee('rel="canonical" href="'.url('/be-nl/zoek/draadloze-koptelefoon').'"', escape: false);
    }

    #[Test]
    public function the_query_form_still_answers_and_names_the_path_as_canonical(): void
    {
        $this->get('/be-nl/search?q=draadloze+koptelefoon')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('q', 'draadloze koptelefoon'))
            ->assertSee('rel="canonical" href="'.url('/be-nl/zoek/draadloze-koptelefoon').'"', escape: false);
    }

    #[Test]
    public function a_term_that_cannot_be_a_path_keeps_the_query_form_as_canonical(): void
    {
        $this->get('/be-nl/search?q=iphone+6.1')
            ->assertOk()
            ->assertSee('rel="canonical" href="'.url('/be-nl/search').'?q=iphone+6.1"', escape: false);
    }

    #[Test]
    public function a_segment_from_another_language_serves_and_points_at_the_markets_own(): void
    {
        $this->get('/be-fr/zoek/casque')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('q', 'casque'))
            ->assertSee('rel="canonical" href="'.url('/be-fr/recherche/casque').'"', escape: false);
    }

    #[Test]
    public function a_filtered_path_search_is_indexable_and_canonicalises_to_the_bare_path(): void
    {
        // Indexing on, or the environment stamps noindex on every page.
        config(['giftcoves.robots_allow' => true]);

        $this->get('/be-nl/zoek/koptelefoon?sort=price_asc')
            ->assertOk()
            ->assertDontSee('noindex', false)
            ->assertSee('rel="canonical" href="'.url('/be-nl/zoek/koptelefoon').'"', escape: false);
    }

    #[Test]
    public function a_later_page_is_its_own_canonical(): void
    {
        config(['giftcoves.robots_allow' => true]);

        $this->get('/be-nl/zoek/koptelefoon?page=2')
            ->assertOk()
            ->assertDontSee('noindex', false)
            ->assertSee('rel="canonical" href="'.url('/be-nl/zoek/koptelefoon').'?page=2"', escape: false);
    }

    #[Test]
    public function robots_txt_no_longer_blocks_filtered_or_paginated_urls(): void
    {
        config(['giftcoves.robots_allow' => true]);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Disallow: /*/go/', false)
            ->assertDontSee('sort=', false)
            ->assertDontSee('page=', false)
            ->assertDontSee('brand', false);
    }

    #[Test]
    public function the_alternates_use_each_markets_own_word_for_search(): void
    {
        $this->get('/be-nl/zoek/koptelefoon')
            ->assertOk()
            ->assertSee(url('/be-fr/recherche/koptelefoon'), escape: false)
            ->assertSee(url('/en/search/koptelefoon'), escape: false)
            ->assertDontSee(url('/be-fr/zoek/koptelefoon'), escape: false);
    }

    #[Test]
    public function a_path_the_slug_rule_does_not_allow_is_not_a_search(): void
    {
        // Uppercase, dots and doubled hyphens are outside the route constraint,
        // so they are not silently normalised into a different search.
        $this->get('/be-nl/zoek/Iphone')->assertNotFound();
        $this->get('/be-nl/zoek/iphone--pro')->assertNotFound();
    }
}

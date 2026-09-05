<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Models\SearchLog;
use App\Services\Search\SearchTermStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The page that replaced the related-search chips.
 *
 * Two of these guard rules that fail silently and expensively. The minimum
 * volume is a privacy floor — a term below it can be one identifiable person,
 * and lowering it would publish what somebody typed into a gift site with no
 * error anywhere. The zero-result exclusion keeps the page from linking to
 * "nothing matched", which is a bad link on the one page whose whole job is
 * linking outward.
 */
class PopularSearchesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    private function log(string $query, int $count, int $results = 8, ?string $market = null, int $daysAgo = 0): void
    {
        $bucket = now()->subDays($daysAgo)->startOfHour();

        SearchLog::create([
            'query' => $query,
            // The bucket is part of the key, so the same term can be logged in
            // two windows without colliding — which is the whole setup trending
            // needs.
            'query_hash' => hash('sha256', $query.($market ?? Market::BeNl->value).$daysAgo),
            'market' => $market ?? Market::BeNl->value,
            'hour_bucket' => $bucket,
            'search_count' => $count,
            'result_count' => $results,
        ]);
    }

    #[Test]
    public function it_renders_in_every_market(): void
    {
        foreach (Market::values() as $market) {
            $this->get("/{$market}/popular-searches")
                ->assertOk("/{$market}/popular-searches did not render")
                ->assertInertia(fn ($page) => $page
                    ->component('PopularSearches')
                    ->has('months')
                    ->has('trending')
                    ->has('latest')
                    ->has('urls.search')
                );
        }
    }

    #[Test]
    public function it_ranks_by_volume_and_links_to_the_search(): void
    {
        $this->log('koptelefoon', 40);
        $this->log('koffiemachine', 90);

        $terms = app(SearchTermStats::class)->for(Market::BeNl)['months'][0]['terms'];

        $this->assertSame(['koffiemachine', 'koptelefoon'], array_column($terms, 'term'));
        $this->assertSame('/be-nl/search?q=koffiemachine', $terms[0]['url']);

        /*
         * Counts are ordering evidence, never payload. Rendering them was
         * dropped on 2026-09-05 and they left the props with it: shipping exact
         * search volumes in the page source while choosing not to print them
         * would publish the same numbers to anyone who looked.
         */
        $this->assertArrayNotHasKey('volume', $terms[0]);
    }

    #[Test]
    public function a_term_below_the_privacy_floor_is_never_listed(): void
    {
        config()->set('giftcoves.search.popular.min_volume', 5);

        $this->log('a name somebody typed once', 1);
        $this->log('koptelefoon', 40);

        $terms = array_column(app(SearchTermStats::class)->for(Market::BeNl)['months'][0]['terms'], 'term');

        $this->assertSame(['koptelefoon'], $terms, 'a one-person search reached a public page');
    }

    #[Test]
    public function a_search_that_found_nothing_is_not_offered_as_a_link(): void
    {
        $this->log('iets wat wij niet verkopen', 50, results: 0);
        $this->log('koptelefoon', 40);

        $terms = array_column(app(SearchTermStats::class)->for(Market::BeNl)['months'][0]['terms'], 'term');

        $this->assertSame(['koptelefoon'], $terms);
    }

    #[Test]
    public function one_market_never_shows_another_markets_searches(): void
    {
        $this->log('koptelefoon', 40, market: Market::BeNl->value);
        $this->log('casque audio', 40, market: Market::BeFr->value);

        $this->assertSame(
            ['koptelefoon'],
            array_column(app(SearchTermStats::class)->for(Market::BeNl)['months'][0]['terms'], 'term'),
        );
    }

    #[Test]
    public function a_market_with_no_history_is_a_page_rather_than_an_error(): void
    {
        $this->get('/be-nl/popular-searches')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('PopularSearches')
                ->where('trending', [])
                ->where('latest', [])
            );
    }

    #[Test]
    public function an_empty_page_is_not_offered_to_a_crawler(): void
    {
        /*
         * Thin pages spend crawl budget that belongs to products and guides —
         * the same rule the filtered search variants follow.
         *
         * `robots_allow` has to be turned on for this to be observable at all:
         * it is false everywhere but production, which renders every page
         * `noindex, nofollow` and would make this pass for the wrong reason.
         */
        config()->set('giftcoves.robots_allow', true);

        $this->get('/be-nl/popular-searches')
            ->assertOk()
            ->assertSee('noindex, follow', false);

        $this->log('koptelefoon', 40);
        Cache::flush();

        $this->get('/be-nl/popular-searches')
            ->assertOk()
            ->assertDontSee('noindex', false);
    }

    #[Test]
    public function trending_ranks_by_rate_rather_than_by_size(): void
    {
        /*
         * The steady term is searched far more in total and must still lose:
         * ranked by count this section would return the same rows as the
         * popular list and would be decoration.
         */
        $this->log('steady seller', 400, daysAgo: 40);
        $this->log('steady seller', 30, daysAgo: 1);

        $this->log('sinterklaas', 6, daysAgo: 40);
        $this->log('sinterklaas', 40, daysAgo: 1);

        $trending = app(SearchTermStats::class)->for(Market::BeNl)['trending'];

        $this->assertSame('sinterklaas', $trending[0]['term'], 'trending ranked by volume, not by rate');
        // The rate decided the order; it is not published with it.
        $this->assertArrayNotHasKey('lift', $trending[0]);
        $this->assertArrayNotHasKey('volume', $trending[0]);
    }

    #[Test]
    public function a_term_with_nothing_in_the_recent_window_is_not_trending(): void
    {
        $this->log('last winter', 300, daysAgo: 60);

        $terms = array_column(app(SearchTermStats::class)->for(Market::BeNl)['trending'], 'term');

        $this->assertNotContains('last winter', $terms, 'a dormant term was called trending');
    }

    #[Test]
    public function latest_is_recently_active_rather_than_a_live_feed(): void
    {
        /*
         * The floor is what makes this list publishable at all. Without it the
         * section is a feed of what individuals typed in the last few minutes,
         * and a one-off query can be about one identifiable person.
         */
        config()->set('giftcoves.search.popular.min_volume', 5);

        $this->log('someone typed this once', 1, daysAgo: 0);
        $this->log('koptelefoon', 40, daysAgo: 2);

        $terms = array_column(app(SearchTermStats::class)->for(Market::BeNl)['latest'], 'term');

        $this->assertSame(['koptelefoon'], $terms, 'a one-person search reached the latest list');
    }

    #[Test]
    public function latest_is_ordered_by_when_a_term_was_last_seen(): void
    {
        $this->log('older term', 20, daysAgo: 30);
        $this->log('newer term', 20, daysAgo: 1);

        $terms = array_column(app(SearchTermStats::class)->for(Market::BeNl)['latest'], 'term');

        $this->assertSame(['newer term', 'older term'], $terms);
    }

    #[Test]
    public function the_popular_list_is_capped(): void
    {
        // Twenty in three columns, not an export of the log — see
        // docs/features/popular-searches.md.
        config()->set('giftcoves.search.popular.limit', 20);

        foreach (range(1, 25) as $i) {
            $this->log("term {$i}", 10 + $i);
        }

        $popular = app(SearchTermStats::class)->for(Market::BeNl)['months'][0]['terms'];

        $this->assertCount(20, $popular);
        // Capped from the top, not the middle.
        $this->assertSame('term 25', $popular[0]['term']);
    }

    #[Test]
    public function movement_compares_this_window_against_the_one_before_it(): void
    {
        /*
         * `climber` was behind `faller` in the previous quarter and is ahead of
         * it now, so the pair must move in opposite directions. `newcomer` has
         * no history at all.
         */
        // Last week: faller ahead of climber. This week: the other way round.
        $this->log('faller', 100, daysAgo: 8);
        $this->log('climber', 20, daysAgo: 8);

        $this->log('faller', 30, daysAgo: 1);
        $this->log('climber', 90, daysAgo: 1);
        $this->log('newcomer', 60, daysAgo: 1);

        $popular = collect(app(SearchTermStats::class)->for(Market::BeNl)['months'][0]['terms'])
            ->keyBy('term');

        $this->assertSame('up', $popular['climber']['movement']);
        $this->assertSame('down', $popular['faller']['movement']);
        $this->assertSame('new', $popular['newcomer']['movement']);
    }

    #[Test]
    public function no_baseline_shows_no_arrows_rather_than_marking_everything_new(): void
    {
        /*
         * A log that does not reach back two windows has nothing to compare
         * against, and twenty rows all badged "new" would be a page of badges
         * saying nothing. Absence of a baseline is not evidence of novelty.
         */
        $this->log('koptelefoon', 40, daysAgo: 1);
        $this->log('koffiemachine', 30, daysAgo: 1);

        $popular = app(SearchTermStats::class)->for(Market::BeNl)['months'][0]['terms'];

        foreach ($popular as $row) {
            $this->assertNull($row['movement'], "{$row['term']} was given a direction with nothing to compare to");
        }
    }

    #[Test]
    public function it_is_one_ranked_column_per_period_newest_first(): void
    {
        config()->set('giftcoves.search.popular.columns', 3);

        $this->log('this period', 40, daysAgo: 1);
        $this->log('last period', 40, daysAgo: 8);

        $columns = app(SearchTermStats::class)->for(Market::BeNl)['months'];

        $this->assertCount(3, $columns);

        foreach ($columns as $column) {
            $this->assertNotSame('', $column['label'], 'a column shipped without a heading');
        }

        // Newest on the left, and each period ranked on its own rather than
        // sharing one list.
        $this->assertSame(['this period'], array_column($columns[0]['terms'], 'term'));
        $this->assertSame(['last period'], array_column($columns[1]['terms'], 'term'));
    }

    #[Test]
    public function the_footer_links_to_it(): void
    {
        /*
         * A source check, for the reason SearchHelpPageTest gives: SSR does not
         * run in the suite, so the footer's links are not in the rendered HTML
         * and asserting on the page would pass for the wrong reason.
         *
         * The footer is the whole of this page's discoverability — nothing else
         * links to it — so a removed link leaves a page that exists and that
         * nobody, crawler included, can reach.
         */
        $this->assertStringContainsString(
            '/popular-searches',
            file_get_contents(resource_path('js/Layouts/SiteLayout.tsx')),
            'the footer stopped linking to the popular searches page',
        );
    }

    /**
     * The crawler-minted vocabulary never reaches the page.
     *
     * Until the term chips were removed on 2026-09-05 they were crawlable links
     * that narrowed cumulatively, and `SearchLog::record()` wrote every term a
     * crawler minted on its way through. What that left behind is still in the
     * table and still passes the privacy floor: "pro" was logged 158 times in
     * nl-nl, "geschikt" 56, "liter" 44. All three were being published under
     * the heading "what people search for", each a followed link to an
     * indexable search URL.
     *
     * These are the exact terms production was showing.
     */
    #[Test]
    public function it_does_not_publish_terms_lifted_out_of_product_titles(): void
    {
        foreach (['pro', 'geschikt', 'liter', 'zilver', 'grijs', 'draadloze'] as $junk) {
            $this->log($junk, 40);
        }

        $terms = $this->publishedTerms();

        foreach (['pro', 'geschikt', 'liter', 'zilver', 'grijs', 'draadloze'] as $junk) {
            $this->assertNotContains($junk, $terms, "'{$junk}' is a modifier, not a search");
        }
    }

    /** "256gb" and "18v" are specifications. Nobody shops for a unit. */
    #[Test]
    public function it_does_not_publish_bare_measurements(): void
    {
        foreach (['256gb', '18v', '1.5l', '2000', '40mm'] as $spec) {
            $this->log($spec, 40);
        }

        $terms = $this->publishedTerms();

        foreach (['256gb', '18v', '1.5l', '2000', '40mm'] as $spec) {
            $this->assertNotContains($spec, $terms, "'{$spec}' is a measurement, not a search");
        }
    }

    /**
     * The filter has to leave the real terms alone, and the tempting shortcuts
     * would not: "ps5" and "ssd" are three characters, which rules out a length
     * floor on its own, and "bluetooth" is a modifier by grammar and a genuine
     * query by behaviour.
     */
    #[Test]
    public function it_still_publishes_the_terms_people_really_type(): void
    {
        foreach (['ps5', 'ssd', 'bluetooth', 'koptelefoon', 'robotstofzuiger', 'nintendo switch'] as $real) {
            $this->log($real, 40);
        }

        $terms = $this->publishedTerms();

        foreach (['ps5', 'ssd', 'bluetooth', 'koptelefoon', 'robotstofzuiger', 'nintendo switch'] as $real) {
            $this->assertContains($real, $terms, "'{$real}' is a real search and was filtered out");
        }
    }

    /**
     * Every list on the page, not only the ranked columns.
     *
     * `trending` and `latest` are built from separate queries, and a filter
     * applied to one of the three would leave the junk visible in the others —
     * which is exactly the shape of bug that put it there in the first place.
     *
     * @return list<string>
     */
    private function publishedTerms(): array
    {
        $lists = app(SearchTermStats::class)->for(Market::BeNl);

        $terms = [];

        foreach ($lists['months'] as $column) {
            foreach ($column['terms'] as $row) {
                $terms[] = $row['term'];
            }
        }

        foreach ([...$lists['trending'], ...$lists['latest']] as $row) {
            $terms[] = $row['term'];
        }

        return array_values(array_unique($terms));
    }
}

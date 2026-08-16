<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Models\CommunityQuestion;
use App\Models\Guide;
use App\Models\ProductGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Discover Cove hub, and the archive it now lists.
 *
 * The four cards are static. What is worth testing is everything underneath
 * them — the Coves, today's edition and the questions — because each reads a
 * table the rest of the site also reads, and the two ways any of them can be
 * wrong (showing something unpublished, or showing another market's) are both
 * invisible on a page that otherwise looks entirely correct.
 */
class DiscoverCoveHubTest extends TestCase
{
    use RefreshDatabase;

    private function guide(string $slug, string $title, Market $market, PublishStatus $status, string $publishedAt): Guide
    {
        return Guide::create([
            'market' => $market->value,
            'slug' => $slug,
            'title' => $title,
            'intro' => 'Waar het over gaat.',
            'status' => $status->value,
            'published_at' => $publishedAt,
        ]);
    }

    #[Test]
    public function it_lists_the_published_coves_for_this_market_newest_first(): void
    {
        $this->guide('oudste', 'De oudste', Market::BeNl, PublishStatus::Published, '2026-01-01');
        $this->guide('nieuwste', 'De nieuwste', Market::BeNl, PublishStatus::Published, '2026-08-01');

        $this->get('/be-nl/discover-cove')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('DiscoverCove')
                ->has('coves', 2)
                ->where('coves.0.title', 'De nieuwste')
                ->where('coves.1.title', 'De oudste')
                ->where('coves.0.url', '/be-nl/guides/nieuwste')
            );
    }

    #[Test]
    public function a_draft_is_not_listed(): void
    {
        // The hub is a public page. A Cove that is still being written is not
        // published anywhere else either, and this must not be the leak.
        $this->guide('concept', 'Nog niet klaar', Market::BeNl, PublishStatus::Draft, '2026-08-01');

        $this->get('/be-nl/discover-cove')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('coves', 0));
    }

    #[Test]
    public function another_markets_coves_are_not_listed(): void
    {
        /*
         * Coves are written per market, in that market's language and against
         * its catalogue. A Dutch Cove on the French hub is the market boundary
         * leaking — the same class of mistake as invariant #2, one page further
         * out.
         */
        $this->guide('nederlands', 'Een Nederlandse Cove', Market::BeNl, PublishStatus::Published, '2026-08-01');

        $this->get('/be-fr/discover-cove')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('coves', 0));

        $this->get('/be-nl/discover-cove')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('coves', 1));
    }

    #[Test]
    public function the_list_is_capped_so_the_hub_does_not_become_the_archive(): void
    {
        // Enough titles that the range is obvious, then a link to the whole
        // thing. A hub that lists everything is a second copy of /guides.
        for ($i = 1; $i <= 15; $i++) {
            $this->guide("cove-{$i}", "Cove {$i}", Market::BeNl, PublishStatus::Published, '2026-08-01');
        }

        $this->get('/be-nl/discover-cove')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('coves', 12));
    }

    // --- The questions band --------------------------------------------------

    #[Test]
    public function it_lists_published_questions_for_this_market(): void
    {
        $here = CommunityQuestion::factory()->published()->create(['title' => 'Iets voor mijn zus']);
        $elsewhere = CommunityQuestion::factory()->published()->inMarket(Market::NlNl)->create();
        $held = CommunityQuestion::factory()->create(['title' => 'Nog niet gelezen']);

        $titles = array_column(
            $this->get('/be-nl/discover-cove')->assertOk()->viewData('page')['props']['questions'],
            'title',
        );

        $this->assertContains($here->title, $titles);
        $this->assertNotContains($elsewhere->title, $titles);
        $this->assertNotContains($held->title, $titles);
    }

    #[Test]
    public function a_question_carries_its_own_answer_count_and_nothing_aggregate(): void
    {
        // Each question's count belongs to it and travels with it. A hub that
        // totals things is the catalogue-counter mistake in a new place.
        CommunityQuestion::factory()->published()->create();

        $props = $this->get('/be-nl/discover-cove')->assertOk()->viewData('page')['props'];

        $this->assertSame(0, $props['questions'][0]['answers']);
        $this->assertArrayNotHasKey('questionCount', $props);
        $this->assertArrayNotHasKey('totals', $props);
    }

    #[Test]
    public function the_bands_are_absent_rather_than_empty_on_a_quiet_market(): void
    {
        // An empty shelf is worse than no shelf: the page renders each band
        // only when there is something in it. This is the state a brand new
        // market is in until `bc:refresh-discovery` has run.
        $props = $this->get('/be-nl/discover-cove')->assertOk()->viewData('page')['props'];

        $this->assertSame([], $props['questions']);
        $this->assertSame([], $props['coves']);
        $this->assertSame([], $props['surprises']);
        $this->assertNull($props['today']);
    }

    #[Test]
    public function the_surprise_band_shows_scored_products_for_this_market(): void
    {
        /*
         * Surprise was the one card with nothing underneath it. It reads
         * `surprise_score`, which the scoring job writes after an ingest — an
         * unscored catalogue produces no band rather than a random one, which
         * is the distinction that makes the surface worth having.
         */
        $here = ProductGroup::factory()->create([
            'market' => Market::BeNl,
            'surprise_score' => 90,
            'in_stock' => true,
            'min_price' => 2500,
            'merchant_count' => 1,
        ]);

        ProductGroup::factory()->create([
            'market' => Market::NlNl,
            'surprise_score' => 99,
            'in_stock' => true,
            'min_price' => 2500,
            'merchant_count' => 1,
        ]);

        // Scored zero: ranked, and correctly not surprising.
        ProductGroup::factory()->create([
            'market' => Market::BeNl,
            'surprise_score' => 0,
            'in_stock' => true,
            'min_price' => 2500,
            'merchant_count' => 1,
        ]);

        $surprises = $this->get('/be-nl/discover-cove')->assertOk()->viewData('page')['props']['surprises'];

        $this->assertSame([$here->id], array_column($surprises, 'id'));
    }
}

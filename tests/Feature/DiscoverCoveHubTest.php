<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Models\Guide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Discover Cove hub, and the archive it now lists.
 *
 * The three cards were always static. What is worth testing is the band added
 * underneath them: it reads the same table the front page and `/guides` read,
 * and the two ways it can be wrong — showing a draft, or showing another
 * market's Coves — are both invisible on a page that otherwise looks correct.
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
}
